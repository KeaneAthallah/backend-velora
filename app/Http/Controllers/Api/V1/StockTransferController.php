<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class StockTransferController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);

        return $this->success(StockTransfer::where('business_id', $businessId)->with('items')->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);
        $data = $request->validate([
            'from_outlet_id' => [
                'required',
                'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId),
            ],
            'to_outlet_id' => [
                'required',
                'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId),
                'different:from_outlet_id',
            ],
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at'),
            ],
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
        ]);

        foreach ($data['items'] as $item) {
            if (empty($item['product_variant_id'])) {
                continue;
            }
            $variantMatches = ProductVariant::where('id', $item['product_variant_id'])
                ->where('product_id', $item['product_id'])
                ->exists();
            if (! $variantMatches) {
                return $this->error('Not found', 404);
            }
        }
        $transfer = DB::transaction(function () use ($businessId, $data, $request) {
            $t = StockTransfer::create([
                'business_id' => $businessId,
                'from_outlet_id' => $data['from_outlet_id'],
                'to_outlet_id' => $data['to_outlet_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            foreach ($data['items'] as $it) {
                StockTransferItem::create([
                    'stock_transfer_id' => $t->id,
                    'product_id' => $it['product_id'],
                    'product_variant_id' => $it['product_variant_id'] ?? null,
                    'quantity' => $it['quantity'],
                ]);
            }

            return $t;
        });

        return $this->success($transfer->load('items'), 'Created', 201);
    }

    public function show(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }

        return $this->success($stockTransfer->load('items'));
    }

    public function update(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        if ($stockTransfer->status !== 'draft') {
            return $this->error('Cannot edit non-draft transfer', 422);
        }
        $data = $request->validate(['status' => 'sometimes|in:draft,approved,cancelled', 'notes' => 'nullable|string']);
        $stockTransfer->update($data);

        return $this->success($stockTransfer);
    }

    public function destroy(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        if (! in_array($stockTransfer->status, ['draft', 'cancelled'])) {
            return $this->error('Cannot delete', 422);
        }
        $stockTransfer->delete();

        return $this->success(null, 'Deleted');
    }

    public function complete(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);

        try {
            DB::transaction(function () use ($stockTransfer, $request, $businessId) {
                // Lock the transfer so a double submit cannot move stock twice.
                $locked = StockTransfer::whereKey($stockTransfer->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($locked->status, ['approved', 'in_transit', 'draft'], true)) {
                    throw new RuntimeException('Invalid state');
                }

                $locked->load('items');

                // Defensive tenant check for transfers created before the
                // validation rules scoped their outlets and products.
                $outletIds = Outlet::where('business_id', $businessId)
                    ->whereIn('id', [$locked->from_outlet_id, $locked->to_outlet_id])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                if (count(array_unique($outletIds)) !== 2) {
                    throw new RuntimeException('Invalid transfer outlets');
                }

                foreach ($locked->items as $item) {
                    if (! Product::where('id', $item->product_id)->where('business_id', $businessId)->exists()) {
                        throw new RuntimeException('Invalid transfer product');
                    }

                    // decrement from
                    $balFrom = InventoryBalance::lockForUpdate()->firstOrCreate(
                        ['outlet_id' => $locked->from_outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                        ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                    );
                    $balFrom->quantity = bcsub((string) $balFrom->quantity, (string) $item->quantity, 4);
                    $balFrom->save();
                    InventoryMovement::create([
                        'business_id' => $businessId,
                        'outlet_id' => $locked->from_outlet_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'type' => 'transfer_out',
                        'quantity' => bcmul((string) $item->quantity, '-1', 4),
                        'balance_after' => $balFrom->quantity,
                        'reference_type' => 'stock_transfer',
                        'reference_id' => $locked->id,
                        'actor_id' => $request->user()->id,
                    ]);
                    // increment to
                    $balTo = InventoryBalance::lockForUpdate()->firstOrCreate(
                        ['outlet_id' => $locked->to_outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                        ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                    );
                    $balTo->quantity = bcadd((string) $balTo->quantity, (string) $item->quantity, 4);
                    $balTo->save();
                    InventoryMovement::create([
                        'business_id' => $businessId,
                        'outlet_id' => $locked->to_outlet_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'type' => 'transfer_in',
                        'quantity' => $item->quantity,
                        'balance_after' => $balTo->quantity,
                        'reference_type' => 'stock_transfer',
                        'reference_id' => $locked->id,
                        'actor_id' => $request->user()->id,
                    ]);
                }

                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();
            });
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'TRANSFER_ERROR');
        }

        return $this->success($stockTransfer->fresh()->load('items'));
    }
}

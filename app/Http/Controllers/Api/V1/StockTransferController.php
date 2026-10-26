<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'from_outlet_id' => 'required|integer|exists:outlets,id',
            'to_outlet_id' => 'required|integer|exists:outlets,id|different:from_outlet_id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
        ]);
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
        if ($stockTransfer->business_id !== $businessId) return $this->error('Not found', 404);
        return $this->success($stockTransfer->load('items'));
    }

    public function update(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) return $this->error('Not found', 404);
        if ($stockTransfer->status !== 'draft') return $this->error('Cannot edit non-draft transfer', 422);
        $data = $request->validate(['status' => 'sometimes|in:draft,approved,cancelled', 'notes' => 'nullable|string']);
        $stockTransfer->update($data);
        return $this->success($stockTransfer);
    }

    public function destroy(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) return $this->error('Not found', 404);
        if (! in_array($stockTransfer->status, ['draft', 'cancelled'])) return $this->error('Cannot delete', 422);
        $stockTransfer->delete();
        return $this->success(null, 'Deleted');
    }

    public function complete(Request $request, StockTransfer $stockTransfer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($stockTransfer->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);
        if (! in_array($stockTransfer->status, ['approved', 'in_transit', 'draft'])) return $this->error('Invalid state', 422);
        DB::transaction(function () use ($stockTransfer, $request) {
            foreach ($stockTransfer->items as $item) {
                // decrement from
                $balFrom = \App\Models\InventoryBalance::lockForUpdate()->firstOrCreate(
                    ['outlet_id' => $stockTransfer->from_outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                    ['business_id' => $stockTransfer->business_id, 'quantity' => 0, 'reserved_quantity' => 0]
                );
                $balFrom->quantity = bcsub((string) $balFrom->quantity, (string) $item->quantity, 4);
                $balFrom->save();
                \App\Models\InventoryMovement::create([
                    'business_id' => $stockTransfer->business_id,
                    'outlet_id' => $stockTransfer->from_outlet_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'transfer_out',
                    'quantity' => bcmul((string) $item->quantity, '-1', 4),
                    'balance_after' => $balFrom->quantity,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $stockTransfer->id,
                    'actor_id' => $request->user()->id,
                ]);
                // increment to
                $balTo = \App\Models\InventoryBalance::lockForUpdate()->firstOrCreate(
                    ['outlet_id' => $stockTransfer->to_outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                    ['business_id' => $stockTransfer->business_id, 'quantity' => 0, 'reserved_quantity' => 0]
                );
                $balTo->quantity = bcadd((string) $balTo->quantity, (string) $item->quantity, 4);
                $balTo->save();
                \App\Models\InventoryMovement::create([
                    'business_id' => $stockTransfer->business_id,
                    'outlet_id' => $stockTransfer->to_outlet_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'transfer_in',
                    'quantity' => $item->quantity,
                    'balance_after' => $balTo->quantity,
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $stockTransfer->id,
                    'actor_id' => $request->user()->id,
                ]);
            }
            $stockTransfer->status = 'completed';
            $stockTransfer->completed_at = now();
            $stockTransfer->save();
        });
        return $this->success($stockTransfer);
    }
}

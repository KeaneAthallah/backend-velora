<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class StocktakeController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);

        $query = Stocktake::where('business_id', $businessId)
            ->with('outlet', 'items')
            ->orderByDesc('id');

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        return $this->success($query->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);

        $data = $request->validate([
            'outlet_id' => [
                'required',
                'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId),
            ],
            'notes' => 'nullable|string',
        ]);

        $outletId = (int) $data['outlet_id'];

        $stocktake = DB::transaction(function () use ($businessId, $outletId, $data, $request) {
            $stocktake = Stocktake::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Snapshot every tracked product at the outlet, including the ones
            // with no balance row yet, so a count can record zero on hand.
            $balances = InventoryBalance::where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->whereNull('product_variant_id')
                ->pluck('quantity', 'product_id');

            Product::where('business_id', $businessId)
                ->where('track_inventory', true)
                ->orderBy('name')
                ->pluck('id')
                ->each(function ($productId) use ($stocktake, $balances) {
                    StocktakeItem::create([
                        'stocktake_id' => $stocktake->id,
                        'product_id' => $productId,
                        'product_variant_id' => null,
                        'expected_quantity' => $balances[$productId] ?? 0,
                        'counted_quantity' => 0,
                    ]);
                });

            return $stocktake;
        });

        return $this->success($stocktake->load('items.product', 'outlet'), 'Created', 201);
    }

    public function show(Request $request, Stocktake $stocktake)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $stocktake->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);

        return $this->success($stocktake->load('items.product', 'outlet'));
    }

    public function update(Request $request, Stocktake $stocktake)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $stocktake->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);

        if (! in_array($stocktake->status, ['draft', 'in_progress'], true)) {
            return $this->error('Stocktake is not open for counting', 422, [], 'STOCKTAKE_ERROR');
        }

        $data = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'sometimes|array',
            'items.*.id' => ['required', 'integer'],
            'items.*.counted_quantity' => 'required|numeric|min:0',
        ]);

        $itemIds = $stocktake->items()->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($data['items'] ?? [] as $row) {
            if (! in_array((int) $row['id'], $itemIds, true)) {
                return $this->error('Not found', 404);
            }
        }

        DB::transaction(function () use ($stocktake, $data) {
            if (array_key_exists('notes', $data)) {
                $stocktake->notes = $data['notes'];
            }
            foreach ($data['items'] ?? [] as $row) {
                StocktakeItem::where('id', $row['id'])
                    ->where('stocktake_id', $stocktake->id)
                    ->update(['counted_quantity' => $row['counted_quantity']]);
            }
            if ($stocktake->status === 'draft') {
                $stocktake->status = 'in_progress';
            }
            $stocktake->save();
        });

        return $this->success($stocktake->fresh()->load('items.product', 'outlet'));
    }

    /**
     * Post the count: recompute each expected quantity against live stock,
     * write the variance as an inventory adjustment, then close the count.
     */
    public function post(Request $request, Stocktake $stocktake)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $stocktake->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);

        try {
            DB::transaction(function () use ($stocktake, $businessId, $request) {
                $locked = Stocktake::whereKey($stocktake->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($locked->status, ['draft', 'in_progress'], true)) {
                    throw new RuntimeException('Stocktake is not open for counting');
                }
                if (! Outlet::where('id', $locked->outlet_id)->where('business_id', $businessId)->exists()) {
                    throw new RuntimeException('Invalid stocktake outlet');
                }

                $locked->load('items');

                foreach ($locked->items as $item) {
                    $balance = InventoryBalance::lockForUpdate()->firstOrCreate(
                        [
                            'outlet_id' => $locked->outlet_id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                        ],
                        ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                    );

                    $expected = (string) $balance->quantity;
                    $delta = bcsub((string) $item->counted_quantity, $expected, 4);

                    $item->expected_quantity = $expected;
                    if (bccomp($delta, '0', 4) !== 0) {
                        $balance->quantity = bcadd($expected, $delta, 4);
                        $balance->save();

                        InventoryMovement::create([
                            'business_id' => $businessId,
                            'outlet_id' => $locked->outlet_id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'type' => 'adjustment',
                            'quantity' => $delta,
                            'balance_after' => $balance->quantity,
                            'reason' => "Stocktake #{$locked->id}",
                            'reference_type' => 'stocktake',
                            'reference_id' => $locked->id,
                            'actor_id' => $request->user()->id,
                        ]);
                    }
                    $item->save();
                }

                $locked->status = 'completed';
                $locked->completed_at = now();
                $locked->save();
            });
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'STOCKTAKE_ERROR');
        }

        return $this->success($stocktake->fresh()->load('items.product', 'outlet'));
    }

    public function cancel(Request $request, Stocktake $stocktake)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $stocktake->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);

        if (! in_array($stocktake->status, ['draft', 'in_progress'], true)) {
            return $this->error('Stocktake is not open for counting', 422, [], 'STOCKTAKE_ERROR');
        }

        $stocktake->status = 'cancelled';
        $stocktake->save();

        return $this->success($stocktake->load('items.product', 'outlet'));
    }
}

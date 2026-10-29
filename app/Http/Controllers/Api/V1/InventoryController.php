<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function balances(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);
        $q = InventoryBalance::where('business_id', $businessId)->with('product', 'variant');
        if ($outletId) {
            $q->where('outlet_id', $outletId);
        }

        return $this->success($q->paginate(100));
    }

    public function adjust(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'inventory.adjust', $businessId);
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('business_id', $businessId)
                    ->whereNull('deleted_at'),
            ],
            'product_variant_id' => 'nullable|integer',
            'quantity' => 'required|numeric',
            'reason' => 'required|string|max:255',
            'type' => 'sometimes|in:adjustment,damaged,missing,expired,write_off',
        ]);

        // Never trust an outlet id taken from the body: it must belong to the
        // active business before we write a balance/movement against it.
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        if (! empty($data['product_variant_id'])) {
            $variantMatches = ProductVariant::where('id', $data['product_variant_id'])
                ->where('product_id', $data['product_id'])
                ->exists();
            if (! $variantMatches) {
                return $this->error('Not found', 404);
            }
        }

        $type = $data['type'] ?? 'adjustment';
        DB::transaction(function () use ($businessId, $outletId, $data, $type, $request) {
            $balance = InventoryBalance::lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outletId, 'product_id' => $data['product_id'], 'product_variant_id' => $data['product_variant_id'] ?? null],
                ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
            );
            $balance->quantity = bcadd((string) $balance->quantity, (string) $data['quantity'], 4);
            $balance->save();
            InventoryMovement::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'product_id' => $data['product_id'],
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'type' => $type,
                'quantity' => $data['quantity'],
                'balance_after' => $balance->quantity,
                'reason' => $data['reason'],
                'actor_id' => $request->user()->id,
            ]);
        });

        return $this->success(null, 'Adjusted');
    }

    public function movements(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'inventory.view', $businessId);
        $q = InventoryMovement::where('business_id', $businessId)->with('product');
        if ($outletId) {
            $q->where('outlet_id', $outletId);
        }
        if ($request->filled('product_id')) {
            $q->where('product_id', $request->input('product_id'));
        }

        return $this->success($q->orderByDesc('id')->paginate(100));
    }
}

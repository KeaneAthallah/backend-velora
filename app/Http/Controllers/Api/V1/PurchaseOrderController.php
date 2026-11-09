<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientMovement;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'purchasing.view', $businessId);
        $q = PurchaseOrder::where('business_id', $businessId)->with('items.product', 'items.ingredient', 'supplier', 'outlet');
        if ($request->filled('outlet_id')) {
            $q->where('outlet_id', $request->input('outlet_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('po_number', 'like', $term)
                    ->orWhereHas('supplier', function ($supplier) use ($term) {
                        $supplier->where('name', 'like', $term);
                    });
            });
        }
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        return $this->success($q->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'outlet_id' => 'nullable|integer',
            'po_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('purchase_orders', 'po_number')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'notes' => 'nullable|string',
            'expected_at' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('business_id', $businessId)),
            ],
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric',
        ]);
        if (! $outletId && ! empty($data['outlet_id'])) {
            $outletId = $data['outlet_id'];
        }
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! empty($data['outlet_id']) && (int) $data['outlet_id'] !== (int) $outletId) {
            throw ValidationException::withMessages(['outlet_id' => 'The outlet must match the active outlet context.']);
        }

        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->first();
        if (! $outlet) {
            throw ValidationException::withMessages(['outlet_id' => 'The selected outlet does not belong to this business.']);
        }

        $lines = collect($data['items']);
        $lineKeys = $lines->map(fn ($item) => $item['product_id'].':'.($item['product_variant_id'] ?? ''))->all();
        if (count($lineKeys) !== count(array_unique($lineKeys))) {
            throw ValidationException::withMessages(['items' => 'Each product and variant can only appear once per purchase order.']);
        }

        $productIds = $lines->pluck('product_id')->unique()->values();
        $productMap = Product::where('business_id', $businessId)->whereIn('id', $productIds)->get()->keyBy('id');
        if ($productMap->count() !== $productIds->count()) {
            throw ValidationException::withMessages(['items' => 'Every product must belong to this business.']);
        }

        $variantIds = $lines->pluck('product_variant_id')->filter()->unique()->values();
        $variantMap = $variantIds->isEmpty()
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->get()->keyBy('id');
        foreach ($lines as $index => $item) {
            if (empty($item['product_variant_id'])) {
                continue;
            }
            $variant = $variantMap->get($item['product_variant_id']);
            if (! $variant || (int) $variant->product_id !== (int) $item['product_id']) {
                throw ValidationException::withMessages([
                    "items.$index.product_variant_id" => 'The selected variant does not belong to the product.',
                ]);
            }
        }

        $poNumber = $data['po_number'] ?? ('PO-'.now()->format('Ymd').'-'.uniqid());
        $po = DB::transaction(function () use ($businessId, $outletId, $data, $poNumber, $request) {
            $subtotal = '0';
            $tax = '0';
            $total = '0';
            $po = PurchaseOrder::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'supplier_id' => $data['supplier_id'],
                'po_number' => $poNumber,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'expected_at' => $data['expected_at'] ?? null,
            ]);
            foreach ($data['items'] as $it) {
                $taxRate = (string) ($it['tax_rate'] ?? 0);
                $line = bcmul((string) $it['quantity'], (string) $it['unit_cost'], 4);
                $lineTax = bcmul($line, bcdiv($taxRate, '100', 6), 4);
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $it['product_id'],
                    'product_variant_id' => $it['product_variant_id'] ?? null,
                    'quantity' => $it['quantity'],
                    'unit_cost' => $it['unit_cost'],
                    'tax_rate' => $taxRate,
                    'line_total' => bcadd($line, $lineTax, 4),
                ]);
                $subtotal = bcadd($subtotal, $line, 4);
                $tax = bcadd($tax, $lineTax, 4);
            }
            $po->subtotal = $subtotal;
            $po->tax_amount = $tax;
            $po->total = bcadd($subtotal, $tax, 4);
            $po->save();

            return $po;
        });

        return $this->success($po->load('items'), 'Created', 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'purchasing.view', $businessId);

        return $this->success($purchaseOrder->load('items.product', 'items.ingredient', 'supplier', 'outlet'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        if ($purchaseOrder->status !== 'draft') {
            return $this->error('Only draft can be edited', 422);
        }
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate(['notes' => 'nullable|string', 'status' => 'sometimes|in:draft,cancelled']);
        $purchaseOrder->update($data);

        return $this->success($purchaseOrder);
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        if (! in_array($purchaseOrder->status, ['draft', 'cancelled'])) {
            return $this->error('Cannot delete', 422);
        }
        $purchaseOrder->delete();

        return $this->success(null, 'Deleted');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        if ($purchaseOrder->status !== 'draft' && $purchaseOrder->status !== 'submitted') {
            return $this->error('Invalid state', 422);
        }
        $purchaseOrder->status = 'approved';
        $purchaseOrder->approved_by = $request->user()->id;
        $purchaseOrder->approved_at = now();
        $purchaseOrder->save();

        return $this->success($purchaseOrder);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $businessId = $request->attributes->get('business_id');
        if ($purchaseOrder->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'purchasing.manage', $businessId);
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);
        if (! in_array($purchaseOrder->status, ['approved', 'partially_received'], true)) {
            return $this->error('Only approved or partially received purchase orders can be received', 422);
        }
        $outlet = Outlet::where('id', $purchaseOrder->outlet_id)->where('business_id', $businessId)->first();
        if (! $outlet) {
            return $this->error('Purchase order outlet not found in this business', 422);
        }
        $outletId = $outlet->id;
        $itemIds = collect($data['items'])->pluck('purchase_order_item_id');
        if ($itemIds->count() !== $itemIds->unique()->count()) {
            throw ValidationException::withMessages(['items' => 'Each purchase order line can only be received once per receipt.']);
        }

        DB::transaction(function () use ($purchaseOrder, $data, $businessId, $outletId, $itemIds, $request) {
            $po = PurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if (! in_array($po->status, ['approved', 'partially_received'], true)) {
                throw ValidationException::withMessages(['status' => 'Only approved or partially received purchase orders can be received.']);
            }
            $poItems = PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($poItems->count() !== $itemIds->count()) {
                throw ValidationException::withMessages(['items' => 'Every line must belong to this purchase order.']);
            }
            foreach ($data['items'] as $index => $it) {
                $poi = $poItems->get($it['purchase_order_item_id']);
                $remaining = bcsub((string) $poi->quantity, (string) $poi->received_quantity, 4);
                if (bccomp((string) $it['quantity'], $remaining, 4) === 1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Quantity exceeds the remaining quantity for this line.',
                    ]);
                }
                if ($poi->ingredient_id) {
                    // Copilot lines buy an ingredient into ingredient stock; the
                    // product on the line is only the catalogue link.
                    $ingredientBelongs = Ingredient::where('id', $poi->ingredient_id)
                        ->where('business_id', $businessId)
                        ->exists();
                    if (! $ingredientBelongs) {
                        throw ValidationException::withMessages([
                            "items.$index.purchase_order_item_id" => 'The line ingredient does not belong to this business.',
                        ]);
                    }

                    continue;
                }

                $productBelongs = Product::where('id', $poi->product_id)
                    ->where('business_id', $businessId)
                    ->exists();
                if (! $productBelongs) {
                    throw ValidationException::withMessages([
                        "items.$index.purchase_order_item_id" => 'The line product does not belong to this business.',
                    ]);
                }
            }

            $receipt = GoodsReceipt::create([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'receipt_number' => 'GR-'.now()->format('Ymd').'-'.uniqid(),
                'status' => 'completed',
                'received_by' => $request->user()->id,
                'received_at' => now(),
            ]);
            foreach ($data['items'] as $it) {
                $poi = $poItems->get($it['purchase_order_item_id']);
                $qty = (string) $it['quantity'];
                $unitCost = (string) ($it['unit_cost'] ?? $poi->unit_cost);
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'product_id' => $poi->product_id,
                    'product_variant_id' => $poi->product_variant_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                ]);
                $poi->received_quantity = bcadd((string) $poi->received_quantity, $qty, 4);
                $poi->save();

                // A copilot line restocks the ingredient, not the product: the
                // two inventories are separate, and product stock is only moved
                // by the product-based purchasing flow.
                if ($poi->ingredient_id) {
                    $ingredientBalance = IngredientBalance::lockForUpdate()->firstOrCreate(
                        ['outlet_id' => $outletId, 'ingredient_id' => $poi->ingredient_id],
                        ['business_id' => $businessId, 'quantity' => 0]
                    );
                    $ingredientBalance->quantity = bcadd((string) $ingredientBalance->quantity, $qty, 4);
                    $ingredientBalance->save();

                    IngredientMovement::create([
                        'business_id' => $businessId,
                        'outlet_id' => $outletId,
                        'ingredient_id' => $poi->ingredient_id,
                        'type' => 'receipt',
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                        'balance_after' => $ingredientBalance->quantity,
                        'reference_type' => 'goods_receipt',
                        'reference_id' => $receipt->id,
                        'reason' => 'Purchase order '.$po->po_number,
                        'actor_id' => $request->user()->id,
                    ]);

                    continue;
                }

                $bal = InventoryBalance::lockForUpdate()->firstOrCreate(
                    ['outlet_id' => $outletId, 'product_id' => $poi->product_id, 'product_variant_id' => $poi->product_variant_id],
                    ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                );
                $bal->quantity = bcadd((string) $bal->quantity, $qty, 4);
                $bal->save();
                InventoryMovement::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'product_id' => $poi->product_id,
                    'product_variant_id' => $poi->product_variant_id,
                    'type' => 'receipt',
                    'quantity' => $qty,
                    'balance_after' => $bal->quantity,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'actor_id' => $request->user()->id,
                ]);
            }

            $po->load('items');
            $totalQty = '0';
            $receivedQty = '0';
            foreach ($po->items as $line) {
                $totalQty = bcadd($totalQty, (string) $line->quantity, 4);
                $receivedQty = bcadd($receivedQty, (string) $line->received_quantity, 4);
            }
            $po->status = bccomp($receivedQty, $totalQty, 4) >= 0 ? 'completed' : 'partially_received';
            $po->save();
        });

        return $this->success($purchaseOrder->fresh()->load('items.product', 'items.ingredient'), 'Received');
    }
}

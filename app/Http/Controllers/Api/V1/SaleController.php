<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TaxRate;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);
        $q = Sale::where('business_id', $businessId)->with('items', 'payments');
        if ($request->filled('outlet_id')) $q->where('outlet_id', $request->input('outlet_id'));
        if ($request->filled('status')) $q->where('status', $request->input('status'));
        if ($request->filled('from')) $q->where('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $q->where('created_at', '<=', $request->input('to'));
        return $this->success($q->orderByDesc('id')->paginate(50));
    }

    public function show(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);
        return $this->success($sale->load('items', 'payments', 'refunds'));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if (! $outletId) return $this->error('Outlet required', 422);

        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'device_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer',
            'tax_rate_id' => 'nullable|integer',
            'idempotency_key' => 'nullable|string|max:128',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.unit_price_override' => 'nullable|numeric|min:0',
        ]);

        // idempotency check
        if (! empty($data['idempotency_key'])) {
            $existing = Sale::where('business_id', $businessId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->success($existing->load('items', 'payments'), 'Sale already exists (idempotent)');
            }
        }

        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->firstOrFail();
        $taxRate = null;
        if (! empty($data['tax_rate_id'])) {
            $taxRate = TaxRate::where('id', $data['tax_rate_id'])->where('business_id', $businessId)->first();
        } else {
            $taxRate = TaxRate::where('business_id', $businessId)->where('is_default', true)->first();
        }
        $taxRateValue = $taxRate ? (string) $taxRate->rate : '0';
        $taxType = $taxRate ? $taxRate->type : 'exclusive';

        try {
            $sale = DB::transaction(function () use ($businessId, $outletId, $outlet, $data, $taxRate, $taxRateValue, $taxType, $request) {
                // resolve prices and validate
                $subtotal = '0';
                $totalDiscount = '0';
                $totalTax = '0';
                $resolvedItems = [];
                foreach ($data['items'] as $it) {
                    $product = Product::where('id', $it['product_id'])->where('business_id', $businessId)->firstOrFail();
                    if (! $product->is_active) {
                        throw new \RuntimeException('Product inactive: ' . $product->id);
                    }
                    $variant = null;
                    if (! empty($it['product_variant_id'])) {
                        $variant = ProductVariant::where('id', $it['product_variant_id'])->where('product_id', $product->id)->firstOrFail();
                    }
                    // price resolution
                    $price = null;
                    $opp = \App\Models\OutletProductPrice::where('outlet_id', $outletId)
                        ->where('product_id', $product->id)
                        ->where('product_variant_id', $it['product_variant_id'] ?? null)
                        ->first();
                    if ($opp) {
                        $price = (string) $opp->selling_price;
                    } elseif ($variant && $variant->selling_price !== null) {
                        $price = (string) $variant->selling_price;
                    } else {
                        $price = (string) $product->selling_price;
                    }
                    if (isset($it['unit_price_override'])) {
                        // only allow override if permission? For safety, reject override unless manager
                        // We'll allow but could restrict; keep server authoritative by ignoring override
                    }
                    $qty = (string) $it['quantity'];
                    $lineSubtotal = bcmul($price, $qty, 4);
                    $lineDiscount = (string) ($it['discount_amount'] ?? 0);
                    $lineAfterDiscount = bcsub($lineSubtotal, $lineDiscount, 4);
                    // tax
                    $lineTax = '0';
                    if (bccomp($taxRateValue, '0', 4) !== 0) {
                        if ($taxType === 'inclusive') {
                            // tax included in price
                            $lineTax = bcsub($lineAfterDiscount, bcdiv($lineAfterDiscount, bcadd('1', bcdiv($taxRateValue, '100', 6), 6), 6), 4);
                        } else {
                            $lineTax = bcmul($lineAfterDiscount, bcdiv($taxRateValue, '100', 6), 4);
                        }
                    }
                    $lineTotal = $taxType === 'inclusive' ? $lineAfterDiscount : bcadd($lineAfterDiscount, $lineTax, 4);

                    $subtotal = bcadd($subtotal, $lineSubtotal, 4);
                    $totalDiscount = bcadd($totalDiscount, $lineDiscount, 4);
                    $totalTax = bcadd($totalTax, $lineTax, 4);

                    $resolvedItems[] = [
                        'product' => $product,
                        'variant' => $variant,
                        'product_id' => $product->id,
                        'product_variant_id' => $it['product_variant_id'] ?? null,
                        'product_name' => $product->name . ($variant ? ' - ' . $variant->name : ''),
                        'sku' => $variant ? $variant->sku : $product->sku,
                        'unit_price' => $price,
                        'cost_price' => $variant && $variant->cost_price !== null ? (string) $variant->cost_price : (string) $product->cost_price,
                        'quantity' => $qty,
                        'discount_amount' => $lineDiscount,
                        'tax_rate' => $taxRateValue,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineTotal,
                    ];

                    // inventory check/decrement
                    if ($product->track_inventory) {
                        $bal = InventoryBalance::lockForUpdate()->firstOrCreate(
                            ['outlet_id' => $outletId, 'product_id' => $product->id, 'product_variant_id' => $it['product_variant_id'] ?? null],
                            ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                        );
                        if (bccomp((string) $bal->quantity, $qty, 4) < 0) {
                            throw new \RuntimeException('Insufficient stock for product ' . $product->id);
                        }
                        $bal->quantity = bcsub((string) $bal->quantity, $qty, 4);
                        $bal->save();
                        // movement recorded after sale created; store for later
                    }
                }

                $total = $taxType === 'inclusive'
                    ? bcsub(bcadd($subtotal, '0', 4), $totalDiscount, 4) // subtotal includes tax
                    : bcadd(bcsub(bcadd($subtotal, '0', 4), $totalDiscount, 4), $totalTax, 4);
                // Actually recompute total cleanly
                $total = bcadd(bcsub($subtotal, $totalDiscount, 4), $taxType === 'inclusive' ? '0' : $totalTax, 4);
                if ($taxType === 'inclusive') {
                    $total = bcsub($subtotal, $totalDiscount, 4);
                }

                $sale = Sale::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'device_id' => $data['device_id'] ?? null,
                    'cashier_id' => $request->user()->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'shift_id' => $data['shift_id'] ?? null,
                    'receipt_number' => 'PENDING',
                    'status' => 'completed',
                    'subtotal' => $subtotal,
                    'discount_amount' => $totalDiscount,
                    'tax_amount' => $totalTax,
                    'total' => $total,
                    'paid_amount' => 0,
                    'change_amount' => 0,
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'completed_at' => now(),
                ]);

                foreach ($resolvedItems as $ri) {
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $ri['product_id'],
                        'product_variant_id' => $ri['product_variant_id'],
                        'product_name' => $ri['product_name'],
                        'sku' => $ri['sku'],
                        'unit_price' => $ri['unit_price'],
                        'cost_price' => $ri['cost_price'],
                        'quantity' => $ri['quantity'],
                        'discount_amount' => $ri['discount_amount'],
                        'tax_rate' => $ri['tax_rate'],
                        'tax_amount' => $ri['tax_amount'],
                        'line_total' => $ri['line_total'],
                    ]);

                    $product = $ri['product'];
                    if ($product->track_inventory) {
                        InventoryMovement::create([
                            'business_id' => $businessId,
                            'outlet_id' => $outletId,
                            'product_id' => $ri['product_id'],
                            'product_variant_id' => $ri['product_variant_id'],
                            'type' => 'sale',
                            'quantity' => bcmul((string) $ri['quantity'], '-1', 4),
                            'balance_after' => 0, // placeholder
                            'reference_type' => 'sale',
                            'reference_id' => $sale->id,
                            'reason' => 'Sale ' . $sale->id,
                            'actor_id' => $request->user()->id,
                        ]);
                        // fix balance_after
                        $bal = InventoryBalance::where('outlet_id', $outletId)->where('product_id', $ri['product_id'])->where('product_variant_id', $ri['product_variant_id'])->first();
                        $last = InventoryMovement::where('reference_type', 'sale')->where('reference_id', $sale->id)->where('product_id', $ri['product_id'])->orderByDesc('id')->first();
                        if ($last && $bal) {
                            $last->balance_after = $bal->quantity;
                            $last->save();
                        }
                    }
                }

                // receipt number
                $receiptNumber = $outlet->code . '-' . now()->format('Ymd') . '-' . str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT);
                $sale->receipt_number = $receiptNumber;
                $sale->save();

                return $sale;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'SALE_ERROR');
        }

        return $this->success($sale->load('items'), 'Sale completed', 201);
    }

    public function void(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.void', $businessId);
        if ($sale->status !== 'completed') return $this->error('Only completed sales can be voided', 422);
        $data = $request->validate(['reason' => 'required|string']);
        DB::transaction(function () use ($sale, $data, $request, $businessId) {
            // restore inventory
            foreach ($sale->items as $item) {
                $product = Product::find($item->product_id);
                if ($product && $product->track_inventory) {
                    $bal = InventoryBalance::lockForUpdate()->firstOrCreate(
                        ['outlet_id' => $sale->outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                        ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                    );
                    $bal->quantity = bcadd((string) $bal->quantity, (string) $item->quantity, 4);
                    $bal->save();
                    InventoryMovement::create([
                        'business_id' => $businessId,
                        'outlet_id' => $sale->outlet_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'type' => 'void',
                        'quantity' => $item->quantity,
                        'balance_after' => $bal->quantity,
                        'reference_type' => 'sale',
                        'reference_id' => $sale->id,
                        'reason' => $data['reason'],
                        'actor_id' => $request->user()->id,
                    ]);
                }
            }
            $sale->status = 'voided';
            $sale->voided_at = now();
            $sale->voided_by = $request->user()->id;
            $sale->void_reason = $data['reason'];
            $sale->save();
        });
        return $this->success($sale, 'Voided');
    }

    public function receipt(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);
        return $this->success($sale->load('items', 'payments', 'customer', 'outlet', 'cashier'));
    }
}

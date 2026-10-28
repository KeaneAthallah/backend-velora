<?php

namespace App\Services;

use App\Models\Business;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OrderCounter;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TaxRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaleService
{
    /** Prefix used in customer-facing order numbers. */
    private const ORDER_PREFIX = [
        'dine_in' => 'DI',
        'takeaway' => 'TA',
        'delivery' => 'DL',
        'pickup' => 'PU',
    ];

    public function __construct(
        protected MenuService $menuService,
        protected RecipeService $recipeService,
    ) {}

    /**
     * Price and validate order lines using server-side menu data.
     *
     * Nothing is written to the database here, so the same routine prices both
     * open orders and immediate sales.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, discount_amount: string, tax_amount: string, total: string, tax_rate: string, tax_type: string, tax_rate_id: int|null}
     */
    public function priceItems(array $items, int $businessId, int $outletId, ?int $taxRateId = null): array
    {
        $taxRate = null;
        if ($taxRateId) {
            $taxRate = TaxRate::where('id', $taxRateId)->where('business_id', $businessId)->first();
        }
        if (! $taxRate) {
            $taxRate = TaxRate::where('business_id', $businessId)->where('is_default', true)->first();
        }

        $taxRateValue = $taxRate ? (string) $taxRate->rate : '0';
        $taxType = $taxRate ? $taxRate->type : 'exclusive';

        $subtotal = '0';
        $totalDiscount = '0';
        $totalTax = '0';
        $resolvedItems = [];

        foreach ($items as $it) {
            $product = Product::where('id', $it['product_id'])->where('business_id', $businessId)->firstOrFail();
            if (! $product->is_active) {
                throw new \RuntimeException('Product inactive: '.$product->id);
            }

            $variant = null;
            if (! empty($it['product_variant_id'])) {
                $variant = ProductVariant::where('id', $it['product_variant_id'])->where('product_id', $product->id)->firstOrFail();
            }

            $availability = $this->menuService->productAvailability($product, $outletId, $variant);
            if (! $availability['available']) {
                throw new \RuntimeException("Unavailable: {$product->name} ({$availability['reason']})");
            }

            // Server-authoritative pricing: base price from outlet override / variant / product,
            // plus the sum of selected modifier price deltas.
            $basePrice = $this->menuService->basePrice($product, $variant, $outletId);
            $optionIds = array_values(array_unique(array_map('intval', $it['option_ids'] ?? [])));
            $selection = $this->menuService->resolveItem(
                $product,
                $variant,
                $optionIds,
                $businessId,
                $outletId,
            );
            $price = bcadd($basePrice, $selection['delta'], 4);

            $qty = (string) $it['quantity'];
            $lineSubtotal = bcmul($price, $qty, 4);
            $lineDiscount = (string) ($it['discount_amount'] ?? 0);
            $lineAfterDiscount = bcsub($lineSubtotal, $lineDiscount, 4);

            $lineTax = '0';
            if (bccomp($taxRateValue, '0', 4) !== 0) {
                if ($taxType === 'inclusive') {
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
                'product_name' => $product->name.($variant ? ' - '.$variant->name : ''),
                'sku' => $variant ? $variant->sku : $product->sku,
                'base_unit_price' => $basePrice,
                'unit_price' => $price,
                'modifiers' => $selection['modifiers'],
                'option_ids' => $optionIds,
                'cost_price' => $variant && $variant->cost_price !== null ? (string) $variant->cost_price : (string) $product->cost_price,
                'quantity' => $qty,
                'discount_amount' => $lineDiscount,
                'tax_rate' => $taxRateValue,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
                'notes' => $it['notes'] ?? null,
            ];
        }

        $total = bcadd(bcsub($subtotal, $totalDiscount, 4), $taxType === 'inclusive' ? '0' : $totalTax, 4);

        return [
            'items' => $resolvedItems,
            'subtotal' => $subtotal,
            'discount_amount' => $totalDiscount,
            'tax_amount' => $totalTax,
            'total' => $total,
            'tax_rate' => $taxRateValue,
            'tax_type' => $taxType,
            'tax_rate_id' => $taxRate?->id,
        ];
    }

    /**
     * Persist priced lines on a sale.
     *
     * @param  array<int, array<string, mixed>>  $resolved
     */
    public function attachItems(Sale $sale, array $resolved): void
    {
        foreach ($resolved as $ri) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $ri['product_id'],
                'product_variant_id' => $ri['product_variant_id'],
                'product_name' => $ri['product_name'],
                'sku' => $ri['sku'],
                'base_unit_price' => $ri['base_unit_price'],
                'unit_price' => $ri['unit_price'],
                'cost_price' => $ri['cost_price'],
                'quantity' => $ri['quantity'],
                'discount_amount' => $ri['discount_amount'],
                'tax_rate' => $ri['tax_rate'],
                'tax_amount' => $ri['tax_amount'],
                'line_total' => $ri['line_total'],
                'notes' => $ri['notes'],
                'modifiers' => $ri['modifiers'],
                'option_ids' => $ri['option_ids'],
            ]);
        }
    }

    /**
     * Replace every line on a sale with freshly priced lines.
     *
     * @param  array{items: array<int, array<string, mixed>>, subtotal: string, discount_amount: string, tax_amount: string, total: string}  $priced
     */
    public function replaceItems(Sale $sale, array $priced): void
    {
        $sale->items()->delete();
        $this->attachItems($sale, $priced['items']);

        $sale->subtotal = $priced['subtotal'];
        $sale->discount_amount = $priced['discount_amount'];
        $sale->tax_amount = $priced['tax_amount'];
        $sale->total = $priced['total'];
        $sale->save();

        $sale->unsetRelation('items');
    }

    /**
     * Deduct tracked product stock and recipe ingredients when a sale is finalised.
     */
    public function deductStock(Sale $sale, int $outletId, ?int $actorId = null): void
    {
        $sale->unsetRelation('items');
        $sale->load('items');

        foreach ($sale->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product || ! $product->track_inventory) {
                continue;
            }

            $balance = InventoryBalance::lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outletId, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                ['business_id' => $sale->business_id, 'quantity' => 0, 'reserved_quantity' => 0]
            );

            if (bccomp((string) $balance->quantity, (string) $item->quantity, 4) < 0) {
                throw new \RuntimeException('Insufficient stock for product '.$item->product_id);
            }

            $balance->quantity = bcsub((string) $balance->quantity, (string) $item->quantity, 4);
            $balance->save();

            InventoryMovement::create([
                'business_id' => $sale->business_id,
                'outlet_id' => $outletId,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'type' => 'sale',
                'quantity' => bcmul((string) $item->quantity, '-1', 4),
                'balance_after' => $balance->quantity,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'reason' => 'Sale '.$sale->id,
                'actor_id' => $actorId,
            ]);
        }

        $this->recipeService->consumeForSale($sale, $outletId, $actorId);
    }

    /**
     * Next order number for an outlet and order type, resetting each business day.
     */
    public function nextOrderNumber(Outlet $outlet, string $orderType): string
    {
        $outlet->loadMissing('business');
        $timezone = $outlet->timezone
            ?? $outlet->business?->timezone
            ?? Business::find($outlet->business_id)?->timezone
            ?? config('app.timezone');

        $now = Carbon::now($timezone);
        $businessDate = $now->format('Y-m-d');

        return DB::transaction(function () use ($outlet, $orderType, $businessDate, $now) {
            $counter = OrderCounter::lockForUpdate()->firstOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'business_date' => $businessDate,
                    'order_type' => $orderType,
                ],
                ['business_id' => $outlet->business_id, 'last_number' => 0]
            );

            $counter->last_number = (int) $counter->last_number + 1;
            $counter->save();

            $prefix = self::ORDER_PREFIX[$orderType] ?? 'OR';
            $outletCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($outlet->code ?: 'O'.$outlet->id)), 0, 8));

            return sprintf('%s-%s-%s-%03d', $outletCode, $prefix, $now->format('Ymd'), $counter->last_number);
        });
    }

    public function receiptNumber(Sale $sale, Outlet $outlet): string
    {
        return $outlet->code.'-'.now()->format('Ymd').'-'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT);
    }
}

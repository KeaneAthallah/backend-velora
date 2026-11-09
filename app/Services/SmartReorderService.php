<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\IngredientPhotoAndUsePattern;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemApproval;
use App\Models\PurchaseOrderItemSuggestedSupplierParcel;
use App\Models\RecipeIngredient;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartReorderService
{
    public function __construct(
        private readonly AuthorizationService $auth,
    ) {}

    public function forecastForBusiness(
        int $businessId,
        array $params,
    ): array {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        $startOfLookback = Carbon::parse($params['from'] ?? '14 days ago')->startOfDay();
        $until = Carbon::parse($params['until'] ?? 'now')->endOfDay();
        $defaultLeadDays = max(1, (int) ($params['defaultLeadDays'] ?? 7));
        $forecastAt = Carbon::parse($params['forecastAt'] ?? 'now')->startOfDay();
        $minCoverageDays = max(1, (int) ($params['minCoverageDays'] ?? $defaultLeadDays));
        $models = $params['models'] ?? ['demand' => true, 'shelfLife' => true, 'leadTime' => true];

        $ingredients = Ingredient::forBusiness($businessId)
            ->trackingInventory()
            ->with(['balances', 'recipes.recipe.product'])
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'ingredient' => $ingredient,
                'openingBalance' => $ingredient->openingQuantity(),
                'currentStock' => $ingredient->current_stock,
                'reorderLevel' => (float) $ingredient->reorder_level,
                'trackInventory' => $ingredient->track_inventory,
                'shelfLifeDays' => (float) ($ingredient->shelf_life_days ?? Ingredient::SHELF_LIFE_UNTRACKED),
                'photoAndUsePatterns' => $ingredient->photoAndUsePatterns,
                'recipes' => $ingredient->recipes->map(fn (RecipeIngredient $relation) => [
                    'recipe' => $relation->recipe,
                    'quantityInRecipe' => (float) $relation->quantity_per_unit,
                    'unit' => $relation->unit,
                ]),
            ]);

        $sales = Product::forBusiness($businessId)
            ->published()
            ->with('category')
            ->get()
            ->keyBy('id');

        $salesQuantitiesByProduct = $this->salesQuantitiesByProduct(
            $businessId,
            $sales->pluck('id'),
            $startOfLookback,
            $until,
        );

        $consumptionsByIngredient = $this->consumptionFromRecipes(
            $ingredients,
            $salesQuantitiesByProduct,
            $sales,
        );

        $forecastBlocks = $ingredients->map(fn (array $data): array => [
            'ingredient' => $data['ingredient'],
            'openingBalance' => $data['openingBalance'],
            'currentStock' => $data['currentStock'],
            'reorderLevel' => $data['reorderLevel'],
            'trackInventory' => $data['trackInventory'],
            'shelfLifeDays' => $data['shelfLifeDays'],
            'photoAndUsePatterns' => $data['photoAndUsePatterns'],
            'recipes' => $data['recipes'],
            'consumption' => $consumptionsByIngredient->get($data['ingredient']->id, []),
            'forecast' => $this->forecastForIngredient(
                $data,
                $consumptionsByIngredient->get($data['ingredient']->id, []),
                $salesQuantitiesByProduct,
                $sales,
                $forecastAt,
                $minCoverageDays,
                $models,
            ),
        ]);

        // One read, grouped twice: product lines draft purchase orders, while
        // ingredient parcels are what a forecast breakdown can name.
        $parcels = PurchaseOrderItemSuggestedSupplierParcel::forBusiness($businessId)
            ->with('supplier')
            ->get();
        $suggestedParcelsByProduct = $this->groupParcels($parcels, 'product_id');
        $suggestedParcelsByIngredient = $this->groupParcels($parcels, 'ingredient_id');

        $purchaseMoneyBudget = $this->purchaseMoneyBudget($forecastBlocks, $businessId);

        return [
            'forecastAt' => $forecastAt->toIso8601String(),
            'lookback' => [
                'from' => $startOfLookback->toIso8601String(),
                'until' => $until->toIso8601String(),
                'days' => $startOfLookback->diffInDays($until),
            ],
            'settings' => [
                'defaultLeadDays' => $defaultLeadDays,
                'minCoverageDays' => $minCoverageDays,
                'models' => $models,
            ],
            'ingredients' => $forecastBlocks->map(function (array $block) use ($suggestedParcelsByIngredient, $minCoverageDays): array {
                $forecast = $this->toForecastDto($block['forecast']);

                return [
                    'id' => (string) $block['ingredient']->id,
                    'name' => $block['ingredient']->name,
                    'sku' => $block['ingredient']->sku,
                    'unit' => $block['ingredient']->unit,
                    'costPerUnit' => (float) $block['ingredient']->cost_per_unit,
                    'currentStock' => $block['currentStock'],
                    'reorderLevel' => $block['reorderLevel'],
                    'shelfLifeDays' => $block['shelfLifeDays'],
                    'isTracked' => $block['trackInventory'],
                    'photoPattern' => $this->photoPattern($block['photoAndUsePatterns']),
                    // Supplier parcels refine the lead time only once a parcel is
                    // chosen: take the ingredient's own lead time here, then fall
                    // back to the 7-day default.
                    'leadTimeDays' => $this->effectiveLeadTimeDays($block['ingredient'], []),
                    'suggestedParcels' => $this->withParcelPreviews(
                        $suggestedParcelsByIngredient[(int) $block['ingredient']->id] ?? [],
                        $block,
                        $forecast,
                        $minCoverageDays,
                    ),
                    'forecast' => $forecast,
                    'consumption' => $this->toConsumptionDto($block['consumption']),
                ];
            })->values(),
            'suggestedParcelsByProduct' => $suggestedParcelsByProduct,
            'purchaseMoneyBudget' => $purchaseMoneyBudget,
        ];
    }

    public function suggestPurchaseOrder(
        int $businessId,
        array $params,
    ): array {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        $forecast = $this->forecastForBusiness($businessId, [
            'from' => $params['from'] ?? '14 days ago',
            'until' => $params['until'] ?? 'now',
            'defaultLeadDays' => $params['defaultLeadDays'] ?? 7,
            'minCoverageDays' => $params['minCoverageDays'] ?? null,
            'forecastAt' => $params['forecastAt'] ?? 'now',
            'models' => $params['models'] ?? ['demand' => true, 'shelfLife' => true, 'leadTime' => true],
        ]);

        $requestedSupplierId = (int) ($params['supplierId'] ?? 0);
        // A cap is opt-in: whole parcels routinely cost more than the forecast's
        // own per-unit estimate, which would otherwise exclude every line.
        $budget = (float) ($params['budget'] ?? 0);
        $budgetOkay = $budget <= 0 ? 'unbounded' : 'capped';
        $preferCurrentStockSupplier = (bool) ($params['preferCurrentStockSupplier'] ?? false);

        $selections = $this->selectReorderItems(
            $forecast,
            $requestedSupplierId,
            $budget,
            $budgetOkay,
            $preferCurrentStockSupplier,
            $forecast['suggestedParcelsByProduct'],
        );

        if ($selections === []) {
            throw ValidationException::withMessages([
                'items' => 'No ingredient needs reordering with the current stock and budget.',
            ]);
        }

        $outletId = $this->resolveOutletId($businessId, (int) ($params['outletId'] ?? 0));
        $supplierId = $this->resolveSupplierId($businessId, $requestedSupplierId, $selections);

        $purchaseOrder = DB::transaction(function () use ($businessId, $outletId, $supplierId, $selections) {
            $order = PurchaseOrder::create([
                'business_id' => $businessId,
                'supplier_id' => $supplierId,
                'outlet_id' => $outletId,
                'po_number' => $this->nextPurchaseOrderNumber($businessId),
                'status' => 'draft',
                'notes' => 'Drafted by the reorder copilot.',
                'expected_at' => Carbon::now()->addDays($this->effectiveLeadTimeDaysForBusiness($businessId, $selections))->toDateString(),
                'created_by' => $this->auth->currentUser()?->id,
            ]);

            $total = 0.0;

            foreach ($selections as $selection) {
                $ingredient = Ingredient::forBusiness($businessId)->findOrFail((int) $selection['ingredientId']);
                $quantity = (string) $selection['reorderQuantity'];
                $parcelQuantity = (float) ($selection['suggestedParcelQuantity'] ?? 0);
                $parcelCost = (float) ($selection['suggestedParcelCost'] ?? 0);
                // A whole parcel is one purchase unit: its cost divided by its
                // size is the honest per-unit price, and rounding to whole
                // parcels is already baked into the selected quantity.
                $unitCost = $parcelQuantity > 0 && $parcelCost > 0
                    ? $parcelCost / $parcelQuantity
                    : (float) $ingredient->cost_per_unit;
                $lineTotal = round((float) $quantity * $unitCost, 4);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    // Only a catalogue link: ingredients are not products, and
                    // not every ingredient is linked to one.
                    'product_id' => $ingredient->product_id ? (int) $ingredient->product_id : null,
                    'ingredient_id' => (int) $ingredient->id,
                    'supplier_id' => $supplierId,
                    'quantity' => $quantity,
                    'unit' => $ingredient->unit,
                    'unit_cost' => $unitCost,
                    'tax_rate' => 0,
                    'line_total' => $lineTotal,
                    'cost_per_unit' => $unitCost,
                    'estimated_total' => $lineTotal,
                    'parcel_quantity' => $parcelQuantity > 0 ? $parcelQuantity : null,
                    'parcel_cost' => $parcelCost > 0 ? $parcelCost : null,
                    'status' => 'suggested',
                ]);

                $total += $lineTotal;
            }

            $order->subtotal = round($total, 4);
            $order->total = round($total, 4);
            $order->save();

            return $order;
        });

        $purchaseOrder->load(['items.ingredient', 'items.product', 'items.approvals', 'supplier', 'outlet']);

        return [
            'purchaseOrder' => $this->toPurchaseOrderDto($purchaseOrder),
            'selections' => $this->toSelectionDtoCollection($selections),
            'budgetUsed' => (float) $purchaseOrder->total,
            'budgetLeft' => $budgetOkay === 'capped' ? max(0.0, $budget - (float) $purchaseOrder->total) : null,
            'forecast' => $forecast,
        ];
    }

    /**
     * Accept a drafted line, optionally at a different quantity or unit cost.
     * The line is what receiving works from, so the approval is applied to it
     * rather than only recorded beside it.
     */
    public function approveItem(
        int $businessId,
        int $purchaseOrderItemId,
        array $params,
    ): PurchaseOrderItem {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        return DB::transaction(function () use ($businessId, $purchaseOrderItemId, $params) {
            $item = PurchaseOrderItem::forBusiness($businessId)
                ->lockForUpdate()
                ->findOrFail($purchaseOrderItemId);

            $quantity = (string) ($params['quantity'] ?? $item->quantity);
            $unitCost = (string) ($params['cost_per_unit'] ?? $item->cost_per_unit ?? $item->unit_cost);
            $lineTotal = round((float) $quantity * (float) $unitCost, 4);

            $item->approvals()->create([
                'approved_by' => $this->auth->currentUser()?->id,
                'quantity' => $quantity,
                'cost_per_unit' => $unitCost,
                'notes' => $params['notes'] ?? null,
            ]);

            $item->quantity = $quantity;
            $item->unit_cost = $unitCost;
            $item->cost_per_unit = $unitCost;
            $item->line_total = $lineTotal;
            $item->estimated_total = $lineTotal;
            $item->status = 'approved';
            $item->save();

            $this->recalculatePurchaseOrder((int) $item->purchase_order_id);

            return $item->fresh();
        });
    }

    public function rejectItem(
        int $businessId,
        int $purchaseOrderItemId,
    ): PurchaseOrderItem {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        return DB::transaction(function () use ($businessId, $purchaseOrderItemId) {
            $item = PurchaseOrderItem::forBusiness($businessId)
                ->lockForUpdate()
                ->findOrFail($purchaseOrderItemId);

            $item->approvals()->create([
                'approved_by' => $this->auth->currentUser()?->id,
                'quantity' => 0,
                'cost_per_unit' => $item->cost_per_unit,
                'rejected_at' => now(),
                'notes' => 'Rejected in reorder copilot',
            ]);

            // A rejected line must not be receivable, so it drops to zero and
            // stops counting towards the order total.
            $item->quantity = 0;
            $item->line_total = 0;
            $item->estimated_total = 0;
            $item->status = 'rejected';
            $item->save();

            $this->recalculatePurchaseOrder((int) $item->purchase_order_id);

            return $item->fresh();
        });
    }

    /**
     * Keep the header totals and status in step with the individual lines: the
     * order becomes approvable only once every line has been accepted or
     * rejected, and an order where everything was rejected is cancelled.
     */
    private function recalculatePurchaseOrder(int $purchaseOrderId): void
    {
        $order = PurchaseOrder::lockForUpdate()->find($purchaseOrderId);
        if (! $order) {
            return;
        }

        $items = PurchaseOrderItem::where('purchase_order_id', $order->id)->get();
        $total = 0.0;
        foreach ($items as $item) {
            if ($item->status !== 'rejected') {
                $total += (float) $item->line_total;
            }
        }

        $order->subtotal = round($total, 4);
        $order->total = round($total, 4);

        $undecided = $items->contains(fn (PurchaseOrderItem $item) => $item->status === 'suggested');
        $approved = $items->contains(fn (PurchaseOrderItem $item) => $item->status === 'approved');

        if (! $undecided) {
            if ($approved) {
                $order->status = 'approved';
                $order->approved_by = $order->approved_by ?: $this->auth->currentUser()?->id;
                $order->approved_at = $order->approved_at ?: now();
            } else {
                $order->status = 'cancelled';
            }
        }

        $order->save();
    }

    private function resolveOutletId(int $businessId, int $requested): int
    {
        if ($requested > 0) {
            if (! Outlet::where('id', $requested)->where('business_id', $businessId)->exists()) {
                throw ValidationException::withMessages([
                    'outlet_id' => 'The selected outlet does not belong to this business.',
                ]);
            }

            return $requested;
        }

        $fallback = (int) Outlet::where('business_id', $businessId)->orderBy('id')->value('id');
        if ($fallback === 0) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Create an outlet before drafting a purchase order.',
            ]);
        }

        return $fallback;
    }

    private function resolveSupplierId(int $businessId, int $requested, array $selections): int
    {
        if ($requested > 0) {
            if (! Supplier::where('id', $requested)->where('business_id', $businessId)->exists()) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'The selected supplier does not belong to this business.',
                ]);
            }

            return $requested;
        }

        foreach ($selections as $selection) {
            $preferred = (int) Ingredient::forBusiness($businessId)
                ->whereKey($selection['ingredientId'])
                ->value('preferred_supplier_id');
            if ($preferred > 0) {
                return $preferred;
            }
        }

        return (int) Supplier::where('business_id', $businessId)->orderBy('id')->value('id');
    }

    private function nextPurchaseOrderNumber(int $businessId): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $taken = PurchaseOrder::where('business_id', $businessId)
                ->where('po_number', $number)
                ->exists();
            if (! $taken) {
                return $number;
            }
        }

        return 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));
    }

    private function salesQuantitiesByProduct(
        int $businessId,
        Collection $productIds,
        Carbon $from,
        Carbon $until,
    ): Collection {
        if ($productIds->isEmpty()) {
            return collect();
        }

        // `sales.status` is draft/completed/voided/refunded/partially_refunded.
        // Voided and fully refunded sales were put back into stock, so they do
        // not drive demand; partially refunded ones still left the door.
        return DB::table('sales')
            ->where('business_id', $businessId)
            ->whereIn('status', ['completed', 'partially_refunded'])
            ->whereBetween('completed_at', [$from, $until])
            ->join('sale_items', 'sales.id', '=', 'sale_items.sale_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as quantity')
            ->groupBy('sale_items.product_id')
            ->get()
            ->keyBy('product_id')
            ->map(fn (object $row): float => (float) $row->quantity);
    }

    private function consumptionFromRecipes(
        Collection $ingredients,
        Collection $salesQuantitiesByProduct,
        Collection $products,
    ): Collection {
        $consumption = collect();

        foreach ($ingredients as $ingredient) {
            $total = 0.0;

            foreach ($ingredient['recipes'] as $recipeRelation) {
                $product = $recipeRelation['recipe']?->product;
                if (! $product) {
                    continue;
                }
                $sold = $salesQuantitiesByProduct->get($product->id, 0.0);
                if ($sold <= 0.0) {
                    continue;
                }

                $recipeYield = (float) ($recipeRelation['recipe']->yield_quantity ?? 1);
                $perUnit = (float) $recipeRelation['quantityInRecipe'] / max(0.0001, $recipeYield);
                $total += $sold * $perUnit;
            }

            $consumption->put($ingredient['ingredient']->id, [
                'theoretical' => $total,
                'soldUnits' => $salesQuantitiesByProduct
                    ->filter(fn (float $q, int $pid) => $ingredient['recipes']
                        ->contains(fn (array $r) => (int) $r['recipe']->product_id === $pid))
                    ->sum(fn (float $q, int $pid): float => $q),
                'recipeDriven' => $total > 0.0,
            ]);
        }

        return $consumption;
    }

    private function forecastForIngredient(
        array $data,
        array $consumption,
        Collection $salesQuantitiesByProduct,
        Collection $products,
        Carbon $forecastAt,
        int $minCoverageDays,
        array $models,
    ): array {
        $ingredient = $data['ingredient'];
        $forecast = [
            'method' => 'none',
            'dailyDemand' => 0.0,
            'leadTimeDemand' => 0.0,
            // The DTO reads these on every path, including the early returns for
            // untracked or out-of-stock ingredients.
            'targetStock' => null,
            'currentStock' => $data['currentStock'],
            'reorderQuantity' => 0.0,
            'reorderQuantityUnit' => $ingredient->unit,
            'suggestedParcelQuantity' => null,
            'suggestedParcelCost' => null,
            'reorderReason' => 'not enough data to forecast',
            'predictedStockAtReorderDate' => $data['currentStock'],
            'daysOfStockRemaining' => null,
            'wasteRisk' => null,
            'blindSpot' => null,
        ];

        if (! $data['trackInventory']) {
            $forecast['blindSpot'] = 'Ingredient is not tracked in inventory; enable tracking to receive reorder suggestions.';

            return $forecast;
        }

        if ($data['currentStock'] <= 0.0) {
            $forecast['reorderReason'] = 'Out of stock — suggest reorder immediately.';
            $forecast['predictedStockAtReorderDate'] = 0.0;

            return $forecast;
        }

        $hasRecipes = $data['recipes']->isNotEmpty();
        $recipeTotal = $hasRecipes
            ? $this->recipeDrivenSales($data, $salesQuantitiesByProduct, $products)
            : null;
        $photoDriven = $this->photoDrivenDemand($data);

        $demandSignal = null;
        $method = 'none';

        if ($models['demand'] ?? true) {
            if ($recipeTotal !== null && $recipeTotal > 0.0 && ($consumption['recipeDriven'] ?? false)) {
                $demandSignal = $recipeTotal;
                $method = 'recipe-demand';
            } elseif ($photoDriven !== null && $photoDriven > 0.0 && ($data['photoAndUsePatterns']->isNotEmpty())) {
                $demandSignal = $photoDriven;
                $method = 'photo-demand';
            }
        }

        if ($method === 'none') {
            $forecast['blindSpot'] = 'No sales-driven or photo-driven demand signal; refine recipes or photo patterns to forecast this ingredient.';

            return $forecast;
        }

        $lookbackDays = $this->lookbackDays($forecastAt);
        $dailyDemand = $demandSignal / max(1, $lookbackDays);
        $leadTimeDays = $this->effectiveLeadTimeDays($ingredient, $data['photoAndUsePatterns']->first()?->suggestedParcels ?? []);
        $leadTimeDemand = $dailyDemand * max(1, $leadTimeDays);
        $targetStock = $leadTimeDemand + ($dailyDemand * $minCoverageDays);

        $forecast['method'] = $method;
        $forecast['dailyDemand'] = $dailyDemand;
        $forecast['leadTimeDemand'] = $leadTimeDemand;
        $forecast['targetStock'] = $targetStock;
        $forecast['currentStock'] = $data['currentStock'];
        $forecast['daysOfStockRemaining'] = $dailyDemand > 0.0 ? $data['currentStock'] / $dailyDemand : null;
        $forecast['reorderQuantity'] = max(0.0, $targetStock - $data['currentStock']);
        $forecast['reorderQuantityUnit'] = $ingredient->unit;
        $forecast['reorderReason'] = $this->reorderReason($data, $dailyDemand, $leadTimeDemand, $targetStock);
        $forecast['predictedStockAtReorderDate'] = max(0.0, $data['currentStock'] - $leadTimeDemand);

        if (($models['shelfLife'] ?? true) && $data['shelfLifeDays'] > 0.0) {
            $wasteRisk = $this->wasteRisk($data['currentStock'], $data['shelfLifeDays'], $dailyDemand, $leadTimeDays);
            $forecast['wasteRisk'] = $wasteRisk;
            if ($wasteRisk['likelyWasteQuantity'] > 0.0) {
                $forecast['shelfLifeNote'] = $wasteRisk['note'];
            }
        }

        if (($models['leadTime'] ?? true) && $leadTimeDays > 0) {
            $forecast['leadTimeDays'] = $leadTimeDays;
        }

        return $forecast;
    }

    /**
     * Total ingredient units consumed by sales over the lookback window. The
     * caller turns this into a daily rate, so do not divide here.
     */
    private function recipeDrivenSales(array $data, Collection $salesQuantitiesByProduct, Collection $products): ?float
    {
        $consumed = 0.0;

        foreach ($data['recipes'] as $recipeRelation) {
            $product = $recipeRelation['recipe']?->product;
            if (! $product) {
                continue;
            }
            $sold = $salesQuantitiesByProduct->get($product->id, 0.0);
            if ($sold <= 0.0) {
                continue;
            }
            $recipeYield = (float) ($recipeRelation['recipe']->yield_quantity ?? 1);
            $perUnit = (float) $recipeRelation['quantityInRecipe'] / max(0.0001, $recipeYield);
            $consumed += $sold * $perUnit;
        }

        return $consumed > 0.0 ? $consumed : null;
    }

    private function photoDrivenDemand(array $data): ?float
    {
        $patterns = $data['photoAndUsePatterns'];
        if ($patterns->isEmpty()) {
            return null;
        }

        $best = $patterns->sortByDesc(fn (IngredientPhotoAndUsePattern $p): float => $p->consumption_rate)->first();
        if (! $best instanceof IngredientPhotoAndUsePattern) {
            return null;
        }

        // `consumption_rate` is per day; the caller expects a lookback total.
        $perDay = $best->consumption_rate * $best->photo_share_estimate;
        $consumed = $perDay * $this->lookbackDays(Carbon::now());

        return $consumed > 0.0 ? $consumed : null;
    }

    private function wasteRisk(
        float $currentStock,
        float $shelfLifeDays,
        float $dailyDemand,
        int $leadTimeDays,
    ): array {
        if ($dailyDemand <= 0.0) {
            return [
                'likelyWasteQuantity' => $currentStock,
                'note' => 'No demand signal — stock is at risk of expiring unused.',
            ];
        }

        $usageUntilExpiry = $dailyDemand * $shelfLifeDays;
        $likelyWasteQuantity = max(0.0, $currentStock - $usageUntilExpiry);

        if ($likelyWasteQuantity <= 0.0) {
            return ['likelyWasteQuantity' => 0.0, 'note' => 'Stock likely consumed before expiry.'];
        }

        return [
            'likelyWasteQuantity' => $likelyWasteQuantity,
            'note' => "At current demand, ~{$likelyWasteQuantity} {$this->unitWord($currentStock)} may go unused before the {$shelfLifeDays}-day shelf life ends.",
        ];
    }

    private function reorderReason(
        array $data,
        float $dailyDemand,
        float $leadTimeDemand,
        float $targetStock,
    ): string {
        if ($data['currentStock'] <= 0.0) {
            return 'Out of stock — reorder immediately.';
        }
        if ($data['currentStock'] < $data['reorderLevel']) {
            return 'Stock is below the reorder level; replenish soon.';
        }
        if ($data['currentStock'] < $targetStock) {
            return 'Forecasted demand before the next delivery will dip below comfort level.';
        }

        return 'Projected to stay above comfort level until the next delivery.';
    }

    private function toForecastDto(array $forecast): array
    {
        return [
            'method' => $forecast['method'],
            'dailyDemand' => $forecast['dailyDemand'],
            'leadTimeDemand' => $forecast['leadTimeDemand'],
            'targetStock' => $forecast['targetStock'] ?? null,
            'currentStock' => $forecast['currentStock'],
            'reorderQuantity' => $forecast['reorderQuantity'],
            'reorderQuantityUnit' => $forecast['reorderQuantityUnit'],
            'suggestedParcelQuantity' => $forecast['suggestedParcelQuantity'],
            'suggestedParcelCost' => $forecast['suggestedParcelCost'],
            'reorderReason' => $forecast['reorderReason'],
            'predictedStockAtReorderDate' => $forecast['predictedStockAtReorderDate'],
            'daysOfStockRemaining' => $forecast['daysOfStockRemaining'],
            'wasteRisk' => $forecast['wasteRisk'],
            'blindSpot' => $forecast['blindSpot'],
            'shelfLifeNote' => $forecast['shelfLifeNote'] ?? null,
            'leadTimeDays' => $forecast['leadTimeDays'] ?? null,
        ];
    }

    private function toConsumptionDto(array $consumption): array
    {
        return [
            'theoretical' => $consumption['theoretical'],
            'soldUnits' => $consumption['soldUnits'],
            'recipeDriven' => $consumption['recipeDriven'],
        ];
    }

    private function photoPattern(Collection $patterns): ?array
    {
        if ($patterns->isEmpty()) {
            return null;
        }

        $best = $patterns->sortByDesc(fn (IngredientPhotoAndUsePattern $p): float => $p->consumption_rate)->first();
        if (! $best instanceof IngredientPhotoAndUsePattern) {
            return null;
        }

        return [
            'patternId' => (string) $best->id,
            'name' => $best->name,
            'consumptionRate' => $best->consumption_rate,
            'photoShareEstimate' => $best->photo_share_estimate,
            'suggestedParcels' => $best->suggestedParcels->map(fn (PurchaseOrderItemSuggestedSupplierParcel $parcel): array => [
                'parcelQuantity' => $parcel->parcel_quantity,
                'parcelCost' => $parcel->parcel_cost,
                'supplierId' => (string) $parcel->supplier_id,
                'supplierName' => $parcel->supplier?->name,
            ])->values(),
        ];
    }

    private function effectiveLeadTimeDays(
        Ingredient $ingredient,
        array $parcelCandidates,
    ): int {
        if ($ingredient->lead_time_days !== null && $ingredient->lead_time_days > 0) {
            return (int) $ingredient->lead_time_days;
        }

        if (! empty($parcelCandidates)) {
            $bestSupplierParcel = collect($parcelCandidates)
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
            if ($bestSupplierParcel && isset($bestSupplierParcel['supplierLeadTimeDays'])) {
                return (int) $bestSupplierParcel['supplierLeadTimeDays'];
            }
        }

        return 7;
    }

    private function effectiveLeadTimeDaysForBusiness(int $businessId, array $selections): int
    {
        $leadTimeDays = 7;
        foreach ($selections as $selection) {
            $ingredient = Ingredient::forBusiness($businessId)->find((int) $selection['ingredientId']);
            if ($ingredient instanceof Ingredient && $ingredient->lead_time_days !== null && $ingredient->lead_time_days > 0) {
                $leadTimeDays = max($leadTimeDays, (int) $ingredient->lead_time_days);
            }
        }

        return $leadTimeDays;
    }

    private function lookbackDays(Carbon $forecastAt): int
    {
        // Carbon 3 returns a float from diffInDays; the forecast divides by this.
        return (int) $forecastAt->copy()->subDays(14)->startOfDay()->diffInDays($forecastAt->copy()->endOfDay());
    }

    private function unitWord(float $quantity): string
    {
        return $quantity === 1.0 ? 'unit' : 'units';
    }

    private function purchaseMoneyBudget(Collection $blocks, int $businessId): array
    {
        $totalCostOfReorder = 0.0;
        foreach ($blocks as $block) {
            // Blocks carry `trackInventory`; the old `isTracked` key never existed,
            // so the budget always came back as zero.
            if ($block['trackInventory'] && $block['forecast']['method'] !== 'none') {
                $quantity = $block['forecast']['reorderQuantity'];
                if ($quantity > 0.0) {
                    $totalCostOfReorder += $quantity * (float) $block['ingredient']->cost_per_unit;
                }
            }
        }

        return [
            'amount' => round($totalCostOfReorder, 2),
            'currency' => Business::find($businessId)?->currency ?? 'USD',
            'note' => 'Estimated cost of suggested reorder quantities at current ingredient cost.',
        ];
    }

    private function selectReorderItems(
        array $forecast,
        int $supplierId,
        float $budget,
        string $budgetOkay,
        bool $preferCurrentStockSupplier,
        array $suggestedParcelsByProduct,
    ): array {
        $candidates = collect($forecast['ingredients'])
            ->filter(fn (array $ing): bool => $ing['isTracked']
                && $ing['forecast']['method'] !== 'none'
                && $ing['forecast']['reorderQuantity'] > 0.0
            )
            ->map(fn (array $ing): array => [
                'ingredientId' => (int) $ing['id'],
                'name' => $ing['name'],
                'unit' => $ing['unit'],
                'costPerUnit' => $ing['costPerUnit'],
                'reorderQuantity' => $ing['forecast']['reorderQuantity'],
                'reorderReason' => $ing['forecast']['reorderReason'],
                'wasteRisk' => $ing['forecast']['wasteRisk'],
                'suggestedParcelQuantity' => $ing['forecast']['suggestedParcelQuantity'],
                'suggestedParcelCost' => $ing['forecast']['suggestedParcelCost'],
                'preferredParcel' => $this->preferredParcelFromForecast($ing, $suggestedParcelsByProduct),
                'priorityScore' => $this->priorityScore($ing, $budgetOkay),
            ])
            ->sortByDesc(fn (array $c): float => $c['priorityScore'])
            ->values();

        $selections = [];
        $budgetRemaining = $budgetOkay === 'capped' ? $budget : null;

        foreach ($candidates as $candidate) {
            // `preferredParcelFromForecast` returns the forecast DTO's camelCase
            // array (or null), so the parcel is never an Eloquent model here.
            $parcel = $candidate['preferredParcel'];
            $parcelQuantity = $parcel['parcelQuantity'] ?? null;
            $parcelCost = $parcel['parcelCost'] ?? null;
            $hasParcel = $parcelQuantity !== null && (float) $parcelQuantity > 0;
            $cost = $hasParcel
                ? (float) $parcelCost
                : $candidate['reorderQuantity'] * $candidate['costPerUnit'];

            if ($budgetRemaining !== null && $cost > $budgetRemaining) {
                break;
            }

            $selections[] = [
                'ingredientId' => $candidate['ingredientId'],
                'name' => $candidate['name'],
                'unit' => $candidate['unit'],
                'costPerUnit' => $candidate['costPerUnit'],
                'reorderQuantity' => $hasParcel ? $parcelQuantity : $candidate['reorderQuantity'],
                'estimatedCost' => $cost,
                'reorderReason' => $candidate['reorderReason'],
                'wasteRisk' => $candidate['wasteRisk'],
                'suggestedParcelQuantity' => $hasParcel ? $parcelQuantity : null,
                'suggestedParcelCost' => $hasParcel ? $parcelCost : null,
                'priorityScore' => $candidate['priorityScore'],
            ];

            if ($budgetRemaining !== null) {
                $budgetRemaining -= $cost;
            }
        }

        return $selections;
    }

    private function preferredParcelFromForecast(array $ingredient, array $suggestedParcelsByProduct): ?array
    {
        $photoPattern = $ingredient['photoPattern'];
        if ($photoPattern && ! empty($photoPattern['suggestedParcels'])) {
            return collect($photoPattern['suggestedParcels'])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
        }

        $productId = (int) $ingredient['id'];
        if (isset($suggestedParcelsByProduct[$productId]) && ! empty($suggestedParcelsByProduct[$productId])) {
            return collect($suggestedParcelsByProduct[$productId])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
        }

        return null;
    }

    private function priorityScore(array $ingredient, string $budgetOkay): float
    {
        $score = 0.0;
        $forecast = $ingredient['forecast'];

        if ($forecast['currentStock'] <= 0.0) {
            $score += 1000;
        }
        if ($forecast['currentStock'] < $ingredient['reorderLevel']) {
            $score += 500;
        }
        if ($forecast['wasteRisk'] && ($forecast['wasteRisk']['likelyWasteQuantity'] ?? 0.0) > 0.0) {
            $score += 200;
        }
        $score += min(100, round($forecast['dailyDemand'] * 10, 2));
        if ($budgetOkay === 'capped') {
            $score += 1;
        }

        return $score;
    }

    /**
     * Attach to every parcel the forecast its supplier lead time would produce,
     * so a client can show what picking one changes without repeating the
     * arithmetic. A parcel whose supplier has no lead time changes nothing, so
     * its preview is null.
     *
     * @param  array<int, array<string, mixed>>  $parcels
     * @return array<int, array<string, mixed>>
     */
    private function withParcelPreviews(array $parcels, array $block, array $forecast, int $minCoverageDays): array
    {
        $dailyDemand = (float) $forecast['dailyDemand'];
        $currentStock = (float) $block['currentStock'];

        return array_map(function (array $parcel) use ($dailyDemand, $currentStock, $minCoverageDays, $forecast): array {
            $leadTimeDays = $parcel['supplierLeadTimeDays'] ?? null;

            if ($leadTimeDays === null || $forecast['method'] === 'none' || $dailyDemand <= 0.0 || $currentStock <= 0.0) {
                $parcel['preview'] = null;

                return $parcel;
            }

            $leadTimeDays = max(1, (int) $leadTimeDays);
            $leadTimeDemand = $dailyDemand * $leadTimeDays;
            $targetStock = $leadTimeDemand + ($dailyDemand * $minCoverageDays);

            $reorderQuantity = max(0.0, $targetStock - $currentStock);
            // Whole parcels only: a client can price the reorder exactly as the
            // supplier sells it, without rounding a fractional case itself.
            $parcelQuantity = (float) ($parcel['parcelQuantity'] ?? 0.0);
            $parcelCost = (float) ($parcel['parcelCost'] ?? 0.0);
            $parcelsRequired = $parcelQuantity > 0.0
                ? (int) ceil($reorderQuantity / $parcelQuantity)
                : null;

            $parcel['preview'] = [
                'leadTimeDays' => $leadTimeDays,
                'leadTimeDemand' => $leadTimeDemand,
                'targetStock' => $targetStock,
                'reorderQuantity' => $reorderQuantity,
                'predictedStockAtReorderDate' => max(0.0, $currentStock - $leadTimeDemand),
                'parcelsRequired' => $parcelsRequired,
                'totalCost' => $parcelsRequired === null ? null : $parcelsRequired * $parcelCost,
                // Whole parcels can overshoot the cover target by a fraction of
                // a case; that surplus is what stays on the shelf afterwards.
                'leftoverStock' => $parcelsRequired === null
                    ? null
                    : max(0.0, $currentStock + ($parcelsRequired * $parcelQuantity) - $targetStock),
            ];

            return $parcel;
        }, $parcels);
    }

    /**
     * Group parcel rows under one foreign key and sort each group by unit cost,
     * so the cheapest suggested parcel is first for every product or ingredient.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function groupParcels(Collection $parcels, string $key): array
    {
        return $parcels->groupBy(fn (PurchaseOrderItemSuggestedSupplierParcel $parcel): int => (int) $parcel->{$key})
            ->map(fn (Collection $group): array => $this->toParcelDtos($group))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function toParcelDtos(Collection $parcels): array
    {
        return $parcels->map(fn (PurchaseOrderItemSuggestedSupplierParcel $parcel): array => [
            'parcelQuantity' => $parcel->parcel_quantity,
            'parcelCost' => $parcel->parcel_cost,
            // Price per ingredient unit, so parcels can be compared before one
            // is picked. Four decimals, like every other money value.
            'unitCost' => (float) $parcel->parcel_quantity > 0.0
                ? round((float) $parcel->parcel_cost / (float) $parcel->parcel_quantity, 4)
                : null,
            'supplierId' => (string) $parcel->supplier_id,
            'supplierName' => $parcel->supplier?->name,
            'supplierLeadTimeDays' => $parcel->supplier?->lead_time_days ?? null,
        ])->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
            ->values()
            ->all();
    }

    private function toPurchaseOrderDto(PurchaseOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'number' => $order->po_number,
            'supplierId' => (string) $order->supplier_id,
            'supplierName' => $order->supplier?->name,
            'outletId' => $order->outlet_id ? (string) $order->outlet_id : null,
            'outletName' => $order->outlet?->name,
            'status' => $order->status,
            'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => (string) $item->id,
                'productId' => $item->product_id ? (string) $item->product_id : null,
                'productName' => $item->product?->name ?? $item->ingredient?->name,
                'ingredientId' => (string) $item->ingredient_id,
                'ingredientName' => $item->ingredient?->name,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'costPerUnit' => (float) $item->cost_per_unit,
                'estimatedTotal' => (float) $item->estimated_total,
                'parcelQuantity' => $item->parcel_quantity,
                'parcelCost' => $item->parcel_cost,
                'status' => $item->status,
                'approvals' => $item->approvals->map(fn (PurchaseOrderItemApproval $approval): array => [
                    'approvedBy' => $approval->approved_by,
                    'quantity' => $approval->quantity,
                    'costPerUnit' => (float) $approval->cost_per_unit,
                    'notes' => $approval->notes,
                    'rejectedAt' => $approval->rejected_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'total' => (float) $order->total,
            'expectedAt' => $order->expected_at?->toIso8601String(),
            'createdAt' => $order->created_at->toIso8601String(),
        ];
    }

    private function toSelectionDtoCollection(array $selections): array
    {
        return collect($selections)->map(fn (array $selection): array => [
            'ingredientId' => (string) $selection['ingredientId'],
            'name' => $selection['name'],
            'unit' => $selection['unit'],
            'costPerUnit' => $selection['costPerUnit'],
            'reorderQuantity' => $selection['reorderQuantity'],
            'estimatedCost' => $selection['estimatedCost'],
            'reorderReason' => $selection['reorderReason'],
            'wasteRisk' => $selection['wasteRisk'],
            'suggestedParcelQuantity' => $selection['suggestedParcelQuantity'],
            'suggestedParcelCost' => $selection['suggestedParcelCost'],
            'priorityScore' => $selection['priorityScore'],
        ])->values()->all();
    }
}

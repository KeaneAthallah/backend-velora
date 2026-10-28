<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientConsumptionForecast;
use App\Models\IngredientDemandForecast;
use App\Models\IngredientForecastRule;
use App\Models\IngredientPhotoAndUsePattern;
use App\Models\IngredientRecipeRelation;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemApproval;
use App\Models\PurchaseOrderItemCostOverride;
use App\Models\PurchaseOrderItemSuggestedSupplier;
use App\Models\PurchaseOrderItemSuggestedSupplierParcel;
use App\Models\Product;
use App\Services\AuthorizationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            ->with('recipes.recipe.product', 'recipes.recipe.productOutletPrices')
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'ingredient' => $ingredient,
                'openingBalance' => $ingredient->opening_quantity,
                'currentStock' => $ingredient->current_stock,
                'reorderLevel' => (float) $ingredient->reorder_level,
                'trackInventory' => $ingredient->track_inventory,
                'shelfLifeDays' => (float) ($ingredient->shelf_life_days ?? Ingredient::SHELF_LIFE_UNTRACKED),
                'photoAndUsePatterns' => $ingredient->photoAndUsePatterns,
                'recipes' => $ingredient->recipes->map(fn (IngredientRecipeRelation $relation) => [
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
                $salesQuantitiesByProduct,
                $sales,
                $forecastAt,
                $minCoverageDays,
                $models,
            ),
        ]);

        $suggestedParcelsByProduct = $this->suggestedParcelsForBusiness($businessId);

        $purchaseMoneyBudget = $this->purchaseMoneyBudget($forecastBlocks);

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
            'ingredients' => $forecastBlocks->map(fn (array $block): array => [
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
                'leadTimeDays' => $this->effectiveLeadTimeDays(
                    $block['ingredient'],
                    $suggestedParcelsByProduct,
                ),
                'forecast' => $this->toForecastDto($block['forecast']),
                'consumption' => $this->toConsumptionDto($block['consumption']),
            ])->values(),
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

        $supplierId = (int) ($params['supplierId'] ?? 0);
        $budget = (float) ($params['budget'] ?? $forecast['purchaseMoneyBudget']['amount']);
        $budgetOkay = $budget <= 0 ? 'unbounded' : 'capped';
        $preferCurrentStockSupplier = (bool) ($params['preferCurrentStockSupplier'] ?? false);

        $selections = $this->selectReorderItems(
            $forecast,
            $supplierId,
            $budget,
            $budgetOkay,
            $preferCurrentStockSupplier,
            $forecast['suggestedParcelsByProduct'],
        );

        $purchaseOrder = PurchaseOrder::forceCreate([
            'business_id' => $businessId,
            'supplier_id' => $supplierId,
            'outlet_id' => null,
            'number' => PurchaseOrder::nextNumber($businessId),
            'status' => 'draft',
            'expected_at' => Carbon::now()->addDays($this->effectiveLeadTimeDaysForBusiness($forecast, $selections))->toDateString(),
            'created_by' => $this->auth->currentUser()?->id,
        ]);

        $total = 0.0;
        $itemIds = [];

        foreach ($selections as $selection) {
            $ingredient = Ingredient::forBusiness($businessId)->findOrFail((int) $selection['ingredientId']);
            $parcel = $this->preferredParcel($ingredient, $selection, $suggestedParcelsByProduct: $forecast['suggestedParcelsByProduct']);
            $item = PurchaseOrderItem::create([
                'purchase_order_id' => $purchaseOrder->id,
                'product_id' => (int) $ingredient->product_id,
                'ingredient_id' => (int) $ingredient->id,
                'quantity' => $selection['quantity'],
                'unit' => $ingredient->unit,
                'cost_per_unit' => (float) $ingredient->cost_per_unit,
                'estimated_total' => $selection['quantity'] * (float) $ingredient->cost_per_unit,
                'parcel_quantity' => $parcel->parcel_quantity,
                'parcel_cost' => $parcel->parcel_cost,
                'supplier_id' => $supplierId,
            ]);

            $itemIds[] = $item->id;
            $total += $item->estimated_total;
        }

        PurchaseOrder::where('id', $purchaseOrder->id)->update(['total' => round($total, 4)]);

        $purchaseOrder->load(['items.ingredient', 'items.product', 'supplier']);

        return [
            'purchaseOrder' => $this->toPurchaseOrderDto($purchaseOrder),
            'selections' => $this->toSelectionDtoCollection($selections),
            'budgetUsed' => $total,
            'budgetLeft' => $budgetOkay === 'capped' ? max(0.0, $budget - $total) : null,
            'forecast' => $forecast,
        ];
    }

    public function approveItem(
        int $businessId,
        int $purchaseOrderItemId,
        array $params,
    ): PurchaseOrderItem {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        $item = PurchaseOrderItem::forBusiness($businessId)->findOrFail($purchaseOrderItemId);
        $item->approvals()->create([
            'approved_by' => $this->auth->currentUser()?->id,
            'quantity' => $params['quantity'] ?? $item->quantity,
            'cost_per_unit' => $params['cost_per_unit'] ?? $item->cost_per_unit,
            'notes' => $params['notes'] ?? null,
        ]);

        return $item->fresh();
    }

    public function rejectItem(
        int $businessId,
        int $purchaseOrderItemId,
    ): PurchaseOrderItem {
        $this->auth->requirePermission($this->auth->currentUser(), 'purchasing.manage', $businessId);

        $item = PurchaseOrderItem::forBusiness($businessId)->findOrFail($purchaseOrderItemId);
        $item->approvals()->create([
            'approved_by' => $this->auth->currentUser()?->id,
            'quantity' => 0,
            'cost_per_unit' => $item->cost_per_unit,
            'rejected_at' => now(),
            'notes' => 'Rejected in reorder copilot',
        ]);

        return $item->fresh();
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

        return DB::table('sales')
            ->where('business_id', $businessId)
            ->whereIn('status', ['completed', 'completed_refunded'])
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
                $product = $recipeRelation['recipe']->product;
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

        if (!$data['trackInventory']) {
            $forecast['blindSpot'] = 'Ingredient is not tracked in inventory; enable tracking to receive reorder suggestions.';
            return $forecast;
        }

        if ($data['currentStock'] <= 0.0) {
            $forecast['reorderReason'] = 'Out of stock — suggest reorder immediately.';
            $forecast['predictedStockAtReorderDate'] = 0.0;
            return $forecast;
        }

        $recipeDriven = $data['recipes']->isNotEmpty();
        $salesDriven = $recipeDriven
            ? $this->recipeDrivenSales(ingredient: $data, salesQuantitiesByProduct: $salesQuantitiesByProduct, products: $products)
            : null;
        $photoDriven = $this->photoDrivenDemand($data);

        $demandSignal = null;
        $method = 'none';

        if ($models['demand'] ?? true) {
            if ($recipeDriven !== null && $recipeDriven > 0.0 && ($data['consumption']['recipeDriven'] ?? false)) {
                $demandSignal = $recipeDriven;
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

    private function recipeDrivenSales(array $data, Collection $salesQuantitiesByProduct, Collection $products): ?float
    {
        $perDay = 0.0;
        $lookbackDays = $this->lookbackDays(Carbon::now());

        foreach ($data['recipes'] as $recipeRelation) {
            $product = $recipeRelation['recipe']->product;
            $sold = $salesQuantitiesByProduct->get($product->id, 0.0);
            if ($sold <= 0.0) {
                continue;
            }
            $recipeYield = (float) ($recipeRelation['recipe']->yield_quantity ?? 1);
            $perUnit = (float) $recipeRelation['quantityInRecipe'] / max(0.0001, $recipeYield);
            $perDay += ($sold / $lookbackDays) * $perUnit;
        }

        return $perDay > 0.0 ? $perDay : null;
    }

    private function photoDrivenDemand(array $data): ?float
    {
        $patterns = $data['photoAndUsePatterns'];
        if ($patterns->isEmpty()) {
            return null;
        }

        $best = $patterns->sortByDesc(fn (IngredientPhotoAndUsePattern $p): float => $p->consumption_rate)->first();
        if (!$best instanceof IngredientPhotoAndUsePattern) {
            return null;
        }

        $lookbackDays = $this->lookbackDays(Carbon::now());
        $perDay = $best->consumption_rate * $best->photo_share_estimate;

        return $perDay > 0.0 ? $perDay : null;
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
        if (!$best instanceof IngredientPhotoAndUsePattern) {
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

        if (!empty($parcelCandidates)) {
            $bestSupplierParcel = collect($parcelCandidates)
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
            if ($bestSupplierParcel && isset($bestSupplierParcel['supplierLeadTimeDays'])) {
                return (int) $bestSupplierParcel['supplierLeadTimeDays'];
            }
        }

        return 7;
    }

    private function effectiveLeadTimeDaysForBusiness(array $forecast, array $selections): int
    {
        $leadTimeDays = 7;
        foreach ($selections as $selection) {
            $ingredient = Ingredient::forBusiness($forecast['ingredients'][0]['ingredient']->business_id)
                ->find((int) $selection['ingredientId']);
            if ($ingredient instanceof Ingredient && $ingredient->lead_time_days !== null && $ingredient->lead_time_days > 0) {
                $leadTimeDays = max($leadTimeDays, (int) $ingredient->lead_time_days);
            }
        }
        return $leadTimeDays;
    }

    private function lookbackDays(Carbon $forecastAt): int
    {
        return $forecastAt->copy()->subDays(14)->startOfDay()->diffInDays($forecastAt->copy()->endOfDay());
    }

    private function unitWord(float $quantity): string
    {
        return $quantity === 1.0 ? 'unit' : 'units';
    }

    private function purchaseMoneyBudget(Collection $blocks): array
    {
        $totalCostOfReorder = 0.0;
        foreach ($blocks as $block) {
            if ($block['isTracked'] && $block['forecast']['method'] !== 'none') {
                $quantity = $block['forecast']['reorderQuantity'];
                if ($quantity > 0.0) {
                    $totalCostOfReorder += $quantity * $block['ingredient']->cost_per_unit;
                }
            }
        }

        return [
            'amount' => round($totalCostOfReorder, 2),
            'currency' => 'USD',
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
            ->filter(fn (array $ing): bool =>
                $ing['isTracked']
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
            $parcel = $candidate['preferredParcel'];
            $cost = $parcel?->parcel_cost ?? ($candidate['reorderQuantity'] * $candidate['costPerUnit']);

            if ($budgetRemaining !== null && $cost > $budgetRemaining) {
                break;
            }

            $selections[] = [
                'ingredientId' => $candidate['ingredientId'],
                'name' => $candidate['name'],
                'unit' => $candidate['unit'],
                'costPerUnit' => $candidate['costPerUnit'],
                'reorderQuantity' => $parcel?->parcel_quantity ?? $candidate['reorderQuantity'],
                'estimatedCost' => $cost,
                'reorderReason' => $candidate['reorderReason'],
                'wasteRisk' => $candidate['wasteRisk'],
                'suggestedParcelQuantity' => $parcel?->parcel_quantity,
                'suggestedParcelCost' => $parcel?->parcel_cost,
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
        if ($photoPattern && !empty($photoPattern['suggestedParcels'])) {
            return collect($photoPattern['suggestedParcels'])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
        }

        $productId = (int) $ingredient['id'];
        if (isset($suggestedParcelsByProduct[$productId]) && !empty($suggestedParcelsByProduct[$productId])) {
            return collect($suggestedParcelsByProduct[$productId])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
        }

        return null;
    }

    private function preferredParcel(Ingredient $ingredient, array $selection, array $suggestedParcelsByProduct): array
    {
        $photoPattern = $this->photoPattern($ingredient->photoAndUsePatterns);
        if ($photoPattern && !empty($photoPattern['suggestedParcels'])) {
            $best = collect($photoPattern['suggestedParcels'])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
            if ($best) {
                return $best;
            }
        }

        $productId = (int) $ingredient->product_id;
        if (isset($suggestedParcelsByProduct[$productId]) && !empty($suggestedParcelsByProduct[$productId])) {
            return collect($suggestedParcelsByProduct[$productId])
                ->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                ->first();
        }

        return [
            'parcel_quantity' => $selection['reorderQuantity'],
            'parcel_cost' => $selection['estimatedCost'],
            'supplier_id' => $ingredient->preferredSupplier?->id ?? null,
            'supplier_name' => $ingredient->preferredSupplier?->name,
        ];
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

    private function suggestedParcelsForBusiness(int $businessId): array
    {
        $parcels = PurchaseOrderItemSuggestedSupplierParcel::forBusiness($businessId)
            ->with('supplier')
            ->get();

        return $parcels->groupBy(fn (PurchaseOrderItemSuggestedSupplierParcel $parcel): int => $parcel->product_id)
            ->map(fn (Collection $parcels, int $productId): array =>
                $parcels->map(fn (PurchaseOrderItemSuggestedSupplierParcel $parcel): array => [
                    'parcelQuantity' => $parcel->parcel_quantity,
                    'parcelCost' => $parcel->parcel_cost,
                    'supplierId' => (string) $parcel->supplier_id,
                    'supplierName' => $parcel->supplier?->name,
                    'supplierLeadTimeDays' => $parcel->supplier?->lead_time_days ?? null,
                ])->sortBy(fn (array $parcel): float => $parcel['parcelCost'] / max(0.0001, $parcel['parcelQuantity']))
                    ->values()
                    ->all()
            )
            ->all();
    }

    private function toPurchaseOrderDto(PurchaseOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'number' => $order->number,
            'supplierId' => (string) $order->supplier_id,
            'supplierName' => $order->supplier?->name,
            'outletId' => $order->outlet_id ? (string) $order->outlet_id : null,
            'outletName' => $order->outlet?->name,
            'status' => $order->status,
            'items' => $order->items->map(fn (PurchaseOrderItem $item): array => [
                'id' => (string) $item->id,
                'productId' => (string) $item->product_id,
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
        ])->values();
    }
}

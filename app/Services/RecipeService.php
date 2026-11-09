<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientMovement;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Sale;
use InvalidArgumentException;

class RecipeService
{
    public function __construct(protected UnitConverter $units) {}

    /**
     * Resolve the effective recipe for a product (and optional variant).
     *
     * Variant lines override product-level lines for the same ingredient, which
     * lets a "large" variant add milk without duplicating the base coffee line.
     *
     * @return array<int, array{ingredient: Ingredient, quantity: string, effective_quantity: string, waste_percent: string, line_cost: string, item: RecipeItem}>
     */
    public function linesFor(Product $product, ?ProductVariant $variant = null): array
    {
        $items = RecipeItem::query()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->where(function ($query) use ($variant) {
                $query->whereNull('product_variant_id');
                if ($variant) {
                    $query->orWhere('product_variant_id', $variant->id);
                }
            })
            ->with('ingredient')
            ->orderBy('id')
            ->get();

        $resolved = [];

        foreach ($items as $item) {
            if (! $item->ingredient) {
                continue;
            }
            $ingredientId = (int) $item->ingredient_id;
            $isVariantLine = $item->product_variant_id !== null;

            // Variant lines win over base lines; base lines only fill gaps.
            if ($isVariantLine || ! isset($resolved[$ingredientId])) {
                $resolved[$ingredientId] = $this->line($item);
            }
        }

        return array_values($resolved);
    }

    public function costFor(Product $product, ?ProductVariant $variant = null): string
    {
        $total = '0';

        foreach ($this->linesFor($product, $variant) as $line) {
            $total = bcadd($total, $line['line_cost'], 4);
        }

        return $total;
    }

    /**
     * Serialized product payload for the recipe back office.
     */
    public function productPayload(Product $product): array
    {
        $product->loadMissing(['recipeItems.ingredient', 'variants']);

        $lines = $product->recipeItems
            ->map(fn (RecipeItem $item) => $this->serializeLine($item))
            ->values()
            ->all();

        $variantCosts = [];
        foreach ($product->variants as $variant) {
            $variantCosts[(string) $variant->id] = $this->costFor($product, $variant);
        }

        return array_merge($product->toArray(), [
            'recipe_items' => $lines,
            'recipe_cost' => $this->costFor($product, null),
            'variant_costs' => $variantCosts,
        ]);
    }

    /**
     * Deduct every tracked ingredient consumed by the sale.
     *
     * Called inside the sale transaction so a shortage rolls the whole sale back.
     */
    public function consumeForSale(Sale $sale, int $outletId, ?int $actorId = null): void
    {
        $sale->loadMissing('items');
        $needed = [];
        $removals = $this->ingredientRemovals(
            (int) $sale->business_id,
            $sale->items->flatMap(fn ($item) => (array) $item->option_ids)->map(fn ($id) => (int) $id)->all(),
        );

        foreach ($sale->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $item->product_variant_id ? ProductVariant::find($item->product_variant_id) : null;
            $quantity = (string) $item->quantity;

            $removed = [];
            foreach ((array) $item->option_ids as $optionId) {
                if (isset($removals[(int) $optionId])) {
                    $removed[$removals[(int) $optionId]] = true;
                }
            }

            $snapshot = [];

            foreach ($this->linesFor($product, $variant) as $line) {
                $ingredient = $line['ingredient'];
                if (! $ingredient->track_inventory) {
                    continue;
                }
                // A selected "no milk" option drops the optional milk line; a
                // required line is never dropped by a modifier.
                if ($line['optional'] && isset($removed[(int) $ingredient->id])) {
                    continue;
                }
                $id = (int) $ingredient->id;
                $consumed = bcmul($line['effective_quantity'], $quantity, 4);
                $needed[$id] = [
                    'ingredient' => $ingredient,
                    'quantity' => bcadd($needed[$id]['quantity'] ?? '0', $consumed, 4),
                ];

                $snapshot[] = [
                    'ingredient_id' => $id,
                    'ingredient_name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'quantity' => $line['effective_quantity'],
                    'unit_cost' => (string) $ingredient->cost_per_unit,
                    'line_cost' => $line['line_cost'],
                ];
            }

            // What was actually deducted, per unit. Refunds and historical
            // costs read this rather than the recipe as it looks today.
            $item->recipe_snapshot = $snapshot;
            $item->save();
        }

        foreach ($needed as $entry) {
            $ingredient = $entry['ingredient'];
            $quantity = $entry['quantity'];

            $balance = IngredientBalance::lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outletId, 'ingredient_id' => $ingredient->id],
                ['business_id' => $sale->business_id, 'quantity' => 0],
            );

            if (bccomp((string) $balance->quantity, $quantity, 4) < 0) {
                throw new \RuntimeException("Insufficient ingredient stock: {$ingredient->name}");
            }

            $balance->quantity = bcsub((string) $balance->quantity, $quantity, 4);
            $balance->save();

            IngredientMovement::create([
                'business_id' => $sale->business_id,
                'outlet_id' => $outletId,
                'ingredient_id' => $ingredient->id,
                'type' => 'consumption',
                'quantity' => bcmul($quantity, '-1', 4),
                'unit_cost' => $ingredient->cost_per_unit,
                'balance_after' => $balance->quantity,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'reason' => 'Sale '.$sale->receipt_number,
                'actor_id' => $actorId,
            ]);
        }
    }

    /**
     * Put back every ingredient the sale still holds (void, or a refund that
     * returns the whole sale).
     *
     * Reversal works off the *net* consumption left on the sale — consumption
     * movements plus the void movements already written against it — so a
     * partial refund followed by a full one restores exactly the remainder and
     * a void that runs twice cannot inflate stock.
     */
    public function reverseForSale(Sale $sale, ?string $reason = null, ?int $actorId = null): void
    {
        $remaining = $this->remainingConsumption($sale);

        foreach ($remaining as $entry) {
            $this->restore($sale, $entry, $entry['quantity'], $reason ?: 'Sale voided', $actorId);
        }
    }

    /**
     * Put back only the ingredients consumed by the returned sale lines.
     *
     * Used by item-level refunds. Quantities come from the recipe snapshot
     * written when the line was sold, so editing a recipe later (or serving
     * the line without an optional ingredient) does not change what a refund
     * puts back. The restore is capped at what the sale still holds per
     * ingredient, so it can never inflate stock.
     *
     * @param  array<int, string>  $quantitiesBySaleItemId  sale item id => returned quantity
     * @return array<int, string> ingredient id => quantity restored
     */
    public function reverseSaleItems(
        Sale $sale,
        array $quantitiesBySaleItemId,
        ?string $reason = null,
        ?int $actorId = null,
    ): array {
        $sale->loadMissing('items');

        $remaining = $this->remainingConsumption($sale);
        $needed = [];

        foreach ($sale->items as $item) {
            $returned = $quantitiesBySaleItemId[$item->id] ?? null;
            if ($returned === null || bccomp((string) $returned, '0', 4) <= 0) {
                continue;
            }

            $snapshot = $item->recipe_snapshot;

            if (is_array($snapshot)) {
                foreach ($snapshot as $line) {
                    $key = $this->balanceKey((int) $sale->outlet_id, (int) $line['ingredient_id']);
                    $needed[$key] = bcadd(
                        $needed[$key] ?? '0',
                        bcmul((string) $line['quantity'], (string) $returned, 4),
                        4,
                    );
                }

                continue;
            }

            // Sales written before snapshots existed fall back to the live
            // recipe, capped by what the sale still holds below.
            $product = $item->product_id ? Product::find($item->product_id) : null;
            if (! $product) {
                continue;
            }
            $variant = $item->product_variant_id ? ProductVariant::find($item->product_variant_id) : null;

            foreach ($this->linesFor($product, $variant) as $line) {
                $ingredient = $line['ingredient'];
                if (! $ingredient->track_inventory) {
                    continue;
                }
                $key = $this->balanceKey((int) $sale->outlet_id, (int) $ingredient->id);
                $needed[$key] = bcadd(
                    $needed[$key] ?? '0',
                    bcmul($line['effective_quantity'], (string) $returned, 4),
                    4,
                );
            }
        }

        $restored = [];

        foreach ($needed as $key => $quantity) {
            $entry = $remaining[$key] ?? null;
            if (! $entry) {
                continue;
            }

            // Never give back more than the sale still holds for this
            // ingredient, even if the recipe grew since it was sold.
            if (bccomp($quantity, $entry['quantity'], 4) > 0) {
                $quantity = $entry['quantity'];
            }
            if (bccomp($quantity, '0', 4) <= 0) {
                continue;
            }

            $this->restore($sale, $entry, $quantity, $reason ?: 'Refund', $actorId);
            $restored[(int) $entry['ingredient_id']] = $quantity;
        }

        return $restored;
    }

    /**
     * Net quantity still deducted from stock by this sale, per outlet/ingredient.
     *
     * Consumption movements are negative and reversal movements positive, so
     * the signed total inverted is what is still held.
     *
     * @return array<string, array{outlet_id: int, ingredient_id: int, unit_cost: string, quantity: string}>
     */
    private function remainingConsumption(Sale $sale): array
    {
        $rows = IngredientMovement::query()
            ->where('business_id', $sale->business_id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->whereIn('type', ['consumption', 'void'])
            ->orderBy('id')
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $key = $this->balanceKey((int) $row->outlet_id, (int) $row->ingredient_id);
            $entries[$key] ??= [
                'outlet_id' => (int) $row->outlet_id,
                'ingredient_id' => (int) $row->ingredient_id,
                'unit_cost' => (string) $row->unit_cost,
                'quantity' => '0',
            ];
            $entries[$key]['quantity'] = bcadd($entries[$key]['quantity'], (string) $row->quantity, 4);
        }

        foreach ($entries as $key => $entry) {
            $held = bcmul($entry['quantity'], '-1', 4);
            $entries[$key]['quantity'] = bccomp($held, '0', 4) > 0 ? $held : '0';
        }

        return $entries;
    }

    private function balanceKey(int $outletId, int $ingredientId): string
    {
        return $outletId.':'.$ingredientId;
    }

    /**
     * Modifier option id => the ingredient it removes, for the options
     * selected on a sale. Scoped to the business, so a foreign option id is
     * ignored instead of dropping someone else's ingredient.
     *
     * @param  array<int, int>  $optionIds
     * @return array<int, int>
     */
    private function ingredientRemovals(int $businessId, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_filter($optionIds)));

        if ($optionIds === []) {
            return [];
        }

        return ModifierOption::query()
            ->where('business_id', $businessId)
            ->whereIn('id', $optionIds)
            ->whereNotNull('removes_ingredient_id')
            ->pluck('removes_ingredient_id', 'id')
            ->map(fn ($ingredientId) => (int) $ingredientId)
            ->all();
    }

    /**
     * @param  array{outlet_id: int, ingredient_id: int, unit_cost: string}  $entry
     */
    private function restore(Sale $sale, array $entry, string $quantity, string $reason, ?int $actorId): void
    {
        $balance = IngredientBalance::lockForUpdate()->firstOrCreate(
            ['outlet_id' => $entry['outlet_id'], 'ingredient_id' => $entry['ingredient_id']],
            ['business_id' => $sale->business_id, 'quantity' => 0],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 4);
        $balance->save();

        IngredientMovement::create([
            'business_id' => $sale->business_id,
            'outlet_id' => $entry['outlet_id'],
            'ingredient_id' => $entry['ingredient_id'],
            'type' => 'void',
            'quantity' => $quantity,
            'unit_cost' => $entry['unit_cost'],
            'balance_after' => $balance->quantity,
            'reference_type' => 'sale',
            'reference_id' => $sale->id,
            'reason' => $reason,
            'actor_id' => $actorId,
        ]);
    }

    /**
     * @return array{ingredient: Ingredient, quantity: string, effective_quantity: string, waste_percent: string, line_cost: string, item: RecipeItem}
     */
    private function line(RecipeItem $item): array
    {
        $ingredient = $item->ingredient;
        $quantity = (string) $item->quantity;
        $waste = (string) $item->waste_percent;
        $factor = bcadd('1', bcdiv($waste, '100', 8), 8);

        // Recipe quantities may be written in their own unit (18 g against an
        // ingredient stocked in kg); everything downstream works in the
        // ingredient's stocking unit.
        $recipeUnit = $this->recipeUnit($item);
        $inIngredientUnit = $this->units->convert(
            bcmul($quantity, $factor, 8),
            $recipeUnit,
            $ingredient->unit,
        );
        $effective = bcadd($inIngredientUnit, '0', 4);
        $lineCost = bcmul($effective, (string) $ingredient->cost_per_unit, 4);

        return [
            'ingredient' => $ingredient,
            'quantity' => $quantity,
            'unit' => $recipeUnit,
            'effective_quantity' => $effective,
            'waste_percent' => $waste,
            'optional' => (bool) $item->optional,
            'line_cost' => $lineCost,
            'item' => $item,
        ];
    }

    private function recipeUnit(RecipeItem $item): ?string
    {
        return $this->units->normalise($item->unit) ?? $item->ingredient?->unit;
    }

    private function serializeLine(RecipeItem $item): array
    {
        $ingredient = $item->ingredient;
        $quantity = (string) $item->quantity;
        $waste = (string) $item->waste_percent;
        $recipeUnit = $this->recipeUnit($item);
        $effective = $ingredient
            ? $this->units->convert(bcmul($quantity, bcadd('1', bcdiv($waste, '100', 8), 8), 8), $recipeUnit, $ingredient->unit)
            : '0';
        $effective = bcadd($effective, '0', 4);
        $unitCost = (string) ($ingredient?->cost_per_unit ?? '0');

        return [
            'id' => (string) $item->id,
            'product_variant_id' => $item->product_variant_id ? (string) $item->product_variant_id : null,
            'ingredient_id' => (string) $item->ingredient_id,
            'ingredient_name' => $ingredient?->name,
            'unit' => $recipeUnit,
            'ingredient_unit' => $ingredient?->unit,
            'unit_cost' => $unitCost,
            'quantity' => $quantity,
            'waste_percent' => $waste,
            'optional' => (bool) $item->optional,
            'effective_quantity' => $effective,
            'line_cost' => bcmul($effective, $unitCost, 4),
            'notes' => $item->notes,
        ];
    }

    /**
     * Replace recipe lines for one scope.
     *
     * Without a variant id the product-level lines are replaced; with a variant
     * id only that variant's override lines change.
     *
     * @param  array<int, array{ingredient_id: int, quantity: string|float|int, unit?: string|null, waste_percent?: string|float|int|null, optional?: bool, notes?: string|null}>  $items
     */
    public function syncRecipe(Product $product, ?ProductVariant $variant, array $items): void
    {
        // Validate every line first: a rejected unit must not wipe the recipe
        // that is currently saved.
        $resolved = [];
        foreach ($items as $item) {
            $ingredient = Ingredient::where('id', $item['ingredient_id'])
                ->where('business_id', $product->business_id)
                ->first();

            if (! $ingredient) {
                throw new InvalidArgumentException('Ingredient not found: '.$item['ingredient_id']);
            }

            $unit = $this->units->normalise($item['unit'] ?? null);
            if ($unit !== null && ! $this->units->compatible($unit, $ingredient->unit)) {
                throw new InvalidArgumentException(
                    "Recipe unit {$unit} cannot be converted to {$ingredient->unit} for {$ingredient->name}"
                );
            }

            $resolved[] = [
                'ingredient_id' => $item['ingredient_id'],
                'quantity' => $item['quantity'],
                'unit' => $unit,
                'waste_percent' => $item['waste_percent'] ?? 0,
                'optional' => (bool) ($item['optional'] ?? false),
                'notes' => $item['notes'] ?? null,
            ];
        }

        RecipeItem::query()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->when($variant, fn ($query) => $query->where('product_variant_id', $variant->id))
            ->when(! $variant, fn ($query) => $query->whereNull('product_variant_id'))
            ->delete();

        foreach ($resolved as $item) {
            RecipeItem::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                ...$item,
            ]);
        }
    }

    /**
     * How many units of a product can still be produced from the outlet's
     * ingredient stock, and which ingredient is the bottleneck.
     *
     * soldable = floor(min(stock / quantity per unit)) across tracked lines.
     *
     * @return array{sellable: int|null, unlimited: bool, status: string, limiting: array<string, mixed>|null, lines: array<int, array<string, mixed>>}
     */
    public function availabilityFor(Product $product, int $outletId, ?ProductVariant $variant = null): array
    {
        $balances = IngredientBalance::query()
            ->where('business_id', $product->business_id)
            ->where('outlet_id', $outletId)
            ->pluck('quantity', 'ingredient_id');

        $sellable = null;
        $limiting = null;
        $rows = [];

        foreach ($this->linesFor($product, $variant) as $line) {
            $ingredient = $line['ingredient'];
            $perUnit = $line['effective_quantity'];

            if (! $ingredient->track_inventory || bccomp((string) $perUnit, '0', 4) <= 0) {
                continue;
            }

            $available = (string) ($balances[$ingredient->id] ?? '0');
            $possible = (int) bcdiv($available, (string) $perUnit, 0);

            $row = [
                'ingredient_id' => (string) $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'unit' => $ingredient->unit,
                'available' => bcadd($available, '0', 4),
                'required_per_unit' => (string) $perUnit,
                'reorder_level' => (string) $ingredient->reorder_level,
                'safety_stock' => (string) $ingredient->safety_stock,
                'critical' => $ingredient->isCritical($available),
                'possible' => $possible,
            ];
            $rows[] = $row;

            if ($sellable === null || $possible < $sellable) {
                $sellable = $possible;
                $limiting = $row;
            }
        }

        $unlimited = $sellable === null;
        $status = 'available';
        if (! $unlimited) {
            if ($sellable <= 0) {
                $status = 'out_of_stock';
            } elseif ($sellable <= 5 || ($limiting && bccomp((string) $limiting['available'], (string) $limiting['reorder_level'], 4) <= 0)) {
                $status = 'low_stock';
            }
        }

        return [
            'sellable' => $sellable,
            'unlimited' => $unlimited,
            'status' => $status,
            'limiting' => $limiting,
            'lines' => $rows,
        ];
    }

    /**
     * Reverse lookup: every ingredient with the products that consume it, the
     * per-unit quantity in the ingredient's stocking unit, and how many units
     * of each product the current balance still allows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ingredientUsage(int $businessId, int $outletId, ?int $ingredientId = null): array
    {
        $ingredients = Ingredient::query()
            ->where('business_id', $businessId)
            ->when($ingredientId, fn ($query) => $query->where('id', $ingredientId))
            ->orderBy('name')
            ->get();

        $balances = IngredientBalance::query()
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->pluck('quantity', 'ingredient_id');

        $items = RecipeItem::query()
            ->where('business_id', $businessId)
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->with(['product', 'ingredient'])
            ->get()
            ->groupBy('ingredient_id');

        $movements = IngredientMovement::query()
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->get(['ingredient_id', 'type', 'quantity'])
            ->groupBy('ingredient_id');

        return $ingredients->map(function (Ingredient $ingredient) use ($balances, $items, $movements) {
            $available = (string) ($balances[$ingredient->id] ?? '0');

            $products = collect($items[$ingredient->id] ?? [])->map(function (RecipeItem $item) use ($ingredient, $available) {
                $recipeUnit = $this->recipeUnit($item);
                $factor = bcadd('1', bcdiv((string) $item->waste_percent, '100', 8), 8);
                $perUnit = $this->units->convert(
                    bcmul((string) $item->quantity, $factor, 8),
                    $recipeUnit,
                    $ingredient->unit,
                );
                $perUnit = bcadd($perUnit, '0', 4);

                return [
                    'product_id' => (string) $item->product_id,
                    'product_name' => $item->product?->name,
                    'product_variant_id' => $item->product_variant_id ? (string) $item->product_variant_id : null,
                    'quantity' => (string) $item->quantity,
                    'unit' => $recipeUnit,
                    'quantity_in_ingredient_unit' => $perUnit,
                    'producible' => bccomp($perUnit, '0', 4) > 0 ? (int) bcdiv($available, $perUnit, 0) : null,
                ];
            })->values()->all();

            $consumed = '0';
            $received = '0';
            foreach ($movements[$ingredient->id] ?? [] as $movement) {
                $quantity = (string) $movement->quantity;
                if (in_array($movement->type, ['consumption', 'wastage', 'expired'], true) && bccomp($quantity, '0', 4) < 0) {
                    $consumed = bcsub($consumed, $quantity, 4);
                }
                if ($movement->type === 'purchase' && bccomp($quantity, '0', 4) > 0) {
                    $received = bcadd($received, $quantity, 4);
                }
            }

            return [
                'ingredient_id' => (string) $ingredient->id,
                'name' => $ingredient->name,
                'sku' => $ingredient->sku,
                'unit' => $ingredient->unit,
                'remaining' => bcadd($available, '0', 4),
                'consumed' => bcadd($consumed, '0', 4),
                'received' => bcadd($received, '0', 4),
                'reorder_level' => (string) $ingredient->reorder_level,
                'safety_stock' => (string) $ingredient->safety_stock,
                'low_stock' => bccomp($available, (string) $ingredient->reorder_level, 4) <= 0,
                'critical' => $ingredient->isCritical($available),
                'track_inventory' => (bool) $ingredient->track_inventory,
                'products' => $products,
            ];
        })->values()->all();
    }
}

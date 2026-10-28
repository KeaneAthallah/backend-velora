<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Sale;

class RecipeService
{
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

        foreach ($sale->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $item->product_variant_id ? ProductVariant::find($item->product_variant_id) : null;
            $quantity = (string) $item->quantity;

            foreach ($this->linesFor($product, $variant) as $line) {
                $ingredient = $line['ingredient'];
                if (! $ingredient->track_inventory) {
                    continue;
                }
                $id = (int) $ingredient->id;
                $consumed = bcmul($line['effective_quantity'], $quantity, 4);
                $needed[$id] = [
                    'ingredient' => $ingredient,
                    'quantity' => bcadd($needed[$id]['quantity'] ?? '0', $consumed, 4),
                ];
            }
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
     * Put back every ingredient deducted by the sale (void).
     */
    public function reverseForSale(Sale $sale, ?string $reason = null, ?int $actorId = null): void
    {
        $movements = IngredientMovement::query()
            ->where('business_id', $sale->business_id)
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale->id)
            ->where('type', 'consumption')
            ->get();

        foreach ($movements as $movement) {
            $quantity = bcmul((string) $movement->quantity, '-1', 4);
            if (bccomp($quantity, '0', 4) <= 0) {
                continue;
            }

            $balance = IngredientBalance::lockForUpdate()->firstOrCreate(
                ['outlet_id' => $movement->outlet_id, 'ingredient_id' => $movement->ingredient_id],
                ['business_id' => $sale->business_id, 'quantity' => 0],
            );

            $balance->quantity = bcadd((string) $balance->quantity, $quantity, 4);
            $balance->save();

            IngredientMovement::create([
                'business_id' => $sale->business_id,
                'outlet_id' => $movement->outlet_id,
                'ingredient_id' => $movement->ingredient_id,
                'type' => 'void',
                'quantity' => $quantity,
                'unit_cost' => $movement->unit_cost,
                'balance_after' => $balance->quantity,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'reason' => $reason ?: 'Sale voided',
                'actor_id' => $actorId,
            ]);
        }
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
        $effective = bcmul($quantity, $factor, 4);
        $lineCost = bcmul($effective, (string) $ingredient->cost_per_unit, 4);

        return [
            'ingredient' => $ingredient,
            'quantity' => $quantity,
            'effective_quantity' => $effective,
            'waste_percent' => $waste,
            'line_cost' => $lineCost,
            'item' => $item,
        ];
    }

    private function serializeLine(RecipeItem $item): array
    {
        $ingredient = $item->ingredient;
        $quantity = (string) $item->quantity;
        $waste = (string) $item->waste_percent;
        $effective = bcmul($quantity, bcadd('1', bcdiv($waste, '100', 8), 8), 4);
        $unitCost = (string) ($ingredient?->cost_per_unit ?? '0');

        return [
            'id' => (string) $item->id,
            'product_variant_id' => $item->product_variant_id ? (string) $item->product_variant_id : null,
            'ingredient_id' => (string) $item->ingredient_id,
            'ingredient_name' => $ingredient?->name,
            'unit' => $ingredient?->unit,
            'unit_cost' => $unitCost,
            'quantity' => $quantity,
            'waste_percent' => $waste,
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
     * @param  array<int, array{ingredient_id: int, quantity: string|float|int, waste_percent?: string|float|int|null, notes?: string|null}>  $items
     */
    public function syncRecipe(Product $product, ?ProductVariant $variant, array $items): void
    {
        RecipeItem::query()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->when($variant, fn ($query) => $query->where('product_variant_id', $variant->id))
            ->when(! $variant, fn ($query) => $query->whereNull('product_variant_id'))
            ->delete();

        foreach ($items as $item) {
            RecipeItem::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'ingredient_id' => $item['ingredient_id'],
                'quantity' => $item['quantity'],
                'waste_percent' => $item['waste_percent'] ?? 0,
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}

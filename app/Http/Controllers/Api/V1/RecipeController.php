<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuthorizationService;
use App\Services\RecipeService;
use Illuminate\Http\Request;

class RecipeController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected RecipeService $recipes,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'recipes.view', $businessId);

        $query = Product::query()
            ->where('business_id', $businessId)
            ->with(['recipeItems.ingredient', 'variants'])
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            });
        }

        $products = $query->get();

        if ($request->boolean('with_recipe')) {
            $products = $products->filter(fn (Product $product) => $product->recipeItems->isNotEmpty());
        }

        return $this->success($products->map(fn (Product $product) => $this->recipes->productPayload($product))->values());
    }

    public function show(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'recipes.view', $businessId);

        return $this->success($this->recipes->productPayload($product));
    }

    /**
     * Sellable counts for every product that has a recipe, in one call — the
     * register uses this to grey out or cap items without a request per tile.
     */
    public function availabilityIndex(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        // The register needs this to grey out tiles, so it rides on sales.view
        // rather than recipes.view (cashiers rarely hold the latter).
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);

        $data = $request->validate(['outlet_id' => 'nullable|integer']);
        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $products = Product::query()
            ->where('business_id', $businessId)
            ->whereHas('recipeItems')
            ->orderBy('name')
            // business_id is needed: availabilityFor() scopes its recipe lines.
            ->get(['id', 'name', 'business_id']);

        $rows = $products->map(function (Product $product) use ($outletId) {
            $availability = $this->recipes->availabilityFor($product, (int) $outletId);

            return [
                'product_id' => (string) $product->id,
                'product_name' => $product->name,
                'sellable' => $availability['sellable'],
                'unlimited' => $availability['unlimited'],
                'status' => $availability['status'],
                'limiting' => $availability['limiting'],
            ];
        });

        return $this->success($rows);
    }

    /**
     * Maximum sellable units for a product, from the outlet's ingredient
     * balances, plus the ingredient that limits production.
     */
    public function availability(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'recipes.view', $businessId);

        $data = $request->validate(['outlet_id' => 'nullable|integer', 'product_variant_id' => 'nullable|integer']);

        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $variant = null;
        if (! empty($data['product_variant_id'])) {
            $variant = ProductVariant::where('id', $data['product_variant_id'])
                ->where('product_id', $product->id)
                ->first();
            if (! $variant) {
                return $this->error('Variant not found', 404);
            }
        }

        return $this->success($this->recipes->availabilityFor($product, (int) $outletId, $variant));
    }

    public function sync(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'recipes.manage', $businessId);

        $data = $request->validate([
            'product_variant_id' => 'nullable|integer',
            'items' => 'present|array',
            'items.*.ingredient_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit' => 'nullable|string|max:32',
            'items.*.waste_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        $variant = null;
        if (! empty($data['product_variant_id'])) {
            $variant = ProductVariant::where('id', $data['product_variant_id'])
                ->where('product_id', $product->id)
                ->first();
            if (! $variant) {
                return $this->error('Variant not found', 404);
            }
        }

        $ingredientIds = collect($data['items'])->pluck('ingredient_id')->map(fn ($id) => (int) $id)->unique();
        $valid = Ingredient::where('business_id', $businessId)
            ->whereIn('id', $ingredientIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($valid) !== $ingredientIds->count()) {
            return $this->error('One or more ingredients were not found', 404);
        }

        $duplicates = collect($data['items'])
            ->map(fn ($item) => (int) $item['ingredient_id'])
            ->duplicates();

        if ($duplicates->isNotEmpty()) {
            return $this->error('Each ingredient can appear once per recipe scope', 422);
        }

        $items = collect($data['items'])->map(fn ($item) => [
            'ingredient_id' => (int) $item['ingredient_id'],
            'quantity' => $item['quantity'],
            'unit' => $item['unit'] ?? null,
            'waste_percent' => $item['waste_percent'] ?? 0,
            'notes' => $item['notes'] ?? null,
        ])->all();

        try {
            $this->recipes->syncRecipe($product, $variant, $items);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, [], 'RECIPE_ERROR');
        }

        $product->unsetRelation('recipeItems');

        return $this->success($this->recipes->productPayload($product), 'Recipe saved');
    }
}

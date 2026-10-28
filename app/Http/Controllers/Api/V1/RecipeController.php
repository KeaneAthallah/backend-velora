<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
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
            'waste_percent' => $item['waste_percent'] ?? 0,
            'notes' => $item['notes'] ?? null,
        ])->all();

        $this->recipes->syncRecipe($product, $variant, $items);

        $product->unsetRelation('recipeItems');

        return $this->success($this->recipes->productPayload($product), 'Recipe saved');
    }
}

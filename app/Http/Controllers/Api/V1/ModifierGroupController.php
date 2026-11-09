<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ModifierGroupController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.view', $businessId);

        $query = ModifierGroup::where('business_id', $businessId)->with('options');

        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }
        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return $this->success($query->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => 'nullable|in:modifier,combo',
            'selection_type' => 'nullable|in:single,multiple',
            'min_select' => 'nullable|integer|min:0',
            'max_select' => 'nullable|integer|min:0',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:255',
            'options.*.price_delta' => 'nullable|numeric',
            'options.*.is_default' => 'nullable|boolean',
            'options.*.product_id' => 'nullable|integer',
            'options.*.product_variant_id' => 'nullable|integer',
            'options.*.removes_ingredient_id' => 'nullable|integer',
        ]);

        $data = $this->normalizeGroup($data);
        $options = $data['options'] ?? [];
        unset($data['options']);

        $group = ModifierGroup::create($data + ['business_id' => $businessId]);

        foreach ($options as $option) {
            $this->createOption($group, $option, $businessId);
        }

        return $this->success($group->load('options'), 'Modifier group created', 201);
    }

    public function show(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.view', $businessId);

        return $this->success($modifierGroup->load('options', 'products', 'categories'));
    }

    public function update(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'kind' => 'sometimes|in:modifier,combo',
            'selection_type' => 'sometimes|in:single,multiple',
            'min_select' => 'sometimes|integer|min:0',
            'max_select' => 'sometimes|integer|min:0',
            'is_required' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $modifierGroup->update($this->normalizeGroupUpdate($data));

        return $this->success($modifierGroup->load('options'), 'Modifier group updated');
    }

    public function destroy(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $modifierGroup->options()->delete();
        $modifierGroup->products()->detach();
        $modifierGroup->categories()->detach();
        $modifierGroup->delete();

        return $this->success(null, 'Modifier group deleted');
    }

    public function attach(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'assignable_type' => 'required|in:product,category',
            'assignable_id' => 'required|integer',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($data['assignable_type'] === 'product') {
            $target = Product::where('id', $data['assignable_id'])->where('business_id', $businessId)->first();
            if (! $target) {
                return $this->error('Not found', 404);
            }
            $modifierGroup->products()->syncWithoutDetaching([
                $target->id => ['business_id' => $businessId, 'sort_order' => $data['sort_order'] ?? 0],
            ]);
        } else {
            $target = Category::where('id', $data['assignable_id'])->where('business_id', $businessId)->first();
            if (! $target) {
                return $this->error('Not found', 404);
            }
            $modifierGroup->categories()->syncWithoutDetaching([
                $target->id => ['business_id' => $businessId, 'sort_order' => $data['sort_order'] ?? 0],
            ]);
        }

        return $this->success($modifierGroup->load('options'), 'Modifier group attached');
    }

    public function detach(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'assignable_type' => 'required|in:product,category',
            'assignable_id' => 'required|integer',
        ]);

        if ($data['assignable_type'] === 'product') {
            $modifierGroup->products()->detach($data['assignable_id']);
        } else {
            $modifierGroup->categories()->detach($data['assignable_id']);
        }

        return $this->success(null, 'Modifier group detached');
    }

    public function options(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.view', $businessId);

        return $this->success($modifierGroup->options()->get());
    }

    public function storeOption(Request $request, ModifierGroup $modifierGroup)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierGroup->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price_delta' => 'nullable|numeric',
            'is_default' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'product_id' => 'nullable|integer',
            'product_variant_id' => 'nullable|integer',
            'removes_ingredient_id' => 'nullable|integer',
        ]);

        $option = $this->createOption($modifierGroup, $data, $businessId);

        return $this->success($option, 'Modifier option created', 201);
    }

    public function updateOption(Request $request, ModifierOption $modifierOption)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierOption->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price_delta' => 'sometimes|numeric',
            'is_default' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'product_id' => 'sometimes|nullable|integer',
            'product_variant_id' => 'sometimes|nullable|integer',
            'removes_ingredient_id' => 'sometimes|nullable|integer',
        ]);

        $this->validateOptionProduct($data, $businessId);

        if (! empty($data['is_default']) && $modifierOption->group?->selection_type === 'single') {
            ModifierOption::where('modifier_group_id', $modifierOption->modifier_group_id)
                ->where('id', '!=', $modifierOption->id)
                ->update(['is_default' => false]);
        }

        $modifierOption->update($data);

        return $this->success($modifierOption, 'Modifier option updated');
    }

    public function destroyOption(Request $request, ModifierOption $modifierOption)
    {
        $businessId = $request->attributes->get('business_id');
        if ($modifierOption->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.manage', $businessId);

        $modifierOption->delete();

        return $this->success(null, 'Modifier option deleted');
    }

    private function createOption(ModifierGroup $group, array $data, int $businessId): ModifierOption
    {
        $this->validateOptionProduct($data, $businessId);

        if (! empty($data['is_default']) && $group->selection_type === 'single') {
            ModifierOption::where('modifier_group_id', $group->id)->update(['is_default' => false]);
        }

        return ModifierOption::create([
            'business_id' => $businessId,
            'modifier_group_id' => $group->id,
            'name' => $data['name'],
            'price_delta' => $data['price_delta'] ?? 0,
            'is_default' => $data['is_default'] ?? false,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'product_id' => $data['product_id'] ?? null,
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'removes_ingredient_id' => $data['removes_ingredient_id'] ?? null,
        ]);
    }

    private function validateOptionProduct(array $data, int $businessId): void
    {
        if (! empty($data['product_id'])) {
            $exists = Product::where('id', $data['product_id'])->where('business_id', $businessId)->exists();
            if (! $exists) {
                abort(404, 'Product not found');
            }
        }

        if (! empty($data['product_variant_id'])) {
            $variant = ProductVariant::where('id', $data['product_variant_id'])
                ->whereIn('product_id', Product::where('business_id', $businessId)->select('id'))
                ->first();
            if (! $variant) {
                abort(404, 'Product variant not found');
            }
        }

        if (! empty($data['removes_ingredient_id'])) {
            $exists = Ingredient::where('id', $data['removes_ingredient_id'])
                ->where('business_id', $businessId)
                ->exists();
            if (! $exists) {
                abort(404, 'Ingredient not found');
            }
        }
    }

    private function normalizeGroup(array $data): array
    {
        $data['kind'] = $data['kind'] ?? 'modifier';
        $data['selection_type'] = $data['selection_type'] ?? 'single';
        $data['is_required'] = $data['is_required'] ?? false;
        $data['min_select'] = $data['min_select'] ?? 0;
        $data['max_select'] = $data['max_select'] ?? 1;

        if ($data['selection_type'] === 'single') {
            $data['max_select'] = 1;
        }
        if ($data['is_required'] && $data['min_select'] < 1) {
            $data['min_select'] = 1;
        }
        if ($data['max_select'] > 0 && $data['min_select'] > $data['max_select']) {
            $data['min_select'] = $data['max_select'];
        }

        return $data;
    }

    private function normalizeGroupUpdate(array $data): array
    {
        if (($data['selection_type'] ?? null) === 'single') {
            $data['max_select'] = 1;
        }
        if (isset($data['min_select'], $data['max_select']) && $data['max_select'] > 0 && $data['min_select'] > $data['max_select']) {
            $data['min_select'] = $data['max_select'];
        }

        return $data;
    }
}

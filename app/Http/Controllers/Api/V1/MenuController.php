<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Daypart;
use App\Models\MenuAvailability;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuthorizationService;
use App\Services\MenuService;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected MenuService $menuService,
    ) {}

    public function menu(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.view', $businessId);

        $data = $request->validate(['outlet_id' => 'required|integer']);
        $outlet = Outlet::where('id', $data['outlet_id'])->where('business_id', $businessId)->first();
        if (! $outlet) {
            return $this->error('Not found', 404);
        }

        return $this->success([
            'outlet' => ['id' => (string) $outlet->id, 'name' => $outlet->name],
            'generated_at' => now()->toIso8601String(),
            'products' => $this->menuService->menu($businessId, $outlet->id),
        ]);
    }

    public function availability(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.availability', $businessId);

        $data = $request->validate(['outlet_id' => 'required|integer']);
        $outlet = Outlet::where('id', $data['outlet_id'])->where('business_id', $businessId)->first();
        if (! $outlet) {
            return $this->error('Not found', 404);
        }

        $rows = MenuAvailability::where('business_id', $businessId)
            ->where(function ($query) use ($outlet) {
                $query->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id);
            })
            ->orderBy('id')
            ->get();

        return $this->success($rows);
    }

    public function updateAvailability(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.availability', $businessId);

        $data = $request->validate([
            'outlet_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.modifier_option_id' => 'nullable|integer',
            'items.*.is_available' => 'required|boolean',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $outlet = Outlet::where('id', $data['outlet_id'])->where('business_id', $businessId)->first();
        if (! $outlet) {
            return $this->error('Not found', 404);
        }

        $saved = [];
        foreach ($data['items'] as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : null;
            $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;
            $optionId = isset($item['modifier_option_id']) ? (int) $item['modifier_option_id'] : null;

            if (! $productId && ! $optionId) {
                return $this->error('Each availability row needs a product or modifier option', 422);
            }

            if ($productId) {
                $product = Product::where('id', $productId)->where('business_id', $businessId)->first();
                if (! $product) {
                    return $this->error('Not found', 404);
                }
                if ($variantId) {
                    $variant = ProductVariant::where('id', $variantId)->where('product_id', $product->id)->first();
                    if (! $variant) {
                        return $this->error('Not found', 404);
                    }
                }
            }

            if ($optionId) {
                $option = ModifierOption::where('id', $optionId)->where('business_id', $businessId)->first();
                if (! $option) {
                    return $this->error('Not found', 404);
                }
            }

            $saved[] = MenuAvailability::updateOrCreate(
                [
                    'business_id' => $businessId,
                    'outlet_id' => $outlet->id,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'modifier_option_id' => $optionId,
                ],
                [
                    'is_available' => $item['is_available'],
                    'reason' => $item['reason'] ?? null,
                ],
            );
        }

        return $this->success($saved, 'Menu availability updated');
    }

    public function markUnavailable(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.availability', $businessId);

        $data = $request->validate([
            'outlet_id' => 'nullable|integer',
            'reason' => 'required|string|max:255',
        ]);

        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $row = MenuAvailability::updateOrCreate(
            [
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'product_id' => $product->id,
                'product_variant_id' => null,
                'modifier_option_id' => null,
            ],
            ['is_available' => false, 'reason' => $data['reason']],
        );

        return $this->success($row, 'Item marked unavailable');
    }

    public function restore(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.availability', $businessId);

        $data = $request->validate(['outlet_id' => 'nullable|integer']);
        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        MenuAvailability::where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->delete();

        return $this->success(null, 'Item restored');
    }

    public function dayparts(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.view', $businessId);

        return $this->success(Daypart::where('business_id', $businessId)->withCount('products')->orderBy('start_time')->get());
    }

    public function storeDaypart(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'menu.dayparts', $businessId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'days' => 'nullable|array',
            'days.*' => 'integer|between:0,6',
            'is_active' => 'nullable|boolean',
        ]);

        $daypart = Daypart::create($data + ['business_id' => $businessId, 'is_active' => $data['is_active'] ?? true]);

        return $this->success($daypart, 'Daypart created', 201);
    }

    public function updateDaypart(Request $request, Daypart $daypart)
    {
        $businessId = $request->attributes->get('business_id');
        if ($daypart->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.dayparts', $businessId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'days' => 'sometimes|nullable|array',
            'days.*' => 'integer|between:0,6',
            'is_active' => 'sometimes|boolean',
        ]);

        $daypart->update($data);

        return $this->success($daypart->loadCount('products'), 'Daypart updated');
    }

    public function destroyDaypart(Request $request, Daypart $daypart)
    {
        $businessId = $request->attributes->get('business_id');
        if ($daypart->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.dayparts', $businessId);

        $daypart->products()->detach();
        $daypart->delete();

        return $this->success(null, 'Daypart deleted');
    }

    public function productDayparts(Request $request, Product $product)
    {
        $businessId = $request->attributes->get('business_id');
        if ($product->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'menu.dayparts', $businessId);

        $data = $request->validate([
            'daypart_ids' => 'required|array',
            'daypart_ids.*' => 'integer',
        ]);

        $validIds = Daypart::where('business_id', $businessId)
            ->whereIn('id', $data['daypart_ids'])
            ->pluck('id')
            ->all();

        if (count($validIds) !== count(array_unique($data['daypart_ids']))) {
            return $this->error('One or more dayparts were not found', 404);
        }

        $product->dayparts()->sync($validIds);

        return $this->success($product->load('dayparts'), 'Product dayparts updated');
    }
}

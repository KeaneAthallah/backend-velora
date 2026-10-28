<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Category;
use App\Models\MenuAvailability;
use App\Models\ModifierGroup;
use App\Models\OutletProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\CarbonInterface;

class MenuService
{
    /**
     * Groups attached directly to the product plus groups attached to its category.
     */
    public function groupsForProduct(Product $product, int $outletId, ?CarbonInterface $at = null): array
    {
        $groups = $product->modifierGroups()->with('options')->get();

        if ($product->category_id) {
            $categoryGroups = ModifierGroup::query()
                ->where('business_id', $product->business_id)
                ->whereIn('id', function ($query) use ($product) {
                    $query->select('modifier_group_id')
                        ->from('modifier_group_assignments')
                        ->where('assignable_type', Category::class)
                        ->where('assignable_id', $product->category_id);
                })
                ->with('options')
                ->get();
            $groups = $groups->merge($categoryGroups);
        }

        return $groups
            ->unique('id')
            ->filter(fn (ModifierGroup $group) => $group->is_active)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn (ModifierGroup $group) => $this->serializeGroup($group, $outletId, $at))
            ->values()
            ->all();
    }

    public function productAvailability(Product $product, int $outletId, ?ProductVariant $variant = null, ?CarbonInterface $at = null): array
    {
        if (! $product->is_active) {
            return ['available' => false, 'reason' => 'Inactive'];
        }

        $override = $this->override($product->business_id, $outletId, $product->id, null, null);
        if ($override && ! $override->is_available) {
            return ['available' => false, 'reason' => $override->reason ?: 'Sold out'];
        }

        if ($variant) {
            $variantOverride = $this->override($product->business_id, $outletId, $product->id, null, $variant->id);
            if ($variantOverride && ! $variantOverride->is_available) {
                return ['available' => false, 'reason' => $variantOverride->reason ?: 'Sold out'];
            }
        }

        if (! $this->withinDaypart($product, $at)) {
            return ['available' => false, 'reason' => 'Outside daypart'];
        }

        return ['available' => true, 'reason' => null];
    }

    /**
     * Resolve selected option ids into a priced, validated snapshot.
     *
     * @return array{delta: string, modifiers: array, combo: array}
     */
    public function resolveItem(
        Product $product,
        ?ProductVariant $variant,
        array $optionIds,
        int $businessId,
        int $outletId,
        ?CarbonInterface $at = null,
    ): array {
        $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        $groups = $this->groupsForProduct($product, $outletId, $at);

        $allOptions = collect($groups)->flatMap(fn (array $group) => $group['options']);
        $byId = $allOptions->keyBy('id');

        foreach ($optionIds as $optionId) {
            if (! $byId->has($optionId)) {
                throw new \RuntimeException('Modifier option does not belong to this product');
            }
        }

        $delta = '0';
        $snapshot = [];
        $combo = [];

        foreach ($groups as $group) {
            $selected = collect($group['options'])->whereIn('id', $optionIds)->values();

            if ($selected->isEmpty() && $group['is_required']) {
                $selected = collect($group['options'])->where('is_default', true)->values();
            }

            if ($group['is_required'] && $selected->isEmpty()) {
                throw new \RuntimeException("Required choice missing: {$group['name']}");
            }

            if ($selected->count() < $group['min_select']) {
                throw new \RuntimeException("Select at least {$group['min_select']} in {$group['name']}");
            }

            if ($group['max_select'] > 0 && $selected->count() > $group['max_select']) {
                throw new \RuntimeException("Select at most {$group['max_select']} in {$group['name']}");
            }

            if ($group['selection_type'] === 'single' && $selected->count() > 1) {
                throw new \RuntimeException("Only one choice is allowed in {$group['name']}");
            }

            foreach ($selected as $option) {
                if (! $option['available']) {
                    throw new \RuntimeException("Unavailable option: {$option['name']}");
                }

                $delta = bcadd($delta, (string) $option['price_delta'], 4);

                $snapshot[] = [
                    'group_id' => $group['id'],
                    'group_name' => $group['name'],
                    'option_id' => $option['id'],
                    'option_name' => $option['name'],
                    'price_delta' => (string) $option['price_delta'],
                    'product_id' => $option['product_id'],
                    'product_variant_id' => $option['product_variant_id'],
                ];

                if ($option['product_id']) {
                    $combo[] = [
                        'group_id' => $group['id'],
                        'option_id' => $option['id'],
                        'name' => $option['name'],
                        'product_id' => $option['product_id'],
                        'product_variant_id' => $option['product_variant_id'],
                    ];
                }
            }
        }

        return ['delta' => $delta, 'modifiers' => $snapshot, 'combo' => $combo];
    }

    public function basePrice(Product $product, ?ProductVariant $variant, int $outletId): string
    {
        $price = OutletProductPrice::query()
            ->where('outlet_id', $outletId)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->value('selling_price');

        if ($price !== null) {
            return bcadd((string) $price, '0', 4);
        }

        if ($variant && $variant->selling_price !== null) {
            return bcadd((string) $variant->selling_price, '0', 4);
        }

        return bcadd((string) $product->selling_price, '0', 4);
    }

    public function menu(int $businessId, int $outletId, ?CarbonInterface $at = null): array
    {
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->with(['category', 'variants', 'dayparts'])
            ->orderBy('name')
            ->get();

        return $products->map(function (Product $product) use ($outletId, $at) {
            $availability = $this->productAvailability($product, $outletId, null, $at);

            return array_merge($product->toArray(), [
                'available' => $availability['available'],
                'unavailable_reason' => $availability['reason'],
                'daypart_ids' => $product->dayparts->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'modifier_groups' => $this->groupsForProduct($product, $outletId, $at),
            ]);
        })->values()->all();
    }

    private function serializeGroup(ModifierGroup $group, int $outletId, ?CarbonInterface $at): array
    {
        $options = $group->options
            ->filter(fn ($option) => $option->is_active)
            ->map(function ($option) use ($outletId) {
                $override = $this->override(
                    $option->business_id,
                    $outletId,
                    null,
                    $option->id,
                    null,
                );

                return [
                    'id' => (string) $option->id,
                    'modifier_group_id' => (string) $option->modifier_group_id,
                    'name' => $option->name,
                    'price_delta' => (string) $option->price_delta,
                    'is_default' => (bool) $option->is_default,
                    'sort_order' => (int) $option->sort_order,
                    'product_id' => $option->product_id ? (string) $option->product_id : null,
                    'product_variant_id' => $option->product_variant_id ? (string) $option->product_variant_id : null,
                    'available' => $override ? $override->is_available : true,
                    'unavailable_reason' => $override && ! $override->is_available ? ($override->reason ?: 'Sold out') : null,
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (string) $group->id,
            'name' => $group->name,
            'kind' => $group->kind,
            'selection_type' => $group->selection_type,
            'min_select' => (int) $group->min_select,
            'max_select' => (int) $group->max_select,
            'is_required' => (bool) $group->is_required,
            'sort_order' => (int) $group->sort_order,
            'options' => $options,
        ];
    }

    private function override(int $businessId, int $outletId, ?int $productId, ?int $optionId, ?int $variantId): ?MenuAvailability
    {
        return MenuAvailability::query()
            ->where('business_id', $businessId)
            ->where(function ($query) use ($outletId) {
                $query->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            })
            ->when($productId, fn ($query) => $query->where('product_id', $productId)->where('product_variant_id', $variantId))
            ->when($optionId, fn ($query) => $query->where('modifier_option_id', $optionId))
            ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('id')
            ->first();
    }

    private function withinDaypart(Product $product, ?CarbonInterface $at): bool
    {
        $dayparts = $product->dayparts()->where('is_active', true)->get();

        if ($dayparts->isEmpty()) {
            return true;
        }

        $business = Business::find($product->business_id);
        $local = ($at ?? now())->copy()->setTimezone($business?->timezone ?: config('app.timezone'));
        $day = (int) $local->dayOfWeek;
        $time = $local->format('H:i:s');

        foreach ($dayparts as $daypart) {
            $days = $daypart->days;
            if (is_array($days) && $days !== [] && ! in_array($day, array_map('intval', $days), true)) {
                continue;
            }

            $start = $daypart->start_time;
            $end = $daypart->end_time;

            if ($start <= $end) {
                if ($time >= $start && $time <= $end) {
                    return true;
                }
            } elseif ($time >= $start || $time <= $end) {
                return true;
            }
        }

        return false;
    }
}

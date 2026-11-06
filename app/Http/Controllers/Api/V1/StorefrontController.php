<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\StorefrontException;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Storefront;
use App\Models\StorefrontItem;
use App\Models\StorefrontOrder;
use App\Services\AuthorizationService;
use App\Services\DeliveryService;
use App\Services\StorefrontService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorefrontController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected StorefrontService $storefronts,
        protected DeliveryService $deliveries,
    ) {}

    public function index(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'storefront.view', $businessId);

        return $this->success(
            Storefront::where('business_id', $businessId)
                ->withCount(['items', 'orders'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $businessId);
        $data = $this->validated($request, $businessId);

        $storefront = Storefront::create(array_merge($data, [
            'business_id' => $businessId,
            'currency' => $data['currency'] ?? 'IDR',
            'is_active' => $data['is_active'] ?? false,
            'published_at' => ! empty($data['is_active']) ? now() : null,
        ]));

        return $this->success($storefront, 'Storefront created', 201);
    }

    public function show(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.view', $storefront->business_id);

        return $this->success($storefront->load('items.product', 'outlet:id,name'));
    }

    public function update(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $storefront->business_id);
        $data = $this->validated($request, $storefront->business_id, $storefront);

        if (array_key_exists('is_active', $data)) {
            $data['published_at'] = $data['is_active'] ? ($storefront->published_at ?? now()) : null;
        }
        $storefront->update($data);

        return $this->success($storefront->fresh());
    }

    public function destroy(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $storefront->business_id);
        $storefront->delete();

        return $this->success(null, 'Storefront deleted');
    }

    public function publish(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $storefront->business_id);
        $storefront->update(['is_active' => true, 'published_at' => $storefront->published_at ?? now()]);

        return $this->success($storefront->fresh(), 'Storefront published');
    }

    public function unpublish(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $storefront->business_id);
        $storefront->update(['is_active' => false, 'published_at' => null]);

        return $this->success($storefront->fresh(), 'Storefront unpublished');
    }

    public function syncItems(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.manage', $storefront->business_id);

        $data = $request->validate([
            'items' => 'present|array',
            'items.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('business_id', $storefront->business_id)->whereNull('deleted_at'),
            ],
            'items.*.is_visible' => 'nullable|boolean',
            'items.*.price_override' => 'nullable|numeric|min:0',
            'items.*.sort_order' => 'nullable|integer|min:0',
        ]);

        $items = DB::transaction(function () use ($storefront, $data) {
            $keep = [];
            foreach ($data['items'] as $row) {
                $item = StorefrontItem::updateOrCreate(
                    ['storefront_id' => $storefront->id, 'product_id' => $row['product_id']],
                    [
                        'is_visible' => $row['is_visible'] ?? true,
                        'price_override' => $row['price_override'] ?? null,
                        'sort_order' => $row['sort_order'] ?? 0,
                    ]
                );
                $keep[] = $item->id;
            }
            StorefrontItem::where('storefront_id', $storefront->id)->whereNotIn('id', $keep)->delete();

            return StorefrontItem::where('storefront_id', $storefront->id)->with('product')->get();
        });

        return $this->success($items, 'Storefront catalogue updated');
    }

    public function orders(Request $request, Storefront $storefront)
    {
        $this->guard($request, $storefront);
        $this->authz->requirePermission($request->user(), 'storefront.orders', $storefront->business_id);

        $query = StorefrontOrder::where('storefront_id', $storefront->id)
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($builder) => $builder->where('placed_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($builder) => $builder->where('placed_at', '<=', $request->input('to')))
            ->orderByDesc('id');

        return $this->success($query->paginate(50));
    }

    public function order(Request $request, StorefrontOrder $storefrontOrder)
    {
        $this->guardOrder($request, $storefrontOrder);
        $this->authz->requirePermission($request->user(), 'storefront.orders', $storefrontOrder->business_id);

        return $this->success($storefrontOrder->load('events', 'sale.items', 'deliveries', 'customer'));
    }

    public function orderStatus(Request $request, StorefrontOrder $storefrontOrder)
    {
        $this->guardOrder($request, $storefrontOrder);
        $this->authz->requirePermission($request->user(), 'storefront.orders', $storefrontOrder->business_id);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', StorefrontOrder::STATUSES),
            'message' => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->storefronts->transition($storefrontOrder, $data['status'], $request->user()->id, $data['message'] ?? null);
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($order, 'Order updated');
    }

    public function orderPayment(Request $request, StorefrontOrder $storefrontOrder)
    {
        $this->guardOrder($request, $storefrontOrder);
        $this->authz->requirePermission($request->user(), 'storefront.orders', $storefrontOrder->business_id);

        $data = $request->validate([
            'method' => 'nullable|string|max:32',
            'reference' => 'nullable|string|max:128',
        ]);

        $order = $this->storefronts->markPaid(
            $storefrontOrder,
            $data['method'] ?? 'manual',
            $data['reference'] ?? null,
            $request->user()->id,
        );

        return $this->success($order, 'Payment recorded');
    }

    public function orderDelivery(Request $request, StorefrontOrder $storefrontOrder)
    {
        $this->guardOrder($request, $storefrontOrder);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $storefrontOrder->business_id);

        $existing = Delivery::where('storefront_order_id', $storefrontOrder->id)
            ->whereIn('status', Delivery::ACTIVE_STATUSES)
            ->first();

        $delivery = $existing ?: $this->deliveries->createForStorefrontOrder($storefrontOrder, $request->user()->id);

        return $this->success($delivery->load('lines', 'events'), $existing ? 'Active delivery exists' : 'Delivery created', $existing ? 200 : 201);
    }

    /* ------------------------------------------------------------------ */

    private function validated(Request $request, int $businessId, ?Storefront $storefront = null): array
    {
        $required = $storefront ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:120',
            'slug' => [
                $required, 'string', 'max:80', 'alpha_dash',
                Rule::unique('storefronts', 'slug')->ignore($storefront?->id),
            ],
            'outlet_id' => [
                'nullable', 'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'domain' => 'nullable|string|max:160',
            'currency' => 'nullable|string|size:3',
            'description' => 'nullable|string',
            'theme' => 'nullable|array',
            'settings' => 'nullable|array',
            'settings.delivery_fee' => 'nullable|numeric|min:0',
            'settings.tax_rate' => 'nullable|numeric|min:0|max:100',
            'settings.min_order' => 'nullable|numeric|min:0',
            'settings.delivery_enabled' => 'nullable|boolean',
            'settings.pickup_enabled' => 'nullable|boolean',
            'settings.payment_methods' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function guard(Request $request, Storefront $storefront): void
    {
        if ((int) $storefront->business_id !== $this->businessId($request)) {
            abort(404);
        }
    }

    private function guardOrder(Request $request, StorefrontOrder $order): void
    {
        if ((int) $order->business_id !== $this->businessId($request)) {
            abort(404);
        }
    }

    private function businessId(Request $request): int
    {
        return (int) $request->attributes->get('business_id');
    }
}

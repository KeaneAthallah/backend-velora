<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\StorefrontException;
use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Storefront;
use App\Models\StorefrontCustomerAccount;
use App\Models\StorefrontOrder;
use App\Services\DeliveryService;
use App\Services\StorefrontService;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated storefront surface. A cart is addressed by an opaque
 * token; storefront shoppers can optionally sign in with a Sanctum token from
 * the account endpoints.
 */
class StorefrontShopController extends Controller
{
    public function __construct(
        protected StorefrontService $storefronts,
        protected DeliveryService $deliveries,
    ) {}

    public function show(string $slug)
    {
        $storefront = $this->storefront($slug);

        return $this->success($this->storefronts->catalog($storefront));
    }

    public function createCart(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $cart = $this->storefronts->createCart($storefront, $this->currentAccount($request, $storefront));

        return $this->success($this->cartPayload($cart), 'Cart created', 201);
    }

    public function showCart(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $cart = $this->storefronts->resolveCart($storefront, $this->token($request));

        return $this->success($this->cartPayload($cart));
    }

    public function addItem(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $data = $request->validate([
            'token' => 'required|string|max:64',
            'product_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.0001',
            'notes' => 'nullable|string|max:255',
        ]);
        $cart = $this->storefronts->resolveCart($storefront, $data['token']);

        try {
            $cart = $this->storefronts->addItem($cart, (int) $data['product_id'], $data['quantity'], $data['notes'] ?? null);
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($this->cartPayload($cart), 'Item added');
    }

    public function updateItem(Request $request, string $slug, int $productId)
    {
        $storefront = $this->storefront($slug);
        $data = $request->validate([
            'token' => 'required|string|max:64',
            'quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:255',
        ]);
        $cart = $this->storefronts->resolveCart($storefront, $data['token']);

        try {
            $cart = $this->storefronts->updateItem($cart, $productId, $data['quantity'], $data['notes'] ?? null);
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($this->cartPayload($cart), 'Cart updated');
    }

    public function removeItem(Request $request, string $slug, int $productId)
    {
        $storefront = $this->storefront($slug);
        $request->validate(['token' => 'required|string|max:64']);
        $cart = $this->storefronts->resolveCart($storefront, $this->token($request));
        $cart = $this->storefronts->removeItem($cart, $productId);

        return $this->success($this->cartPayload($cart), 'Item removed');
    }

    public function checkout(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $data = $request->validate([
            'token' => 'required|string|max:64',
            'fulfilment_type' => 'nullable|in:delivery,pickup',
            'customer_name' => 'required|string|max:120',
            'customer_phone' => 'nullable|string|max:32',
            'customer_email' => 'nullable|email|max:160',
            'payment_method' => 'nullable|in:cod,bank_transfer,online',
            'delivery_zone_id' => 'nullable|integer',
            'delivery_address' => 'nullable|array',
            'delivery_address.line1' => 'nullable|string|max:255',
            'delivery_address.city' => 'nullable|string|max:120',
            'delivery_address.postcode' => 'nullable|string|max:16',
            'notes' => 'nullable|string|max:500',
            'scheduled_at' => 'nullable|date',
        ]);

        $cart = $this->storefronts->resolveCart($storefront, $data['token']);
        $fulfilment = $data['fulfilment_type'] ?? 'delivery';

        $settings = $storefront->settings ?? [];
        if ($fulfilment === 'delivery' && isset($settings['delivery_enabled']) && ! $settings['delivery_enabled']) {
            return $this->error('Delivery is not available for this store.', 422, [], 'DELIVERY_DISABLED');
        }
        if ($fulfilment === 'pickup' && isset($settings['pickup_enabled']) && ! $settings['pickup_enabled']) {
            return $this->error('Pickup is not available for this store.', 422, [], 'PICKUP_DISABLED');
        }
        if ($fulfilment === 'delivery' && (empty($data['customer_phone']) || empty($data['delivery_address']['line1']))) {
            return $this->error('A phone number and delivery address are required.', 422, [], 'DELIVERY_DETAILS_REQUIRED');
        }

        try {
            $order = $this->storefronts->checkout($storefront, $cart, $data, $this->currentAccount($request, $storefront));
            if ($order->fulfilment_type === 'delivery') {
                $this->deliveries->createForStorefrontOrder($order);
            }
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success([
            'order' => $order->fresh()->load('events', 'deliveries'),
            'tracking_token' => $order->tracking_token,
            'tracking_url' => '/storefronts/'.$storefront->slug.'/orders/'.$order->number,
        ], 'Order placed', 201);
    }

    public function track(Request $request, string $slug, string $number)
    {
        $storefront = $this->storefront($slug);
        $order = StorefrontOrder::where('storefront_id', $storefront->id)
            ->where('number', $number)
            ->first();

        if (! $order) {
            return $this->error('Order not found.', 404);
        }

        $token = $request->input('token') ?: $request->header('X-Tracking-Token');
        if ($order->tracking_token && ! hash_equals($order->tracking_token, (string) $token)) {
            return $this->error('Order not found.', 404);
        }

        return $this->success($this->storefronts->tracking($order));
    }

    public function register(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'phone' => 'nullable|string|max:32',
            'password' => 'required|string|min:8|max:72',
        ]);

        try {
            $result = $this->storefronts->registerAccount($storefront, $data);
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($result, 'Account created', 201);
    }

    public function login(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        try {
            $result = $this->storefronts->login($storefront, $data['email'], $data['password']);
        } catch (StorefrontException $e) {
            return $this->error($e->getMessage(), 401, $e->errors, $e->errorCode);
        }

        return $this->success($result, 'Signed in');
    }

    public function account(Request $request, string $slug)
    {
        $storefront = $this->storefront($slug);
        $account = $this->currentAccount($request, $storefront);
        if (! $account) {
            return $this->error('Unauthenticated.', 401, [], 'UNAUTHENTICATED');
        }

        return $this->success([
            'account' => $account,
            'customer' => $account->customer,
            'orders' => StorefrontOrder::where('customer_account_id', $account->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function storefront(string $slug): Storefront
    {
        $storefront = Storefront::where('slug', $slug)
            ->where('is_active', true)
            ->whereNotNull('published_at')
            ->first();

        abort_if(! $storefront, 404, 'Storefront not found.');

        return $storefront;
    }

    private function token(Request $request): string
    {
        return (string) ($request->input('token') ?: $request->header('X-Cart-Token'));
    }

    private function currentAccount(Request $request, Storefront $storefront): ?StorefrontCustomerAccount
    {
        $user = $request->user('sanctum');

        if (! $user instanceof StorefrontCustomerAccount) {
            return null;
        }

        return (int) $user->storefront_id === (int) $storefront->id ? $user : null;
    }

    private function cartPayload(Cart $cart): array
    {
        return [
            'cart' => [
                'token' => $cart->token,
                'status' => $cart->status,
                'expires_at' => $cart->expires_at?->toIso8601String(),
            ],
            'summary' => $this->storefronts->summary($cart, 'delivery'),
        ];
    }
}

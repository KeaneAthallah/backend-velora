<?php

use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;

function storefrontSetup($test, string $suffix = 'SF'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Storefront Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Storefront Outlet $suffix", 'code' => "SF-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Storefront Item $suffix",
            'sku' => "SF-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 10,
        'reason' => 'seed',
    ])->assertOk();

    $slug = 'shop-'.strtolower($suffix);
    $storefront = $test->withHeaders($bizHeaders)->postJson('/api/v1/storefronts', [
        'name' => "Shop $suffix",
        'slug' => $slug,
        'outlet_id' => $outlet['id'],
        'settings' => [
            'delivery_fee' => 5000,
            'tax_rate' => 0,
            'delivery_enabled' => true,
            'pickup_enabled' => true,
            'payment_methods' => ['cod'],
        ],
    ])->assertStatus(201)->json('data');

    $test->withHeaders($bizHeaders)
        ->putJson("/api/v1/storefronts/{$storefront['id']}/items", [
            'items' => [['product_id' => $product['id'], 'is_visible' => true]],
        ])
        ->assertOk();

    $test->withHeaders($bizHeaders)
        ->postJson("/api/v1/storefronts/{$storefront['id']}/publish")
        ->assertOk();

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'storefront', 'slug');
}

function storefrontCheckout($test, array $ctx, int $quantity = 2): array
{
    $cart = $test->postJson("/api/v1/public/storefronts/{$ctx['slug']}/cart")->assertStatus(201)->json('data');
    $token = $cart['cart']['token'];

    $test->postJson("/api/v1/public/storefronts/{$ctx['slug']}/cart/items", [
        'token' => $token,
        'product_id' => $ctx['product']['id'],
        'quantity' => $quantity,
    ])->assertOk();

    $order = $test->postJson("/api/v1/public/storefronts/{$ctx['slug']}/checkout", [
        'token' => $token,
        'fulfilment_type' => 'delivery',
        'customer_name' => 'Nadia',
        'customer_phone' => '08123456789',
        'delivery_address' => ['line1' => 'Jl. Merdeka 1', 'city' => 'Bandung'],
        'payment_method' => 'cod',
    ])->assertStatus(201)->json('data');

    return $order;
}

test('public catalogue lists published products with storefront prices', function () {
    $ctx = storefrontSetup($this, 'CAT');

    $response = $this->getJson("/api/v1/public/storefronts/{$ctx['slug']}")
        ->assertOk()
        ->assertJsonPath('data.storefront.slug', $ctx['slug']);

    $item = $response->json('data.items.0');
    expect($item['name'])->toBe('Storefront Item CAT');
    expect($item['price'])->toBe('10000.0000');
    expect($item['available'])->toBe('10.0000');
});

test('checkout creates an order, sale, delivery and stock reservation', function () {
    $ctx = storefrontSetup($this, 'CHK');

    $order = storefrontCheckout($this, $ctx);

    expect($order['order']['status'])->toBe('pending');
    expect($order['order']['total'])->toBe('25000.0000');
    expect($order['order']['fulfilment_type'])->toBe('delivery');
    expect($order['order']['deliveries'])->toHaveCount(1);
    expect($order['tracking_token'])->toBeString();

    $sale = Sale::find($order['order']['sale_id']);
    expect($sale)->not->toBeNull();
    expect($sale->status)->toBe('draft');
    expect((string) $sale->total)->toBe('25000.0000');

    $balance = InventoryBalance::where('product_id', $ctx['product']['id'])->first();
    expect((string) $balance->reserved_quantity)->toBe('2.0000');
    expect((string) $balance->quantity)->toBe('10.0000');

    // Tracking needs the token that the checkout returned.
    $this->getJson("/api/v1/public/storefronts/{$ctx['slug']}/orders/{$order['order']['number']}")
        ->assertStatus(404);

    $this->getJson("/api/v1/public/storefronts/{$ctx['slug']}/orders/{$order['order']['number']}?token={$order['tracking_token']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
});

test('fulfilment finalises the sale, deducts stock and records payment', function () {
    $ctx = storefrontSetup($this, 'FUL');
    $order = storefrontCheckout($this, $ctx);
    $id = $order['order']['id'];

    foreach (['confirmed', 'preparing', 'ready', 'out_for_delivery'] as $status) {
        $this->withHeaders($ctx['bizHeaders'])
            ->postJson("/api/v1/storefront-orders/$id/status", ['status' => $status])
            ->assertOk();
    }

    $delivered = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/storefront-orders/$id/status", ['status' => 'delivered'])
        ->assertOk()
        ->json('data');

    expect($delivered['status'])->toBe('delivered');
    expect($delivered['payment_status'])->toBe('paid');

    $sale = Sale::find($order['order']['sale_id']);
    expect($sale->status)->toBe('completed');

    $balance = InventoryBalance::where('product_id', $ctx['product']['id'])->first();
    expect((string) $balance->quantity)->toBe('8.0000');
    expect((string) $balance->reserved_quantity)->toBe('0.0000');

    expect(Payment::where('sale_id', $sale->id)->where('status', 'completed')->count())->toBe(1);
});

test('cancelling releases the reservation and voids the sale', function () {
    $ctx = storefrontSetup($this, 'CAN');
    $order = storefrontCheckout($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/storefront-orders/{$order['order']['id']}/status", [
            'status' => 'cancelled',
            'message' => 'Customer changed their mind',
        ])
        ->assertOk();

    $sale = Sale::find($order['order']['sale_id']);
    expect($sale->status)->toBe('voided');

    $balance = InventoryBalance::where('product_id', $ctx['product']['id'])->first();
    expect((string) $balance->reserved_quantity)->toBe('0.0000');
    expect((string) $balance->quantity)->toBe('10.0000');
});

test('invalid order transitions are rejected', function () {
    $ctx = storefrontSetup($this, 'TRN');
    $order = storefrontCheckout($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/storefront-orders/{$order['order']['id']}/status", ['status' => 'delivered'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_TRANSITION');
});

test('storefronts and orders are tenant scoped', function () {
    $owner = storefrontSetup($this, 'OWN');

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $other = User::factory()->create();
    $auth = ['Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken];
    $biz = $this->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Other Shop Biz'])->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $this->withHeaders($headers)
        ->getJson("/api/v1/storefronts/{$owner['storefront']['id']}")
        ->assertStatus(404);

    $this->withHeaders($headers)
        ->getJson("/api/v1/storefronts/{$owner['storefront']['id']}/orders")
        ->assertStatus(404);
});

test('shoppers can register, sign in and read their orders', function () {
    $ctx = storefrontSetup($this, 'ACC');

    $register = $this->postJson("/api/v1/public/storefronts/{$ctx['slug']}/account/register", [
        'name' => 'Sari',
        'email' => 'sari@example.test',
        'phone' => '0812000000',
        'password' => 'secret-pass',
    ])->assertStatus(201)->json('data');

    expect($register['token'])->toBeString();

    $this->postJson("/api/v1/public/storefronts/{$ctx['slug']}/account/login", [
        'email' => 'sari@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    $login = $this->postJson("/api/v1/public/storefronts/{$ctx['slug']}/account/login", [
        'email' => 'sari@example.test',
        'password' => 'secret-pass',
    ])->assertOk()->json('data');

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders(['Authorization' => 'Bearer '.$login['token']])
        ->getJson("/api/v1/public/storefronts/{$ctx['slug']}/account")
        ->assertOk()
        ->assertJsonPath('data.account.email', 'sari@example.test');
});

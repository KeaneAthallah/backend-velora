<?php

use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;

function orderFlowSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Order Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Americano',
        'sku' => 'AMR-1',
        'selling_price' => 20000,
        'cost_price' => 6000,
        'track_inventory' => true,
    ])->json('data');

    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];
    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 50,
        'reason' => 'init',
    ]);

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'product', 'headers');
}

function orderFlowStock($test, array $ctx): string
{
    $response = $test->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/inventory/balances?outlet_id='.$ctx['outlet']['id']);

    $row = collect($response->json('data.data'))->firstWhere('product_id', (int) $ctx['product']['id']);

    return (string) ($row['quantity'] ?? 'missing');
}

function orderFlowCreate($test, array $ctx, array $overrides = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/orders', array_merge([
        'outlet_id' => $ctx['outlet']['id'],
        'order_type' => 'dine_in',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ], $overrides));
}

test('open order prices items, numbers by type and leaves stock untouched', function () {
    $ctx = orderFlowSetup($this);

    $first = orderFlowCreate($this, $ctx);
    $first->assertStatus(201);
    $order = $first->json('data');

    expect($order['status'])->toBe('draft');
    expect($order['order_status'])->toBe('open');
    expect($order['order_type'])->toBe('dine_in');
    expect($order['order_number'])->toStartWith('MAIN-DI-');
    expect($order['order_number'])->toEndWith('-001');
    expect($order['total'])->toBe('40000.0000');
    expect($order['receipt_number'])->toBe('OPEN-'.$order['order_number']);
    expect($order['items'])->toHaveCount(1);
    expect($order['items'][0]['option_ids'])->toBe([]);

    // Opening an order does not move stock; checkout does.
    expect(orderFlowStock($this, $ctx))->toBe('50.0000');

    $second = orderFlowCreate($this, $ctx, ['order_type' => 'takeaway'])->json('data');
    expect($second['order_number'])->toStartWith('MAIN-TA-');
    expect($second['order_number'])->toEndWith('-001');

    $third = orderFlowCreate($this, $ctx, ['order_type' => 'takeaway'])->assertStatus(201)->json('data');
    expect($third['order_number'])->toEndWith('-002');

    $active = $this->withHeaders($ctx['headers'])->getJson('/api/v1/orders');
    $active->assertStatus(200);
    expect($active->json('data.data'))->toHaveCount(3);
});

test('order items can be replaced and repriced while open', function () {
    $ctx = orderFlowSetup($this);
    $order = orderFlowCreate($this, $ctx)->json('data');

    $updated = $this->withHeaders($ctx['headers'])->putJson("/api/v1/orders/{$order['id']}", [
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 3]],
        'notes' => 'Extra hot',
    ]);

    $updated->assertStatus(200);
    expect($updated->json('data.total'))->toBe('60000.0000');
    expect($updated->json('data.items'))->toHaveCount(1);
    expect($updated->json('data.items.0.quantity'))->toBe('3.0000');
    expect($updated->json('data.notes'))->toBe('Extra hot');

    // A checked-out order can no longer be edited.
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(200);
    $this->withHeaders($ctx['headers'])->putJson("/api/v1/orders/{$order['id']}", [
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(422);
});

test('order status advances forward with timestamps', function () {
    $ctx = orderFlowSetup($this);
    $order = orderFlowCreate($this, $ctx)->json('data');

    $fired = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", [
        'status' => 'in_kitchen',
    ]);
    $fired->assertStatus(200);
    expect($fired->json('data.order_status'))->toBe('in_kitchen');
    expect($fired->json('data.fired_at'))->not->toBeNull();

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", [
        'status' => 'open',
    ])->assertStatus(422);

    $served = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", [
        'status' => 'served',
    ]);
    $served->assertStatus(200);
    expect($served->json('data.order_status'))->toBe('served');
    expect($served->json('data.served_at'))->not->toBeNull();

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(200);
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", [
        'status' => 'ready',
    ])->assertStatus(422);
});

test('checkout deducts stock, writes stock movements and a receipt number', function () {
    $ctx = orderFlowSetup($this);
    $order = orderFlowCreate($this, $ctx)->json('data');

    $checkedOut = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout");
    $checkedOut->assertStatus(200);

    expect($checkedOut->json('data.status'))->toBe('completed');
    expect($checkedOut->json('data.order_status'))->toBe('completed');
    expect($checkedOut->json('data.receipt_number'))->toStartWith('MAIN-');
    expect($checkedOut->json('data.completed_at'))->not->toBeNull();
    expect(orderFlowStock($this, $ctx))->toBe('48.0000');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/inventory/movements?product_id='.$ctx['product']['id']);
    expect($movements->json('data.data.0.type'))->toBe('sale');
    expect($movements->json('data.data.0.reference_id'))->toBe($order['id']);

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(422);
});

test('cancelling an open order releases it without touching stock', function () {
    $ctx = orderFlowSetup($this);
    $order = orderFlowCreate($this, $ctx)->json('data');

    $cancelled = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/cancel", [
        'reason' => 'Guest left',
    ]);
    $cancelled->assertStatus(200);
    expect($cancelled->json('data.status'))->toBe('voided');
    expect($cancelled->json('data.order_status'))->toBe('cancelled');
    expect($cancelled->json('data.void_reason'))->toBe('Guest left');
    expect(orderFlowStock($this, $ctx))->toBe('50.0000');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(422);
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/cancel", [
        'reason' => 'Again',
    ])->assertStatus(422);

    $active = $this->withHeaders($ctx['headers'])->getJson('/api/v1/orders');
    expect($active->json('data.data'))->toHaveCount(0);
});

test('delivery orders require contact details', function () {
    $ctx = orderFlowSetup($this);

    orderFlowCreate($this, $ctx, ['order_type' => 'delivery'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ORDER_ERROR');

    $order = orderFlowCreate($this, $ctx, [
        'order_type' => 'delivery',
        'fulfilment' => [
            'contact_name' => 'Rina',
            'contact_phone' => '08123456789',
            'address' => 'Jl. Kemang 10',
            'instructions' => 'Leave at the lobby',
        ],
    ])->assertStatus(201)->json('data');

    expect($order['order_type'])->toBe('delivery');
    expect($order['order_number'])->toStartWith('MAIN-DL-');
    expect($order['fulfilment']['address'])->toBe('Jl. Kemang 10');
});

test('immediate sales get order metadata and deduct stock', function () {
    $ctx = orderFlowSetup($this);

    $sale = $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'order_type' => 'pickup',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);

    $sale->assertStatus(201);
    expect($sale->json('data.order_type'))->toBe('pickup');
    expect($sale->json('data.order_status'))->toBe('completed');
    expect($sale->json('data.order_number'))->toStartWith('MAIN-PU-');
    expect(orderFlowStock($this, $ctx))->toBe('49.0000');
});

test('open orders stay out of the receipt ledger', function () {
    $ctx = orderFlowSetup($this);
    orderFlowCreate($this, $ctx)->assertStatus(201);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(201);

    $ledger = $this->withHeaders($ctx['headers'])->getJson('/api/v1/sales');
    $ledger->assertStatus(200);
    expect($ledger->json('data.data'))->toHaveCount(1);
    expect($ledger->json('data.data.0.order_status'))->toBe('completed');

    $drafts = $this->withHeaders($ctx['headers'])->getJson('/api/v1/sales?status=draft');
    expect($drafts->json('data.data'))->toHaveCount(1);
    expect($drafts->json('data.data.0.order_status'))->toBe('open');
});

test('orders are tenant scoped', function () {
    $ctx = orderFlowSetup($this);
    $order = orderFlowCreate($this, $ctx)->json('data');

    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)->postJson('/api/v1/businesses', ['name' => 'Other Biz'])->json('data');
    $otherHeaders = $otherAuth + ['X-Business-Id' => $otherBiz['id']];
    $this->flushHeaders();

    $this->withHeaders($otherHeaders)->getJson("/api/v1/orders/{$order['id']}")->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/orders/{$order['id']}", [
        'notes' => 'Hacked',
    ])->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/orders/{$order['id']}/status", [
        'status' => 'ready',
    ])->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/orders/{$order['id']}/cancel", [
        'reason' => 'Nope',
    ])->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson('/api/v1/orders?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders($ctx['headers'])->getJson("/api/v1/orders/{$order['id']}")->assertStatus(200);
});

test('order management requires permissions', function () {
    $ctx = orderFlowSetup($this);

    $cashier = User::factory()->create();
    $cashierToken = $cashier->createToken('t')->plainTextToken;
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'name' => 'Cashier', 'slug' => 'cashier']);
    BusinessMembership::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $cashier->id,
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $headers = ['Authorization' => "Bearer $cashierToken", 'X-Business-Id' => $ctx['biz']['id'], 'X-Outlet-Id' => $ctx['outlet']['id']];
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($headers)->getJson('/api/v1/orders')->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/orders', [
        'outlet_id' => $ctx['outlet']['id'],
        'order_type' => 'dine_in',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(403);
});

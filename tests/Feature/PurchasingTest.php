<?php

use App\Models\User;

function setupPurchaseBiz($test, string $suffix = 'A'): array
{
    $test->flushHeaders();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];
    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => "Buying Biz $suffix"])->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);
    $outlet = $test->withHeaders($headers)->postJson('/api/v1/outlets', [
        'name' => "Outlet $suffix",
        'code' => "OUT-$suffix",
    ])->json('data');
    $product = $test->withHeaders($headers)->postJson('/api/v1/products', [
        'name' => "Beans $suffix",
        'sku' => "BEAN-$suffix",
        'selling_price' => 200,
        'cost_price' => 100,
        'track_inventory' => true,
    ])->json('data');
    $supplier = $test->withHeaders($headers)->postJson('/api/v1/suppliers', [
        'name' => "Supplier $suffix",
    ])->json('data');
    $outletHeaders = array_merge($headers, ['X-Outlet-Id' => $outlet['id']]);

    return compact('user', 'token', 'biz', 'outlet', 'product', 'supplier', 'headers', 'outletHeaders');
}

test('purchase order receiving validates state, ownership and remaining quantities', function () {
    $ctx = setupPurchaseBiz($this);
    $h = $ctx['outletHeaders'];

    $create = $this->withHeaders($h)->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $ctx['supplier']['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [[
            'product_id' => $ctx['product']['id'],
            'quantity' => 10,
            'unit_cost' => 100,
        ]],
    ]);
    $create->assertStatus(201);
    $po = $create->json('data');
    $lineId = $po['items'][0]['id'];

    // A draft cannot be received.
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $lineId, 'quantity' => 1]],
    ])->assertStatus(422);

    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/approve")->assertOk();

    // Partial receipt moves the order to partially_received and increments stock.
    $partial = $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $lineId, 'quantity' => 4]],
    ]);
    $partial->assertOk()->assertJsonPath('data.status', 'partially_received');
    expect($this->withHeaders($h)->getJson('/api/v1/inventory/balances?per_page=100')->json('data.data.0.quantity'))
        ->toBe('4.0000');

    // Duplicate lines and over-receipt are rejected without changing stock.
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [
            ['purchase_order_item_id' => $lineId, 'quantity' => 1],
            ['purchase_order_item_id' => $lineId, 'quantity' => 1],
        ],
    ])->assertStatus(422);
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $lineId, 'quantity' => 7]],
    ])->assertStatus(422);
    expect($this->withHeaders($h)->getJson('/api/v1/inventory/balances?per_page=100')->json('data.data.0.quantity'))
        ->toBe('4.0000');

    // A line from another business's purchase order is rejected.
    $other = setupPurchaseBiz($this, 'B');
    $otherPo = $this->withHeaders($other['outletHeaders'])->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $other['supplier']['id'],
        'outlet_id' => $other['outlet']['id'],
        'items' => [[
            'product_id' => $other['product']['id'],
            'quantity' => 3,
            'unit_cost' => 100,
        ]],
    ])->json('data');
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $otherPo['items'][0]['id'], 'quantity' => 1]],
    ])->assertStatus(422);

    // Receiving the remaining quantity completes the order.
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $lineId, 'quantity' => 6]],
    ])->assertOk()->assertJsonPath('data.status', 'completed');
    expect($this->withHeaders($h)->getJson('/api/v1/inventory/balances?per_page=100')->json('data.data.0.quantity'))
        ->toBe('10.0000');

    // Completed orders cannot receive more stock.
    $this->withHeaders($h)->postJson("/api/v1/purchase-orders/{$po['id']}/receive", [
        'items' => [['purchase_order_item_id' => $lineId, 'quantity' => 1]],
    ])->assertStatus(422);
});

test('purchase order creation rejects cross-business records and duplicate lines', function () {
    $ctx = setupPurchaseBiz($this);
    $other = setupPurchaseBiz($this, 'B');

    $this->withHeaders($ctx['outletHeaders'])->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $other['supplier']['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1, 'unit_cost' => 100]],
    ])->assertStatus(422);

    $this->withHeaders($ctx['outletHeaders'])->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $ctx['supplier']['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $other['product']['id'], 'quantity' => 1, 'unit_cost' => 100]],
    ])->assertStatus(422);

    $this->withHeaders($ctx['outletHeaders'])->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $ctx['supplier']['id'],
        'outlet_id' => $other['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1, 'unit_cost' => 100]],
    ])->assertStatus(422);

    $this->withHeaders($ctx['outletHeaders'])->postJson('/api/v1/purchase-orders', [
        'supplier_id' => $ctx['supplier']['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [
            ['product_id' => $ctx['product']['id'], 'quantity' => 1, 'unit_cost' => 100],
            ['product_id' => $ctx['product']['id'], 'quantity' => 2, 'unit_cost' => 100],
        ],
    ])->assertStatus(422);
});

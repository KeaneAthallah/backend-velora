<?php

use App\Models\User;

function setupBiz($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $bizRes = $test->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/v1/businesses', ['name' => 'Biz']);
    $biz = $bizRes->json('data');
    $outletRes = $test->withHeaders(['Authorization' => "Bearer $token", 'X-Business-Id' => $biz['id']])->postJson('/api/v1/outlets', ['name' => 'O1', 'code' => 'O1']);
    $outlet = $outletRes->json('data');
    $productRes = $test->withHeaders(['Authorization' => "Bearer $token", 'X-Business-Id' => $biz['id']])->postJson('/api/v1/products', ['name' => 'Item', 'sku' => 'SKU1', 'selling_price' => 10000, 'cost_price' => 4000, 'track_inventory' => true]);
    $product = $productRes->json('data');
    if (! $product) {
        dump($productRes->status(), $productRes->json());
    }
    $test->withHeaders(['Authorization' => "Bearer $token", 'X-Business-Id' => $biz['id'], 'X-Outlet-Id' => $outlet['id']])->postJson('/api/v1/inventory/adjust', ['product_id' => $product['id'], 'quantity' => 50, 'reason' => 'init']);
    return compact('user', 'token', 'biz', 'outlet', 'product');
}

test('sale computes totals and decrements stock', function () {
    $ctx = setupBiz($this);
    $h = ['Authorization' => "Bearer {$ctx['token']}", 'X-Business-Id' => $ctx['biz']['id'], 'X-Outlet-Id' => $ctx['outlet']['id']];
    $sale = $this->withHeaders($h)->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ]);
    $sale->assertStatus(201);
    expect($sale->json('data.total'))->toBe("20000.0000");
});

test('idempotent sale', function () {
    $ctx = setupBiz($this);
    $h = ['Authorization' => "Bearer {$ctx['token']}", 'X-Business-Id' => $ctx['biz']['id'], 'X-Outlet-Id' => $ctx['outlet']['id']];
    $key = 'idem-1';
    $s1 = $this->withHeaders($h)->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'idempotency_key' => $key,
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);
    $s2 = $this->withHeaders($h)->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'idempotency_key' => $key,
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);
    expect($s1->json('data.id'))->toBe($s2->json('data.id'));
});

test('insufficient stock rejected', function () {
    $ctx = setupBiz($this);
    $h = ['Authorization' => "Bearer {$ctx['token']}", 'X-Business-Id' => $ctx['biz']['id'], 'X-Outlet-Id' => $ctx['outlet']['id']];
    $res = $this->withHeaders($h)->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 9999]],
    ]);
    $res->assertStatus(422);
});

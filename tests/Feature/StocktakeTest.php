<?php

use App\Models\User;

function setupStocktakeBiz($test, string $suffix = 'A'): array
{
    $test->flushHeaders();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];
    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => "Counting Biz $suffix"])->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);
    $outlet = $test->withHeaders($headers)->postJson('/api/v1/outlets', [
        'name' => "Outlet $suffix",
        'code' => "CNT-$suffix",
    ])->json('data');
    $tracked = $test->withHeaders($headers)->postJson('/api/v1/products', [
        'name' => "Tracked $suffix",
        'sku' => "TRK-$suffix",
        'selling_price' => 200,
        'cost_price' => 100,
        'track_inventory' => true,
    ])->json('data');
    $untracked = $test->withHeaders($headers)->postJson('/api/v1/products', [
        'name' => "Service $suffix",
        'sku' => "SRV-$suffix",
        'selling_price' => 50,
        'track_inventory' => false,
    ])->json('data');
    $outletHeaders = array_merge($headers, ['X-Outlet-Id' => $outlet['id']]);

    $test->withHeaders($outletHeaders)->postJson('/api/v1/inventory/adjust', [
        'outlet_id' => $outlet['id'],
        'product_id' => $tracked['id'],
        'quantity' => 10,
        'reason' => 'Opening stock',
    ])->assertOk();

    return compact('user', 'token', 'biz', 'outlet', 'tracked', 'untracked', 'headers', 'outletHeaders');
}

test('a stocktake snapshots tracked products, saves counts and posts adjustments', function () {
    $ctx = setupStocktakeBiz($this);
    $h = $ctx['outletHeaders'];

    $create = $this->withHeaders($h)->postJson('/api/v1/stocktakes', [
        'outlet_id' => $ctx['outlet']['id'],
        'notes' => 'Month end',
    ]);
    $create->assertStatus(201);
    $stocktake = $create->json('data');
    expect($stocktake['status'])->toBe('draft')
        ->and($stocktake['items'])->toHaveCount(1)
        ->and($stocktake['items'][0]['product_id'])->toBe($ctx['tracked']['id'])
        ->and($stocktake['items'][0]['expected_quantity'])->toBe('10.0000');

    $itemId = $stocktake['items'][0]['id'];
    $this->withHeaders($h)->putJson("/api/v1/stocktakes/{$stocktake['id']}", [
        'items' => [['id' => $itemId, 'counted_quantity' => 7]],
    ])->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.items.0.counted_quantity', '7.0000');

    // An item id from another stocktake is a 404, not a silent write.
    $other = $this->withHeaders($h)->postJson('/api/v1/stocktakes', ['outlet_id' => $ctx['outlet']['id']])->json('data');
    $this->withHeaders($h)->putJson("/api/v1/stocktakes/{$stocktake['id']}", [
        'items' => [['id' => $other['items'][0]['id'] ?? 0, 'counted_quantity' => 1]],
    ])->assertStatus(404);

    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/post")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $balance = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/balances?outlet_id='.$ctx['outlet']['id'])
        ->json('data.data.0');
    expect($balance['quantity'])->toBe('7.0000');

    $movement = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/movements?product_id='.$ctx['tracked']['id'])
        ->json('data.data.0');
    expect($movement['type'])->toBe('adjustment')
        ->and($movement['quantity'])->toBe('-3.0000')
        ->and($movement['reason'])->toBe("Stocktake #{$stocktake['id']}");

    // A completed count cannot be posted or edited again.
    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/post")->assertStatus(422);
    $this->withHeaders($h)->putJson("/api/v1/stocktakes/{$stocktake['id']}", [
        'items' => [['id' => $itemId, 'counted_quantity' => 99]],
    ])->assertStatus(422);
});

test('an all-zero stocktake needs explicit confirmation before it wipes stock', function () {
    $ctx = setupStocktakeBiz($this);
    $h = $ctx['outletHeaders'];

    $stocktake = $this->withHeaders($h)->postJson('/api/v1/stocktakes', [
        'outlet_id' => $ctx['outlet']['id'],
    ])->json('data');

    // Every line is zero while 10 units are on hand: refuse and keep stock.
    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/post")
        ->assertStatus(422)
        ->assertJsonPath('code', 'STOCKTAKE_CONFIRMATION_REQUIRED');

    $balance = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/balances?outlet_id='.$ctx['outlet']['id'])
        ->json('data.data.0.quantity');
    expect($balance)->toBe('10.0000');

    // The count stayed open, so the acknowledged retry applies the wipe.
    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/post", [
        'confirm_zero_count' => true,
    ])->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $balance = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/balances?outlet_id='.$ctx['outlet']['id'])
        ->json('data.data.0.quantity');
    expect($balance)->toBe('0.0000');

    $movement = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/movements?product_id='.$ctx['tracked']['id'])
        ->json('data.data.0');
    expect($movement['quantity'])->toBe('-10.0000');
});

test('a zero stocktake on an outlet without stock posts without confirmation', function () {
    $ctx = setupStocktakeBiz($this);
    $h = $ctx['outletHeaders'];

    // Empty the outlet first, then count it as zero: nothing is wiped.
    $this->withHeaders($h)->postJson('/api/v1/inventory/adjust', [
        'outlet_id' => $ctx['outlet']['id'],
        'product_id' => $ctx['tracked']['id'],
        'quantity' => -10,
        'reason' => 'Emptied for test',
    ])->assertOk();

    $stocktake = $this->withHeaders($h)->postJson('/api/v1/stocktakes', [
        'outlet_id' => $ctx['outlet']['id'],
    ])->json('data');

    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/post")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
});

test('stocktakes are tenant scoped and can be cancelled without moving stock', function () {
    $ctx = setupStocktakeBiz($this);
    $h = $ctx['outletHeaders'];

    $stocktake = $this->withHeaders($h)->postJson('/api/v1/stocktakes', [
        'outlet_id' => $ctx['outlet']['id'],
    ])->json('data');

    $other = setupStocktakeBiz($this, 'B');
    $this->withHeaders($other['outletHeaders'])->getJson("/api/v1/stocktakes/{$stocktake['id']}")->assertStatus(404);
    $this->withHeaders($other['outletHeaders'])->postJson("/api/v1/stocktakes/{$stocktake['id']}/post")->assertStatus(404);

    // An outlet from another business cannot be counted.
    $this->withHeaders($h)->postJson('/api/v1/stocktakes', [
        'outlet_id' => $other['outlet']['id'],
    ])->assertStatus(422);

    $this->withHeaders($h)->postJson("/api/v1/stocktakes/{$stocktake['id']}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $balance = $this->withHeaders($h)
        ->getJson('/api/v1/inventory/balances?outlet_id='.$ctx['outlet']['id'])
        ->json('data.data.0.quantity');
    expect($balance)->toBe('10.0000');
});

<?php

use App\Models\InventoryBalance;
use App\Models\Payment;
use App\Models\User;

/**
 * Regression coverage for tenant isolation and money/stock concurrency fixes:
 * cross-tenant inventory writes, double voids, payment retries and refund
 * over-approval must all be rejected or made idempotent.
 */
function coreSafetySetup($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Safety $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Outlet $suffix", 'code' => "OUT-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Item $suffix",
            'sku' => "SAFE-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function coreSafetyStock($test, array $ctx, int $quantity = 100): void
{
    $test->withHeaders($ctx['headers'])->postJson('/api/v1/inventory/adjust', [
        'product_id' => $ctx['product']['id'],
        'quantity' => $quantity,
        'reason' => 'seed',
    ])->assertOk();
}

function coreSafetySale($test, array $ctx, int $quantity = 2): array
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => $quantity]],
    ])->assertStatus(201)->json('data');
}

test('inventory adjust cannot write against another business product', function () {
    $a = coreSafetySetup($this, 'IA');
    $b = coreSafetySetup($this, 'IB');
    $this->app['auth']->forgetGuards();

    $res = $this->withHeaders($b['headers'])->postJson('/api/v1/inventory/adjust', [
        'product_id' => $a['product']['id'],
        'quantity' => 5,
        'reason' => 'cross tenant',
    ]);
    $res->assertStatus(422);

    // Neither business may have gained a balance for product A's id.
    expect(InventoryBalance::where('product_id', $a['product']['id'])->exists())->toBeFalse();
});

test('a sale can only be voided once and restores stock once', function () {
    $ctx = coreSafetySetup($this, 'V');
    coreSafetyStock($this, $ctx, 10);
    $sale = coreSafetySale($this, $ctx, 3);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'customer changed mind'])
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'again'])
        ->assertStatus(422);

    $balance = InventoryBalance::where('outlet_id', $ctx['outlet']['id'])
        ->where('product_id', $ctx['product']['id'])
        ->first();

    expect($balance->quantity)->toBe('10.0000');
});

test('a retried payment batch is recorded only once', function () {
    $ctx = coreSafetySetup($this, 'P');
    coreSafetyStock($this, $ctx);
    $sale = coreSafetySale($this, $ctx, 2);

    $payload = [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
        'idempotency_key' => 'pay-retry-1',
    ];

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$sale['id']}/payments", $payload)
        ->assertStatus(201);

    $second = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$sale['id']}/payments", $payload)
        ->assertStatus(201);

    expect(Payment::where('sale_id', $sale['id'])->count())->toBe(1);
    expect($second->json('data.paid_amount'))->toBe('20000.0000');
});

test('refunds cannot exceed the payment or be approved twice', function () {
    $ctx = coreSafetySetup($this, 'R');
    coreSafetyStock($this, $ctx);
    $sale = coreSafetySale($this, $ctx, 2);

    $payment = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 20000]],
        ])
        ->assertStatus(201)
        ->json('data.payments.0');

    // More than the payment itself.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 25000, 'reason' => 'too much'])
        ->assertStatus(422);

    // A valid partial refund reserves 5000 of the 20000.
    $refund = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 5000, 'reason' => 'partial'])
        ->assertStatus(201)
        ->json('data');

    // 16000 would leave only 15000 refundable because of the pending 5000.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/payments/{$payment['id']}/refund", ['amount' => 16000, 'reason' => 'over remaining'])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/refunds/{$refund['id']}/approve")
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/refunds/{$refund['id']}/approve")
        ->assertStatus(422);
});

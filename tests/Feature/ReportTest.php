<?php

use App\Models\User;

/**
 * The sales summary must aggregate in SQL and keep the documented money
 * contract (4-decimal strings), while excluding voided sales and other
 * businesses.
 */
function reportSetup($test, string $suffix = 'RS'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Report Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Outlet $suffix", 'code' => "RPT-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Report Item $suffix",
            'sku' => "RPT-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 100,
        'reason' => 'seed',
    ])->assertOk();

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function reportSale($test, array $ctx, int $quantity): array
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => $quantity]],
    ])->assertStatus(201)->json('data');
}

test('sales summary aggregates in SQL and excludes voided sales', function () {
    $ctx = reportSetup($this, 'SUM');

    reportSale($this, $ctx, 2);
    $toVoid = reportSale($this, $ctx, 5);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/sales/{$toVoid['id']}/void", ['reason' => 'aggregate check'])
        ->assertOk();

    $response = $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/reports/sales-summary?from='.urlencode(now()->subDay()->toDateTimeString())
            .'&to='.urlencode(now()->addDay()->toDateTimeString()))
        ->assertOk()
        ->assertJsonPath('success', true);

    // Only the 2-unit sale (2 x 10000) survives.
    expect($response->json('data.count'))->toBe(1);
    expect($response->json('data.gross_sales'))->toBe('20000.0000');
    expect($response->json('data.net_sales'))->toBe('20000.0000');
    expect($response->json('data.tax'))->toBe('0.0000');
    expect($response->json('data.discounts'))->toBe('0.0000');
});

test('sales summary never leaks another business and honours the outlet filter', function () {
    $a = reportSetup($this, 'A');
    reportSale($this, $a, 2);

    $b = reportSetup($this, 'B');

    // Business B asks for its own summary: it must not see A's sale.
    $response = $this->withHeaders($b['headers'])
        ->getJson('/api/v1/reports/sales-summary?from='.urlencode(now()->subDay()->toDateTimeString())
            .'&to='.urlencode(now()->addDay()->toDateTimeString()))
        ->assertOk();

    expect($response->json('data.count'))->toBe(0);
    expect($response->json('data.net_sales'))->toBe('0.0000');

    // Filtering by an unrelated outlet also yields nothing.
    app('auth')->forgetGuards();
    $filtered = $this->withHeaders($a['headers'])
        ->getJson('/api/v1/reports/sales-summary?outlet_id='.urlencode((string) $b['outlet']['id'])
            .'&from='.urlencode(now()->subDay()->toDateTimeString())
            .'&to='.urlencode(now()->addDay()->toDateTimeString()))
        ->assertOk();

    expect($filtered->json('data.count'))->toBe(0);
});

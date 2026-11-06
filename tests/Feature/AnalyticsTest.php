<?php

use App\Models\User;

function analyticsSetup($test, string $suffix = 'AN'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Analytics Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Analytics Outlet $suffix", 'code' => "AN-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Analytics Item $suffix",
            'sku' => "AN-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 20,
        'reason' => 'seed',
    ])->assertOk();

    $sale = $test->withHeaders($headers)->postJson('/api/v1/sales', [
        'outlet_id' => $outlet['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => 2]],
    ])->assertStatus(201)->json('data');

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'sale');
}

test('comparison reports current and previous revenue with change percent', function () {
    $ctx = analyticsSetup($this, 'CMP');

    $response = $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/analytics/comparison?from='.now()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($response->json('data.metrics.revenue.current'))->toBe('20000.0000');
    expect($response->json('data.metrics.orders.current'))->toBe(1);
    expect($response->json('data.metrics.revenue.previous'))->toBe('0.0000');
    expect($response->json('data.metrics.revenue.change_percent'))->toBeNull();
});

test('product analytics keeps margin, share and ABC class', function () {
    $ctx = analyticsSetup($this, 'PRD');

    $response = $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/analytics/products?from='.now()->toDateString().'&to='.now()->toDateString())
        ->assertOk();

    $row = $response->json('data.products.0');
    expect($row['product_name'])->toBe('Analytics Item PRD');
    expect($row['quantity'])->toBe('2.0000');
    expect($row['revenue'])->toBe('20000.0000');
    expect($row['margin'])->toBe('12000.0000');
    expect($row['revenue_share_percent'])->toBe(100);
    expect($row['abc_class'])->toBe('A');
});

test('saved reports run, record history and download in every format', function () {
    $ctx = analyticsSetup($this, 'SAV');

    $report = $this->withHeaders($ctx['headers'])->postJson('/api/v1/saved-reports', [
        'name' => 'Weekly comparison',
        'type' => 'comparison',
        'format' => 'csv',
        'schedule' => 'weekly',
        'schedule_time' => '08:00',
    ])->assertStatus(201)->json('data');

    expect($report['next_run_at'])->not->toBeNull();

    $run = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/saved-reports/{$report['id']}/run")
        ->assertOk()
        ->json('data');

    expect($run['run']['status'])->toBe('completed');
    expect($run['run']['row_count'])->toBeGreaterThan(0);
    expect($run['headers'][0])->toBe('Metric');

    $this->withHeaders($ctx['headers'])
        ->getJson("/api/v1/saved-reports/{$report['id']}/runs")
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $csv = $this->withHeaders($ctx['headers'])
        ->get("/api/v1/saved-reports/{$report['id']}/download?format=csv");
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');
    expect($csv->getContent())->toContain('Metric');

    $excel = $this->withHeaders($ctx['headers'])
        ->get("/api/v1/saved-reports/{$report['id']}/download?format=excel");
    $excel->assertOk();
    expect($excel->getContent())->toContain('<Workbook');

    $pdf = $this->withHeaders($ctx['headers'])
        ->get("/api/v1/saved-reports/{$report['id']}/download?format=pdf");
    $pdf->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

test('analytics are tenant scoped', function () {
    $a = analyticsSetup($this, 'TEN');

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $user = User::factory()->create();
    $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    $biz = $this->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Empty Analytics Biz'])->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);
    $outlet = $this->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => 'Empty Outlet', 'code' => 'EMPTY'])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $clean = $this->withHeaders($headers)
        ->getJson('/api/v1/analytics/comparison?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
        ->assertOk();

    expect($clean->json('data.metrics.revenue.current'))->toBe('0.0000');
    expect($clean->json('data.metrics.orders.current'))->toBe(0);

    // Business A still sees its own sale.
    app('auth')->forgetGuards();
    $mine = $this->withHeaders($a['headers'])
        ->getJson('/api/v1/analytics/comparison?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
        ->assertOk();

    expect($mine->json('data.metrics.revenue.current'))->toBe('20000.0000');
});

test('unknown report types are rejected', function () {
    $ctx = analyticsSetup($this, 'BAD');

    $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/saved-reports', ['name' => 'Nope', 'type' => 'made_up'])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/analytics/export/made_up')
        ->assertStatus(404);
});

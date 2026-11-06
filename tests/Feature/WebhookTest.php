<?php

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;

function webhookSetup($test, string $suffix = 'WH'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $owner = User::factory()->create();
    $token = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Webhook Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Webhook Outlet $suffix", 'code' => "WH-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Webhook Item $suffix",
            'sku' => "WH-$suffix",
            'selling_price' => 8000,
            'track_inventory' => true,
        ])
        ->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 10,
        'reason' => 'seed',
    ])->assertOk();

    return compact('owner', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function createWebhookEndpoint($test, array $ctx, array $payload = []): array
{
    return $test->withHeaders($ctx['headers'])
        ->postJson('/api/v1/webhooks/endpoints', array_merge([
            'name' => 'Order listener',
            'url' => 'https://example.test/hooks/velora',
            'events' => ['sale.completed'],
        ], $payload))
        ->assertStatus(201)
        ->json('data');
}

test('a test delivery is signed and recorded as delivered', function () {
    $ctx = webhookSetup($this, 'SIG');
    $created = createWebhookEndpoint($this, $ctx);

    expect($created['secret'])->not->toBeEmpty();

    Http::fake(['*' => Http::response(['received' => true], 200)]);

    $delivery = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/webhooks/endpoints/{$created['endpoint']['id']}/test")
        ->assertOk()
        ->json('data.delivery');

    expect($delivery['status'])->toBe('delivered');
    expect($delivery['response_status'])->toBe(200);

    Http::assertSent(function ($request) use ($created) {
        parse_str(str_replace(',', '&', $request->header('X-Velora-Signature')[0]), $signature);
        $expected = hash_hmac('sha256', $signature['t'].'.'.$request->body(), $created['secret']);

        return $request->header('X-Velora-Event')[0] === 'webhook.test'
            && hash_equals($expected, $signature['v1']);
    });
});

test('failed deliveries are logged and can be retried', function () {
    $ctx = webhookSetup($this, 'FAIL');
    $created = createWebhookEndpoint($this, $ctx);

    $failing = true;
    Http::fake(function () use (&$failing) {
        return $failing
            ? Http::response('server error', 500)
            : Http::response([], 200);
    });

    $failed = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/webhooks/endpoints/{$created['endpoint']['id']}/test")
        ->assertOk()
        ->json('data.delivery');

    expect($failed['status'])->toBe('failed');
    expect($failed['response_status'])->toBe(500);
    expect($failed['next_attempt_at'])->not->toBeNull();

    $endpoint = WebhookEndpoint::find($created['endpoint']['id']);
    expect($endpoint->failure_count)->toBe(1);

    $log = $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/webhooks/deliveries?status=failed')
        ->assertOk()
        ->json('data');

    expect($log['total'])->toBe(1);

    $failing = false;

    $retried = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/webhooks/deliveries/{$failed['id']}/retry")
        ->assertOk()
        ->json('data.delivery');

    expect($retried['status'])->toBe('delivered');
    expect($retried['attempts'])->toBe(2);
    expect($endpoint->fresh()->failure_count)->toBe(0);
});

test('a completed sale dispatches its subscribed event', function () {
    $ctx = webhookSetup($this, 'SALE');
    createWebhookEndpoint($this, $ctx, ['events' => ['sale.completed']]);

    Http::fake(['*' => Http::response([], 200)]);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(201);

    $delivery = WebhookDelivery::where('event', 'sale.completed')->first();

    expect($delivery)->not->toBeNull();
    expect($delivery->status)->toBe('delivered');
    expect($delivery->payload['data']['sale_id'])->toBeString();
    expect($delivery->payload['data']['total'])->toBe('8000.0000');
});

test('webhook endpoints and delivery logs are tenant scoped', function () {
    $ctx = webhookSetup($this, 'ISO');
    $created = createWebhookEndpoint($this, $ctx);

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)
        ->postJson('/api/v1/businesses', ['name' => 'Webhook Other ISO'])
        ->json('data');
    $otherHeaders = array_merge($otherAuth, ['X-Business-Id' => $otherBiz['id']]);

    $this->withHeaders($otherHeaders)
        ->getJson('/api/v1/webhooks/endpoints')
        ->assertOk()
        ->assertJsonCount(0, 'data.endpoints');

    $this->withHeaders($otherHeaders)
        ->putJson("/api/v1/webhooks/endpoints/{$created['endpoint']['id']}", ['name' => 'Hijack'])
        ->assertStatus(404);

    $this->withHeaders($otherHeaders)
        ->getJson('/api/v1/webhooks/deliveries')
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

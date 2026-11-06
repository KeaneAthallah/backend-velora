<?php

use App\Models\ApiKey;
use App\Models\User;

function apiKeySetup($test, string $suffix = 'AK'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $owner = User::factory()->create();
    $token = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "API Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "API Outlet $suffix", 'code' => "AK-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "API Item $suffix",
            'sku' => "AK-$suffix",
            'selling_price' => 5000,
        ])
        ->json('data');

    return compact('owner', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function createApiKey($test, array $ctx, array $payload = []): array
{
    return $test->withHeaders($ctx['headers'])
        ->postJson('/api/v1/api-keys', array_merge(['name' => 'Integration'], $payload))
        ->assertStatus(201)
        ->json('data');
}

/** Start a fresh request authenticated only by the given key. */
function withApiKey($test, string $key, array $extra = []): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    return array_merge(['X-API-Key' => $key], $extra);
}

/** Switch back to a bearer session after a key request. */
function withBearer($test, array $headers): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    return $headers;
}

test('an api key is returned once and authenticates without a bearer token', function () {
    $ctx = apiKeySetup($this, 'USE');

    $created = createApiKey($this, $ctx);
    expect($created['key'])->toStartWith('vlr_');
    expect($created['api_key']['prefix'])->toBe(implode('_', array_slice(explode('_', $created['key']), 0, 2)));

    // Only the hash is stored.
    $stored = ApiKey::first();
    expect($stored->key_hash)->toBe(hash('sha256', $created['key']));
    expect($stored->getAttributes())->not->toHaveKey('key');

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.data.0.name', 'API Item USE');

    // The plain key must not leak through the listing.
    $this->withHeaders(withBearer($this, $ctx['headers']))
        ->getJson('/api/v1/api-keys')
        ->assertOk()
        ->assertJsonMissing(['key' => $created['key']]);
});

test('invalid and revoked keys are rejected', function () {
    $ctx = apiKeySetup($this, 'REV');
    $created = createApiKey($this, $ctx);

    $this->withHeaders(withApiKey($this, 'vlr_nope_nope'))
        ->getJson('/api/v1/products')
        ->assertStatus(401);

    $this->withHeaders(withBearer($this, $ctx['headers']))
        ->deleteJson("/api/v1/api-keys/{$created['api_key']['id']}")
        ->assertOk();

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertStatus(401);
});

test('read only keys cannot write', function () {
    $ctx = apiKeySetup($this, 'RO');
    $created = createApiKey($this, $ctx, ['scopes' => ['read']]);

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertOk();

    $this->withHeaders(withApiKey($this, $created['key']))
        ->postJson('/api/v1/products', ['name' => 'Blocked', 'sku' => 'AK-RO-2', 'selling_price' => 1000])
        ->assertStatus(403);
});

test('rotating a key invalidates the previous secret', function () {
    $ctx = apiKeySetup($this, 'ROT');
    $created = createApiKey($this, $ctx);

    $rotated = $this->withHeaders(withBearer($this, $ctx['headers']))
        ->postJson("/api/v1/api-keys/{$created['api_key']['id']}/rotate")
        ->assertOk()
        ->json('data');

    expect($rotated['key'])->not->toBe($created['key']);

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertStatus(401);

    $this->withHeaders(withApiKey($this, $rotated['key']))
        ->getJson('/api/v1/products')
        ->assertOk();
});

test('a key is pinned to its own business', function () {
    $ctx = apiKeySetup($this, 'PIN');
    $created = createApiKey($this, $ctx);

    // A second tenant.
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $otherBiz = $this->withHeaders(['Authorization' => "Bearer $otherToken"])
        ->postJson('/api/v1/businesses', ['name' => 'API Other PIN'])
        ->json('data');

    $response = $this->withHeaders(withApiKey($this, $created['key'], ['X-Business-Id' => $otherBiz['id']]))
        ->getJson('/api/v1/products')
        ->assertOk();

    // The key's own catalogue is returned, not the requested tenant's.
    expect($response->json('data.data.0.name'))->toBe('API Item PIN');
});

test('each key honours its own rate limit', function () {
    $ctx = apiKeySetup($this, 'LIM');
    $created = createApiKey($this, $ctx, ['rate_limit' => 1]);

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertOk();

    $this->withHeaders(withApiKey($this, $created['key']))
        ->getJson('/api/v1/products')
        ->assertStatus(429);
});

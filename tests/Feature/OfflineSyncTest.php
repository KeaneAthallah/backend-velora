<?php

use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

function offlineSetup($test): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Offline Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];
    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Kopi Tubruk',
        'sku' => 'OFF-KOPI',
        'selling_price' => 12000,
        'track_inventory' => false,
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

test('the offline snapshot carries everything the register sells with', function () {
    $ctx = offlineSetup($this);
    Customer::create(['business_id' => $ctx['biz']['id'], 'name' => 'Siti', 'phone' => '0812000001']);

    $res = $this->withHeaders($ctx['headers'])->getJson('/api/v1/sync/bootstrap')->assertStatus(200);

    expect($res->json('data.outlet.id'))->toBe($ctx['outlet']['id']);
    expect($res->json('data.business.currency'))->toBe('IDR');
    expect($res->json('data.outlets'))->toHaveCount(1);
    expect(collect($res->json('data.menu'))->pluck('name')->all())->toContain('Kopi Tubruk');
    expect(collect($res->json('data.menu'))->firstWhere('name', 'Kopi Tubruk')['available'])->toBeTrue();
    expect(collect($res->json('data.payment_methods'))->pluck('code')->all())
        ->toBe(['cash', 'qris', 'debit_card', 'credit_card', 'ewallet', 'transfer']);
    expect(collect($res->json('data.customers'))->pluck('name')->all())->toBe(['Siti']);
    expect($res->json('data.server_time'))->toBeString();
});

test('the offline snapshot is limited to the business', function () {
    $ctx = offlineSetup($this);
    $other = offlineSetup($this);

    Customer::create(['business_id' => $other['biz']['id'], 'name' => 'Someone else', 'phone' => '0812999']);

    // Sanctum remembers the first resolved user for the whole test run.
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $res = $this->withHeaders($ctx['headers'])->getJson('/api/v1/sync/bootstrap')->assertStatus(200);

    expect(collect($res->json('data.customers'))->pluck('name')->all())->toBe([]);
    // Only this business's own catalogue, not the second tenant's copy.
    expect($res->json('data.menu'))->toHaveCount(1);
    expect($res->json('data.outlets'))->toHaveCount(1);

    // Another tenant's outlet cannot be asked for when no outlet header pins one.
    $this->flushHeaders();
    $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/sync/bootstrap?outlet_id='.$other['outlet']['id'])
        ->assertStatus(404);
});

test('the offline snapshot honours the customer limit', function () {
    $ctx = offlineSetup($this);
    foreach (range(1, 5) as $index) {
        Customer::create([
            'business_id' => $ctx['biz']['id'],
            'name' => 'Guest '.$index,
            'phone' => '08120000'.$index,
        ]);
    }

    $res = $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/sync/bootstrap?customers_limit=3')
        ->assertStatus(200);

    expect($res->json('data.customers'))->toHaveCount(3);
});

test('cashiers can take a snapshot without the menu back office permission', function () {
    $ctx = offlineSetup($this);

    $cashier = User::factory()->create();
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'slug' => 'cashier', 'name' => 'Cashier']);
    $role->permissions()->sync(Permission::whereIn('name', ['sales.create', 'sales.view'])->pluck('id'));
    BusinessMembership::create([
        'user_id' => $cashier->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $token = $cashier->createToken('t')->plainTextToken;

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $headers = [
        'Authorization' => "Bearer $token",
        'X-Business-Id' => $ctx['biz']['id'],
    ];

    // Selling needs the catalogue; managing the menu back office still does not.
    $this->withHeaders($headers)->getJson('/api/v1/sync/bootstrap')->assertStatus(200);
    $this->withHeaders($headers)->getJson('/api/v1/menu')->assertStatus(403);
});

test('the offline snapshot needs the sale permission', function () {
    $ctx = offlineSetup($this);

    $viewer = User::factory()->create();
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'slug' => 'viewer', 'name' => 'Viewer']);
    $role->permissions()->sync(Permission::whereIn('name', ['sales.view'])->pluck('id'));
    BusinessMembership::create([
        'user_id' => $viewer->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $token = $viewer->createToken('t')->plainTextToken;

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders([
        'Authorization' => "Bearer $token",
        'X-Business-Id' => $ctx['biz']['id'],
    ])->getJson('/api/v1/sync/bootstrap')->assertStatus(403);
});

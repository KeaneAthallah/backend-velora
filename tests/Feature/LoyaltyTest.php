<?php

use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;

function loyaltySetup($test, string $name = 'Loyalty Biz'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => $name])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];
    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Kopi Susu',
        'sku' => 'LOY-KOPI',
        'selling_price' => 10000,
        'track_inventory' => false,
    ])->json('data');

    $customer = $test->withHeaders($bizHeaders)->postJson('/api/v1/customers', [
        'name' => 'Sari',
        'phone' => '081200000001',
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'customer');
}

function loyaltySale($test, array $ctx, array $payload = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/sales', array_merge([
        'outlet_id' => $ctx['outlet']['id'],
        'customer_id' => $ctx['customer']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ], $payload));
}

function loyaltyGrant($test, array $ctx, int $points, ?string $customerId = null)
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson(
        '/api/v1/customers/'.($customerId ?? $ctx['customer']['id']).'/loyalty/adjust',
        ['points' => $points, 'reason' => 'Test grant']
    );
}

test('a completed sale earns points and updates the customer profile', function () {
    $ctx = loyaltySetup($this);

    $program = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/loyalty/program')
        ->assertStatus(200)
        ->json('data');

    expect($program['is_active'])->toBeTrue();
    expect($program['points_per_amount'])->toBe('0.0001');
    expect($program['redeem_value'])->toBe('100.0000');
    expect($program['min_redeem_points'])->toBe('10.0000');

    $sale = loyaltySale($this, $ctx)->assertStatus(201)->json('data');
    expect($sale['loyalty_points_earned'])->toBe('2.0000');

    $customer = Customer::find($ctx['customer']['id']);
    expect((string) $customer->loyalty_points)->toBe('2.0000');
    expect((string) $customer->total_spent)->toBe('20000.0000');
    expect($customer->visits_count)->toBe(1);
    expect($customer->loyalty_joined_at)->not->toBeNull();

    expect(LoyaltyTransaction::where('customer_id', $customer->id)->value('type'))->toBe('earn');
});

test('staff can redeem points at the register', function () {
    $ctx = loyaltySetup($this);
    loyaltyGrant($this, $ctx, 50)->assertStatus(200);

    $sale = loyaltySale($this, $ctx, ['redeem_points' => 10])->assertStatus(201)->json('data');

    expect($sale['loyalty_points_redeemed'])->toBe('10.0000');
    expect($sale['loyalty_discount'])->toBe('1000.0000');
    expect($sale['total'])->toBe('19000.0000');
    // 19000 spend earns 1.9 points on top.
    expect($sale['loyalty_points_earned'])->toBe('1.9000');

    $customer = Customer::find($ctx['customer']['id']);
    expect((string) $customer->loyalty_points)->toBe('41.9000');

    $types = LoyaltyTransaction::where('customer_id', $customer->id)->orderBy('id')->pluck('type')->all();
    expect($types)->toBe(['adjust', 'redeem', 'earn']);
});

test('redemptions are rejected without a customer, over the balance or under the minimum', function () {
    $ctx = loyaltySetup($this);
    loyaltyGrant($this, $ctx, 50);

    loyaltySale($this, $ctx, ['customer_id' => null, 'redeem_points' => 10])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    loyaltySale($this, $ctx, ['redeem_points' => 5])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    loyaltySale($this, $ctx, ['redeem_points' => 100])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    // 50 points are worth 5.000, more than this 20.000 cart once granted 1000.
    loyaltyGrant($this, $ctx, 950);
    loyaltySale($this, $ctx, ['redeem_points' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    expect(Sale::count())->toBe(0);
    expect((string) Customer::find($ctx['customer']['id'])->loyalty_points)->toBe('1000.0000');
});

test('voiding a sale puts earned and redeemed points back', function () {
    $ctx = loyaltySetup($this);
    loyaltyGrant($this, $ctx, 50);

    $sale = loyaltySale($this, $ctx, ['redeem_points' => 10])->json('data');
    expect((string) Customer::find($ctx['customer']['id'])->loyalty_points)->toBe('41.9000');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/void", [
        'reason' => 'Wrong order',
    ])->assertStatus(200);

    $customer = Customer::find($ctx['customer']['id']);
    expect((string) $customer->loyalty_points)->toBe('50.0000');
    expect((string) $customer->total_spent)->toBe('0.0000');
    expect($customer->visits_count)->toBe(0);
});

test('opening an order touches no points until checkout', function () {
    $ctx = loyaltySetup($this);
    loyaltyGrant($this, $ctx, 50);

    $order = $this->withHeaders($ctx['headers'])->postJson('/api/v1/orders', [
        'order_type' => 'takeaway',
        'customer_id' => $ctx['customer']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ])->assertStatus(201)->json('data');

    expect($order['status'])->toBe('draft');
    expect((string) Customer::find($ctx['customer']['id'])->loyalty_points)->toBe('50.0000');

    $checkedOut = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout", [
        'redeem_points' => 10,
        'payments' => [['method' => 'cash', 'amount' => 19000]],
    ])->assertStatus(200)->json('data');

    expect($checkedOut['loyalty_points_redeemed'])->toBe('10.0000');
    expect($checkedOut['total'])->toBe('19000.0000');
    expect((string) Customer::find($ctx['customer']['id'])->loyalty_points)->toBe('41.9000');
});

test('an inactive programme stops earning but keeps the balance', function () {
    $ctx = loyaltySetup($this);
    loyaltyGrant($this, $ctx, 50);

    $this->withHeaders($ctx['bizHeaders'])->putJson('/api/v1/loyalty/program', ['is_active' => false])
        ->assertStatus(200);

    $sale = loyaltySale($this, $ctx)->assertStatus(201)->json('data');
    expect($sale['loyalty_points_earned'])->toBe('0.0000');

    $customer = Customer::find($ctx['customer']['id']);
    expect((string) $customer->loyalty_points)->toBe('50.0000');
    expect($customer->visits_count)->toBe(1);
});

test('a manager can adjust points but not below zero', function () {
    $ctx = loyaltySetup($this);

    $res = loyaltyGrant($this, $ctx, 25)->assertStatus(200)->json('data');
    expect($res['loyalty_points'])->toBe('25.0000');

    loyaltyGrant($this, $ctx, -10)->assertStatus(200);
    expect((string) Customer::find($ctx['customer']['id'])->loyalty_points)->toBe('15.0000');

    loyaltyGrant($this, $ctx, -100)
        ->assertStatus(422)
        ->assertJsonPath('code', 'LOYALTY_ERROR');

    $this->withHeaders($ctx['bizHeaders'])->postJson(
        "/api/v1/customers/{$ctx['customer']['id']}/loyalty/adjust",
        ['points' => 0, 'reason' => 'Nothing']
    )->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
});

test('loyalty data is tenant scoped', function () {
    $a = loyaltySetup($this, 'Loyalty A');
    loyaltyGrant($this, $a, 25)->assertStatus(200);

    $b = loyaltySetup($this, 'Loyalty B');

    $this->withHeaders($b['bizHeaders'])->postJson(
        "/api/v1/customers/{$a['customer']['id']}/loyalty/adjust",
        ['points' => 10, 'reason' => 'Cross tenant']
    )->assertStatus(404);

    expect($this->withHeaders($b['bizHeaders'])->getJson('/api/v1/loyalty/transactions')->json('data.data'))
        ->toHaveCount(0);
});

test('adjusting points needs the loyalty permission', function () {
    $ctx = loyaltySetup($this);

    $staff = User::factory()->create();
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'slug' => 'cashier', 'name' => 'Cashier']);
    $role->permissions()->sync(Permission::whereIn('name', ['sales.view', 'sales.create'])->pluck('id'));
    BusinessMembership::create([
        'user_id' => $staff->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $staffHeaders = [
        'Authorization' => 'Bearer '.$staff->createToken('t')->plainTextToken,
        'X-Business-Id' => $ctx['biz']['id'],
    ];

    $this->withHeaders($staffHeaders)->postJson(
        "/api/v1/customers/{$ctx['customer']['id']}/loyalty/adjust",
        ['points' => 10, 'reason' => 'Nope']
    )->assertStatus(403);

    $this->withHeaders($staffHeaders)->putJson('/api/v1/loyalty/program', ['is_active' => false])
        ->assertStatus(403);

    $this->withHeaders($staffHeaders)->getJson('/api/v1/loyalty/program')->assertStatus(403);
});

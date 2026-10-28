<?php

use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;

function promoSetup($test, string $name = 'Promo Biz'): array
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
        'name' => 'Latte',
        'sku' => 'PROMO-LATTE',
        'selling_price' => 10000,
        'track_inventory' => false,
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function promoMake($test, array $ctx, array $payload)
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/promotions', $payload);
}

function promoSale($test, array $ctx, array $payload = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/sales', array_merge([
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ], $payload));
}

test('a percentage promotion code discounts a sale and counts the usage', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, [
        'name' => 'Ten percent',
        'code' => 'hemat10',
        'type' => 'percentage',
        'value' => 10,
    ])->assertStatus(201);

    $sale = promoSale($this, $ctx, ['promotion_code' => 'HEMAT10'])->assertStatus(201)->json('data');

    expect($sale['subtotal'])->toBe('20000.0000');
    expect($sale['discount_amount'])->toBe('0.0000');
    expect($sale['promotion_discount'])->toBe('2000.0000');
    expect($sale['total'])->toBe('18000.0000');

    $promotion = Promotion::where('code', 'HEMAT10')->first();
    expect($promotion->usage_count)->toBe(1);
    expect(PromotionRedemption::where('sale_id', $sale['id'])->value('amount'))->toBe('2000.0000');
});

test('a fixed promotion is capped by its maximum and by the cart value', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, [
        'name' => 'Capped',
        'code' => 'CAPPED',
        'type' => 'fixed',
        'value' => 5000,
        'max_discount' => 3000,
    ])->assertStatus(201);

    $sale = promoSale($this, $ctx, ['promotion_code' => 'CAPPED'])->json('data');
    expect($sale['promotion_discount'])->toBe('3000.0000');
    expect($sale['total'])->toBe('17000.0000');

    promoMake($this, $ctx, [
        'name' => 'Whole cart',
        'code' => 'FREE',
        'type' => 'fixed',
        'value' => 25000,
    ])->assertStatus(201);

    $free = promoSale($this, $ctx, ['promotion_code' => 'FREE'])->json('data');
    expect($free['promotion_discount'])->toBe('20000.0000');
    expect($free['total'])->toBe('0.0000');
});

test('expired promotions and minimum spend stop the code', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, [
        'name' => 'Old',
        'code' => 'OLD',
        'type' => 'percentage',
        'value' => 10,
        'ends_at' => now()->subDay()->toDateTimeString(),
    ])->assertStatus(201);

    promoMake($this, $ctx, [
        'name' => 'Big spend',
        'code' => 'BIG',
        'type' => 'percentage',
        'value' => 10,
        'min_spend' => 50000,
    ])->assertStatus(201);

    promoSale($this, $ctx, ['promotion_code' => 'OLD'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    promoSale($this, $ctx, ['promotion_code' => 'BIG'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    expect(Sale::count())->toBe(0);
});

test('usage limits and per customer limits are enforced', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, [
        'name' => 'Once ever',
        'code' => 'ONCE',
        'type' => 'fixed',
        'value' => 1000,
        'usage_limit' => 1,
    ])->assertStatus(201);

    promoSale($this, $ctx, ['promotion_code' => 'ONCE'])->assertStatus(201);
    promoSale($this, $ctx, ['promotion_code' => 'ONCE'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    $customer = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/customers', ['name' => 'Budi'])
        ->json('data');

    promoMake($this, $ctx, [
        'name' => 'Once per customer',
        'code' => 'MEMBER',
        'type' => 'fixed',
        'value' => 1000,
        'per_customer_limit' => 1,
    ])->assertStatus(201);

    // The limit needs a customer to count against.
    promoSale($this, $ctx, ['promotion_code' => 'MEMBER'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    promoSale($this, $ctx, ['promotion_code' => 'MEMBER', 'customer_id' => $customer['id']])
        ->assertStatus(201);

    promoSale($this, $ctx, ['promotion_code' => 'MEMBER', 'customer_id' => $customer['id']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');
});

test('the best automatic promotion applies without a code', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, ['name' => 'Small', 'type' => 'percentage', 'value' => 5])->assertStatus(201);
    promoMake($this, $ctx, ['name' => 'Best', 'type' => 'percentage', 'value' => 15])->assertStatus(201);

    $sale = promoSale($this, $ctx)->assertStatus(201)->json('data');

    expect($sale['promotion_discount'])->toBe('3000.0000');
    expect($sale['promotion_code'])->toBeNull();
    expect($sale['promotion_id'])->not->toBeNull();
    expect($sale['total'])->toBe('17000.0000');
});

test('preview prices a cart without creating a sale', function () {
    $ctx = promoSetup($this);
    promoMake($this, $ctx, [
        'name' => 'Ten percent',
        'code' => 'HEMAT10',
        'type' => 'percentage',
        'value' => 10,
    ])->assertStatus(201);

    $res = $this->withHeaders($ctx['headers'])->postJson('/api/v1/promotions/preview', [
        'promotion_code' => 'HEMAT10',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ])->assertStatus(200)->json('data');

    expect($res['promotion_discount'])->toBe('2000.0000');
    expect($res['total'])->toBe('18000.0000');
    expect($res['promotion']['name'])->toBe('Ten percent');
    expect(Sale::count())->toBe(0);
});

test('promotion validation rejects bad values and duplicate codes', function () {
    $ctx = promoSetup($this);

    promoMake($this, $ctx, ['name' => 'Too much', 'code' => 'MORE', 'type' => 'percentage', 'value' => 150])
        ->assertStatus(422);

    promoMake($this, $ctx, ['name' => 'Ten', 'code' => 'TEN', 'type' => 'percentage', 'value' => 10])
        ->assertStatus(201);

    promoMake($this, $ctx, ['name' => 'Ten again', 'code' => 'ten', 'type' => 'percentage', 'value' => 10])
        ->assertStatus(422);

    promoMake($this, $ctx, ['name' => 'Buy two', 'code' => 'B2G1', 'type' => 'buy_x_get_y', 'value' => 1])
        ->assertStatus(422);
});

test('a redeemed promotion cannot be deleted', function () {
    $ctx = promoSetup($this);
    $promotion = promoMake($this, $ctx, [
        'name' => 'Delete me',
        'code' => 'DEL',
        'type' => 'fixed',
        'value' => 1000,
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/promotions/{$promotion['id']}")
        ->assertStatus(200);

    $unused = promoMake($this, $ctx, ['name' => 'Used', 'code' => 'USED', 'type' => 'fixed', 'value' => 1000])
        ->json('data');
    promoSale($this, $ctx, ['promotion_code' => 'USED'])->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/promotions/{$unused['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PROMOTION_IN_USE');
});

test('promotions are tenant scoped', function () {
    $a = promoSetup($this, 'Biz A');
    promoMake($this, $a, ['name' => 'A only', 'code' => 'AONLY', 'type' => 'fixed', 'value' => 1000])
        ->assertStatus(201);
    $promotion = Promotion::where('code', 'AONLY')->first();

    $b = promoSetup($this, 'Biz B');

    expect($this->withHeaders($b['bizHeaders'])->getJson('/api/v1/promotions')->json('data'))->toHaveCount(0);

    promoSale($this, $b, ['promotion_code' => 'AONLY'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SALE_ERROR');

    $this->withHeaders($b['bizHeaders'])->putJson("/api/v1/promotions/{$promotion->id}", ['name' => 'Hijacked'])
        ->assertStatus(404);
    $this->withHeaders($b['bizHeaders'])->deleteJson("/api/v1/promotions/{$promotion->id}")
        ->assertStatus(404);
});

test('promotion management needs the promotions permission', function () {
    $ctx = promoSetup($this);

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

    $this->withHeaders($staffHeaders)->getJson('/api/v1/promotions')->assertStatus(403);
    $this->withHeaders($staffHeaders)->postJson('/api/v1/promotions', [
        'name' => 'Nope',
        'type' => 'percentage',
        'value' => 5,
    ])->assertStatus(403);
});

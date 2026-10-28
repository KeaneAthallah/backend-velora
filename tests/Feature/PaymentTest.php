<?php

use App\Models\BusinessMembership;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;

function paymentSetup($test): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Payments Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];
    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Americano',
        'sku' => 'PAY-AME',
        'selling_price' => 10000,
        'cost_price' => 4000,
        'track_inventory' => false,
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product');
}

function paymentSale($test, array $ctx, array $payload = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/sales', array_merge([
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ], $payload));
}

function paymentMethods($test, array $ctx)
{
    return $test->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/payment-methods');
}

test('a business gets the default payment methods', function () {
    $ctx = paymentSetup($this);

    $methods = paymentMethods($this, $ctx)->assertStatus(200)->json('data');
    expect($methods)->toHaveCount(6);
    expect(collect($methods)->pluck('code')->all())
        ->toBe(['cash', 'qris', 'debit_card', 'credit_card', 'ewallet', 'transfer']);
    expect(collect($methods)->firstWhere('code', 'cash')['type'])->toBe('cash');
});

test('split payments cover the total and record change from cash', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->assertStatus(201)->json('data');

    $res = $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [
            ['method' => 'cash', 'amount' => 15000],
            ['method' => 'qris', 'amount' => 5000],
        ],
    ])->assertStatus(201);

    expect($res->json('data.paid_amount'))->toBe('20000.0000');
    expect($res->json('data.change_amount'))->toBe('0.0000');
    expect($res->json('data.payments'))->toHaveCount(2);
    expect(collect($res->json('data.payments'))->pluck('method')->all())->toBe(['cash', 'qris']);

    $cash = $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 25000]],
    ])->assertStatus(201);

    expect($cash->json('data.paid_amount'))->toBe('25000.0000');
    expect($cash->json('data.change_amount'))->toBe('5000.0000');
});

test('payments must cover the total and change only comes from cash', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 15000]],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'qris', 'amount' => 25000]],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');

    // The cash share is smaller than the change that would be owed.
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [
            ['method' => 'cash', 'amount' => 2000],
            ['method' => 'qris', 'amount' => 25000],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');

    expect(Payment::where('sale_id', $sale['id'])->count())->toBe(0);
});

test('unknown or inactive payment methods are rejected', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'crypto', 'amount' => 20000]],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');

    $qris = collect(paymentMethods($this, $ctx)->json('data'))->firstWhere('code', 'qris');
    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/payment-methods/{$qris['id']}", ['is_active' => false])
        ->assertStatus(200);

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'qris', 'amount' => 20000]],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');
});

test('a payment batch is idempotent and a paid sale cannot be charged twice', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $payload = [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
        'idempotency_key' => 'pay-batch-1',
    ];

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", $payload)
        ->assertStatus(201);
    $again = $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", $payload)
        ->assertStatus(201);

    expect($again->json('data.payments'))->toHaveCount(1);

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_ERROR');

    expect(Payment::where('sale_id', $sale['id'])->count())->toBe(1);
});

test('a failed payment rolls back the whole sale', function () {
    $ctx = paymentSetup($this);

    paymentSale($this, $ctx, [
        'idempotency_key' => 'sale-with-bad-payment',
        'payments' => [['method' => 'cash', 'amount' => 1000]],
    ])->assertStatus(422)->assertJsonPath('code', 'SALE_ERROR');

    expect(Sale::where('business_id', $ctx['biz']['id'])->count())->toBe(0);
});

test('order checkout accepts payments atomically', function () {
    $ctx = paymentSetup($this);

    $order = $this->withHeaders($ctx['headers'])->postJson('/api/v1/orders', [
        'order_type' => 'takeaway',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 3]],
    ])->assertStatus(201)->json('data');

    $checked = $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout", [
        'payments' => [
            ['method' => 'cash', 'amount' => 20000],
            ['method' => 'qris', 'amount' => 10000],
        ],
    ])->assertStatus(200);

    expect($checked->json('data.total'))->toBe('30000.0000');
    expect($checked->json('data.paid_amount'))->toBe('30000.0000');
    expect($checked->json('data.payments'))->toHaveCount(2);
});

test('payment methods can be created, edited and deleted when unused', function () {
    $ctx = paymentSetup($this);

    $created = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/payment-methods', [
        'name' => 'ShopeePay',
        'type' => 'ewallet',
        'requires_reference' => true,
    ])->assertStatus(201)->json('data');

    expect($created['code'])->toBe('shopeepay');
    expect($created['requires_reference'])->toBeTrue();

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/payment-methods', ['name' => 'ShopeePay'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/payment-methods/{$created['id']}", [
        'name' => 'ShopeePay e-wallet',
        'sort_order' => 7,
    ])->assertStatus(200)->assertJsonPath('data.name', 'ShopeePay e-wallet');

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/payment-methods/{$created['id']}")
        ->assertStatus(200);

    expect(paymentMethods($this, $ctx)->json('data'))->toHaveCount(6);
});

test('a payment method with payments cannot be deleted', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'qris', 'amount' => 20000]],
    ])->assertStatus(201);

    $qris = collect(paymentMethods($this, $ctx)->json('data'))->firstWhere('code', 'qris');
    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/payment-methods/{$qris['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYMENT_METHOD_IN_USE');
});

test('payment methods and sale payments are tenant scoped', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');
    $cash = collect(paymentMethods($this, $ctx)->json('data'))->firstWhere('code', 'cash');

    $other = paymentSetup($this);

    $this->withHeaders($other['bizHeaders'])->putJson("/api/v1/payment-methods/{$cash['id']}", ['name' => 'Hijack'])
        ->assertStatus(404);
    $this->withHeaders($other['bizHeaders'])->deleteJson("/api/v1/payment-methods/{$cash['id']}")
        ->assertStatus(404);
    $this->withHeaders($other['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ])->assertStatus(404);
});

test('payment method management needs the payments permission', function () {
    $ctx = paymentSetup($this);
    $cash = collect(paymentMethods($this, $ctx)->json('data'))->firstWhere('code', 'cash');

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
    $staffToken = $staff->createToken('t')->plainTextToken;

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $staffHeaders = [
        'Authorization' => "Bearer $staffToken",
        'X-Business-Id' => $ctx['biz']['id'],
    ];

    $this->withHeaders($staffHeaders)->getJson('/api/v1/payment-methods')->assertStatus(403);
    $this->withHeaders($staffHeaders)->putJson("/api/v1/payment-methods/{$cash['id']}", ['name' => 'Nope'])
        ->assertStatus(403);
});

test('refunds are filtered, approved and roll the sale and payment forward', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $paid = $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ])->assertStatus(201)->json('data.payments.0');

    $refund = $this->withHeaders($ctx['headers'])->postJson("/api/v1/payments/{$paid['id']}/refund", [
        'amount' => 5000,
        'reason' => 'Spilled drink',
    ])->assertStatus(201)->json('data');

    expect($refund['status'])->toBe('pending');

    $listed = $this->withHeaders($ctx['headers'])->getJson('/api/v1/refunds?status=pending&sale_id='.$sale['id'])
        ->assertStatus(200)
        ->json('data');
    expect($listed['total'])->toBe(1);
    expect($listed['data'][0]['payment']['method'])->toBe('cash');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/refunds/{$refund['id']}/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    expect(Payment::find($paid['id'])->status)->toBe('partially_refunded');
    expect(Sale::find($sale['id'])->status)->toBe('partially_refunded');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/refunds/{$refund['id']}/approve")
        ->assertStatus(422)
        ->assertJsonPath('code', 'REFUND_ERROR');
});

test('a refund cannot exceed the payment and can be rejected', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $paid = $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ])->json('data.payments.0');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/payments/{$paid['id']}/refund", [
        'amount' => 25000,
    ])->assertStatus(422)->assertJsonPath('code', 'REFUND_ERROR');

    $refund = $this->withHeaders($ctx['headers'])->postJson("/api/v1/payments/{$paid['id']}/refund", [
        'amount' => 10000,
        'reason' => 'Wrong order',
    ])->assertStatus(201)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/refunds/{$refund['id']}/reject")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected');

    expect(Payment::find($paid['id'])->status)->toBe('completed');
});

test('voiding a paid sale marks its payments refunded', function () {
    $ctx = paymentSetup($this);
    $sale = paymentSale($this, $ctx)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/payments", [
        'payments' => [['method' => 'cash', 'amount' => 20000]],
    ])->assertStatus(201);

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/sales/{$sale['id']}/void", [
        'reason' => 'Customer changed their mind',
    ])->assertStatus(200);

    expect(Payment::where('sale_id', $sale['id'])->pluck('status')->all())->toBe(['refunded']);
});

<?php

use App\Models\InventoryBalance;
use App\Models\User;

/**
 * Tenant isolation for stock transfers, cash registers and shifts: ids from
 * another business must never be accepted, and a completed transfer must not
 * move stock twice.
 */
function tenantBiz($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Tenant $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outletA = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "A $suffix", 'code' => "TA-$suffix"])
        ->json('data');
    $outletB = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "B $suffix", 'code' => "TB-$suffix"])
        ->json('data');

    $headersA = array_merge($bizHeaders, ['X-Outlet-Id' => $outletA['id']]);
    $headersB = array_merge($bizHeaders, ['X-Outlet-Id' => $outletB['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Item $suffix",
            'sku' => "TEN-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    $register = $test->withHeaders($headersA)
        ->postJson('/api/v1/cash-registers', ['name' => "Register $suffix", 'code' => "REG-$suffix"])
        ->json('data');

    $test->withHeaders($headersA)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 10,
        'reason' => 'seed',
    ])->assertOk();

    return [
        'user' => $user,
        'auth' => $auth,
        'biz' => $biz,
        'bizHeaders' => $bizHeaders,
        'outletA' => $outletA,
        'outletB' => $outletB,
        'headersA' => $headersA,
        'headersB' => $headersB,
        'product' => $product,
        'register' => $register,
    ];
}

test('stock transfers reject another business outlet and product', function () {
    $a = tenantBiz($this, 'SA');
    $b = tenantBiz($this, 'SB');

    // Other business's destination outlet.
    app('auth')->forgetGuards();
    $this->withHeaders($a['headersA'])->postJson('/api/v1/stock-transfers', [
        'from_outlet_id' => $a['outletA']['id'],
        'to_outlet_id' => $b['outletA']['id'],
        'items' => [['product_id' => $a['product']['id'], 'quantity' => 2]],
    ])->assertStatus(422);

    // Own outlets, other business's product.
    $this->withHeaders($a['headersA'])->postJson('/api/v1/stock-transfers', [
        'from_outlet_id' => $a['outletA']['id'],
        'to_outlet_id' => $a['outletB']['id'],
        'items' => [['product_id' => $b['product']['id'], 'quantity' => 2]],
    ])->assertStatus(422);
});

test('a stock transfer can only be completed once', function () {
    $a = tenantBiz($this, 'C');

    $transfer = $this->withHeaders($a['headersA'])->postJson('/api/v1/stock-transfers', [
        'from_outlet_id' => $a['outletA']['id'],
        'to_outlet_id' => $a['outletB']['id'],
        'items' => [['product_id' => $a['product']['id'], 'quantity' => 4]],
    ])->assertStatus(201)->json('data');

    $this->withHeaders($a['headersA'])
        ->postJson("/api/v1/stock-transfers/{$transfer['id']}/complete")
        ->assertOk();

    // A retried/duplicated complete must not move stock a second time.
    $this->withHeaders($a['headersA'])
        ->postJson("/api/v1/stock-transfers/{$transfer['id']}/complete")
        ->assertStatus(422);

    $from = InventoryBalance::where('outlet_id', $a['outletA']['id'])
        ->where('product_id', $a['product']['id'])
        ->first();
    $to = InventoryBalance::where('outlet_id', $a['outletB']['id'])
        ->where('product_id', $a['product']['id'])
        ->first();

    expect($from->quantity)->toBe('6.0000');
    expect($to->quantity)->toBe('4.0000');
});

test('categories cannot point at another business parent', function () {
    $a = tenantBiz($this, 'CA');
    $b = tenantBiz($this, 'CB');

    $foreign = $this->withHeaders($b['headersA'])
        ->postJson('/api/v1/categories', ['name' => 'Foreign root'])
        ->assertStatus(201)
        ->json('data');

    app('auth')->forgetGuards();

    $this->withHeaders($a['headersA'])->postJson('/api/v1/categories', [
        'name' => 'Child of foreign',
        'parent_id' => $foreign['id'],
    ])->assertStatus(422);

    $own = $this->withHeaders($a['headersA'])
        ->postJson('/api/v1/categories', ['name' => 'Own root'])
        ->assertStatus(201)
        ->json('data');

    $this->withHeaders($a['headersA'])->postJson('/api/v1/categories', [
        'name' => 'Child of own',
        'parent_id' => $own['id'],
    ])->assertStatus(201);
});

test('shifts cannot open another business register or use a mismatched outlet', function () {
    $a = tenantBiz($this, 'SHA');
    $b = tenantBiz($this, 'SHB');

    // Another business's register.
    app('auth')->forgetGuards();
    $this->withHeaders($a['headersA'])->postJson('/api/v1/shifts/open', [
        'cash_register_id' => $b['register']['id'],
        'opening_cash' => 100,
    ])->assertStatus(422);

    // Own register, but requested under a different outlet.
    $this->withHeaders($a['headersB'])->postJson('/api/v1/shifts/open', [
        'cash_register_id' => $a['register']['id'],
        'opening_cash' => 100,
    ])->assertStatus(422);

    // The valid combination still works.
    $this->withHeaders($a['headersA'])->postJson('/api/v1/shifts/open', [
        'cash_register_id' => $a['register']['id'],
        'opening_cash' => 100,
    ])->assertStatus(201);
});

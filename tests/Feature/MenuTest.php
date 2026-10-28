<?php

use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;

function menuSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Menu Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $category = $test->withHeaders($bizHeaders)->postJson('/api/v1/categories', ['name' => 'Coffee'])->json('data');
    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Latte',
        'sku' => 'LAT-1',
        'category_id' => $category['id'],
        'selling_price' => 10000,
        'cost_price' => 4000,
        'track_inventory' => true,
    ])->json('data');

    $saleHeaders = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];
    $test->withHeaders($saleHeaders)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 100,
        'reason' => 'init',
    ]);

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'category', 'product', 'saleHeaders');
}

test('modifier group crud and attachment', function () {
    $ctx = menuSetup($this);

    $created = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Milk',
        'selection_type' => 'single',
        'is_required' => true,
        'options' => [
            ['name' => 'Whole', 'price_delta' => 0, 'is_default' => true],
            ['name' => 'Oat', 'price_delta' => 5000],
        ],
    ]);

    $created->assertStatus(201);
    $group = $created->json('data');
    expect($group['options'])->toHaveCount(2);

    $attach = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/modifier-groups/{$group['id']}/attach", [
        'assignable_type' => 'product',
        'assignable_id' => $ctx['product']['id'],
    ]);
    $attach->assertStatus(200);

    $menu = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id']);
    $menu->assertStatus(200);

    $product = collect($menu->json('data.products'))->firstWhere('id', (int) $ctx['product']['id']);
    expect($product['modifier_groups'])->toHaveCount(1);
    expect($product['modifier_groups'][0]['options'])->toHaveCount(2);
    expect($product['available'])->toBeTrue();

    $updated = $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/modifier-groups/{$group['id']}", [
        'name' => 'Milk choice',
    ]);
    $updated->assertStatus(200);
    expect($updated->json('data.name'))->toBe('Milk choice');

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/modifier-groups/{$group['id']}")->assertStatus(200);
});

test('sale snapshots selected modifiers and charges the delta', function () {
    $ctx = menuSetup($this);

    $group = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Milk',
        'selection_type' => 'single',
        'is_required' => true,
        'options' => [
            ['name' => 'Whole', 'price_delta' => 0, 'is_default' => true],
            ['name' => 'Oat', 'price_delta' => 5000],
        ],
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/modifier-groups/{$group['id']}/attach", [
        'assignable_type' => 'product',
        'assignable_id' => $ctx['product']['id'],
    ])->assertStatus(200);

    $oat = collect($group['options'])->firstWhere('name', 'Oat');

    $sale = $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [[
            'product_id' => $ctx['product']['id'],
            'quantity' => 2,
            'option_ids' => [$oat['id']],
        ]],
    ]);

    $sale->assertStatus(201);
    expect($sale->json('data.total'))->toBe('30000.0000');

    $item = $sale->json('data.items.0');
    expect($item['base_unit_price'])->toBe('10000.0000');
    expect($item['unit_price'])->toBe('15000.0000');
    expect($item['modifiers'])->toHaveCount(1);
    expect($item['modifiers'][0]['option_name'])->toBe('Oat');
    expect($item['modifiers'][0]['price_delta'])->toBe('5000.0000');
});

test('required modifier group blocks the sale when empty', function () {
    $ctx = menuSetup($this);

    $group = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Milk',
        'selection_type' => 'single',
        'is_required' => true,
        'options' => [['name' => 'Oat', 'price_delta' => 5000]],
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/modifier-groups/{$group['id']}/attach", [
        'assignable_type' => 'product',
        'assignable_id' => $ctx['product']['id'],
    ])->assertStatus(200);

    $sale = $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);

    $sale->assertStatus(422);
});

test('eighty six item cannot be sold and can be restored', function () {
    $ctx = menuSetup($this);

    $this->withHeaders($ctx['saleHeaders'])->postJson("/api/v1/menu/items/{$ctx['product']['id']}/86", [
        'reason' => 'Out of beans',
    ])->assertStatus(200);

    $menu = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id']);
    $product = collect($menu->json('data.products'))->firstWhere('id', (int) $ctx['product']['id']);
    expect($product['available'])->toBeFalse();
    expect($product['unavailable_reason'])->toBe('Out of beans');

    $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(422);

    $this->withHeaders($ctx['saleHeaders'])->postJson("/api/v1/menu/items/{$ctx['product']['id']}/restore")
        ->assertStatus(200);

    $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(201);
});

test('dayparts restrict product availability and sales', function () {
    $ctx = menuSetup($this);

    $local = Carbon::now('Asia/Jakarta');
    $start = $local->copy()->subMinutes(5)->format('H:i');
    $end = $local->copy()->addMinutes(5)->format('H:i');
    $tomorrow = (int) $local->copy()->addDay()->dayOfWeek;

    $created = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/dayparts', [
        'name' => 'Always on',
        'start_time' => $start,
        'end_time' => $end,
        'days' => [],
    ]);
    $created->assertStatus(201);
    $daypart = $created->json('data');

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/dayparts", [
        'daypart_ids' => [$daypart['id']],
    ])->assertStatus(200);

    $menu = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id']);
    $product = collect($menu->json('data.products'))->firstWhere('id', (int) $ctx['product']['id']);
    expect($product['available'])->toBeTrue();
    expect($product['daypart_ids'])->toContain((string) $daypart['id']);

    // Restrict the window to a different weekday so it cannot match today.
    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/dayparts/{$daypart['id']}", [
        'days' => [$tomorrow],
    ])->assertStatus(200);

    $menu = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id']);
    $product = collect($menu->json('data.products'))->firstWhere('id', (int) $ctx['product']['id']);
    expect($product['available'])->toBeFalse();
    expect($product['unavailable_reason'])->toBe('Outside daypart');

    $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(422);

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/dayparts/{$daypart['id']}")->assertStatus(200);
});

test('menu data is isolated between businesses', function () {
    $ctx = menuSetup($this);

    $group = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Size',
        'options' => [['name' => 'Large', 'price_delta' => 3000]],
    ])->json('data');

    $daypart = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/dayparts', [
        'name' => 'Morning',
        'start_time' => '08:00',
        'end_time' => '11:00',
    ])->json('data');

    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)->postJson('/api/v1/businesses', ['name' => 'Other Biz'])->json('data');
    $otherHeaders = $otherAuth + ['X-Business-Id' => $otherBiz['id']];

    $this->withHeaders($otherHeaders)->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/modifier-groups/{$group['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->deleteJson("/api/v1/modifier-groups/{$group['id']}")->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/dayparts/{$daypart['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/menu/items/{$ctx['product']['id']}/86", ['reason' => 'Nope'])->assertStatus(404);
});

test('menu management requires permissions', function () {
    $ctx = menuSetup($this);

    $cashier = User::factory()->create();
    $cashierToken = $cashier->createToken('t')->plainTextToken;
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'name' => 'Cashier', 'slug' => 'cashier']);
    BusinessMembership::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $cashier->id,
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $headers = ['Authorization' => "Bearer $cashierToken", 'X-Business-Id' => $ctx['biz']['id']];
    $this->app['auth']->forgetGuards();

    $this->withHeaders($headers)->postJson('/api/v1/modifier-groups', ['name' => 'Nope'])->assertStatus(403);
    $this->withHeaders($headers)->getJson('/api/v1/menu?outlet_id='.$ctx['outlet']['id'])->assertStatus(403);
    $this->withHeaders($headers)->getJson('/api/v1/dayparts')->assertStatus(403);
});

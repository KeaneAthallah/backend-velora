<?php

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;

function floorPlanSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Floor Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Latte',
        'sku' => 'LAT-1',
        'selling_price' => 20000,
        'cost_price' => 6000,
        'track_inventory' => true,
    ])->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 50,
        'reason' => 'init',
    ]);

    $area = $test->withHeaders($headers)->postJson('/api/v1/floor-areas', [
        'name' => 'Main hall',
        'sort_order' => 1,
    ])->json('data');

    // Upgrade subscription in DB to Pro so we can create additional outlets later.
    $biz = Business::where('name', 'Floor Biz')->firstOrFail();
    $sub = Subscription::where('business_id', $biz->id)->firstOrFail();
    $proPlan = Plan::where('slug', 'pro')->firstOrFail();
    $sub->update(['plan_id' => $proPlan->id]);

    $table = $test->withHeaders($headers)->postJson('/api/v1/tables', [
        'name' => 'T01',
        'seats' => 4,
        'floor_area_id' => $area['id'],
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'area', 'table');
}

function floorPlanOrder($test, array $ctx, array $overrides = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/orders', array_merge([
        'order_type' => 'dine_in',
        'table_id' => $ctx['table']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ], $overrides));
}

function floorPlanTables($test, array $ctx): array
{
    return $test->withHeaders($ctx['headers'])->getJson('/api/v1/tables')->json('data');
}

test('floor plan lists areas and tables with live occupancy', function () {
    $ctx = floorPlanSetup($this);

    $plan = floorPlanTables($this, $ctx);
    expect($plan['areas'])->toHaveCount(1);
    expect($plan['areas'][0]['name'])->toBe('Main hall');
    expect($plan['tables'])->toHaveCount(1);
    expect($plan['tables'][0]['name'])->toBe('T01');
    expect($plan['tables'][0]['seats'])->toBe(4);
    expect($plan['tables'][0]['status'])->toBe('available');
    expect($plan['tables'][0]['occupied'])->toBeFalse();
    expect($plan['tables'][0]['area']['name'])->toBe('Main hall');

    $order = floorPlanOrder($this, $ctx)->assertStatus(201)->json('data');
    expect($order['table_id'])->toBe($ctx['table']['id']);
    expect($order['table']['name'])->toBe('T01');

    $plan = floorPlanTables($this, $ctx);
    expect($plan['tables'][0]['status'])->toBe('occupied');
    expect($plan['tables'][0]['occupied'])->toBeTrue();
    expect($plan['tables'][0]['active_order']['id'])->toBe($order['id']);
    expect($plan['tables'][0]['active_order']['order_number'])->toBe($order['order_number']);
    expect($plan['tables'][0]['active_order']['items_count'])->toBe(1);

    // Orders expose the table they belong to.
    $this->withHeaders($ctx['headers'])->getJson("/api/v1/orders/{$order['id']}")
        ->assertStatus(200)
        ->assertJsonPath('data.table.name', 'T01');

    // The order board list carries the table too.
    $listed = $this->withHeaders($ctx['headers'])->getJson('/api/v1/orders?status=active')->json('data.data');
    expect($listed)->toHaveCount(1);
    expect($listed[0]['table']['name'])->toBe('T01');
});

test('tables and areas are validated per outlet', function () {
    $ctx = floorPlanSetup($this);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/tables', ['name' => 'T01'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/tables', ['name' => 'T02', 'seats' => 0])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/tables', ['name' => 'T03', 'shape' => 'triangle'])
        ->assertStatus(422);

    // Areas from another outlet cannot be referenced.
    $otherOutlet = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/outlets', ['name' => 'Terrace', 'code' => 'TER'])->json('data');
    $this->withHeaders($ctx['bizHeaders'] + ['X-Outlet-Id' => $otherOutlet['id']])
        ->postJson('/api/v1/tables', ['name' => 'T09', 'floor_area_id' => $ctx['area']['id']])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/floor-areas', ['name' => 'Main hall'])
        ->assertStatus(422);
});

test('a table can only hold one open order and only for dine in', function () {
    $ctx = floorPlanSetup($this);

    floorPlanOrder($this, $ctx)->assertStatus(201);

    floorPlanOrder($this, $ctx)
        ->assertStatus(422)
        ->assertJsonPath('code', 'TABLE_ERROR');

    floorPlanOrder($this, $ctx, ['order_type' => 'takeaway'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TABLE_ERROR');

    // An unknown table in this outlet is a 404, not a validation leak.
    floorPlanOrder($this, $ctx, ['table_id' => 999999])->assertStatus(404);

    // Another table is free for a second order.
    $second = $this->withHeaders($ctx['headers'])->postJson('/api/v1/tables', ['name' => 'T02'])->json('data');
    floorPlanOrder($this, $ctx, ['table_id' => $second['id']])->assertStatus(201);
});

test('checkout and cancel release the table', function () {
    $ctx = floorPlanSetup($this);

    $order = floorPlanOrder($this, $ctx)->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/checkout")->assertStatus(200);

    $plan = floorPlanTables($this, $ctx);
    expect($plan['tables'][0]['occupied'])->toBeFalse();
    expect($plan['tables'][0]['status'])->toBe('available');

    $second = floorPlanOrder($this, $ctx)->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$second['id']}/cancel", ['reason' => 'Guest left'])
        ->assertStatus(200);

    expect(floorPlanTables($this, $ctx)['tables'][0]['occupied'])->toBeFalse();
});

test('switching an order away from dine in frees the table', function () {
    $ctx = floorPlanSetup($this);

    $order = floorPlanOrder($this, $ctx)->json('data');

    $updated = $this->withHeaders($ctx['headers'])->putJson("/api/v1/orders/{$order['id']}", [
        'order_type' => 'takeaway',
    ]);

    $updated->assertStatus(200);
    expect($updated->json('data.order_type'))->toBe('takeaway');
    expect($updated->json('data.table_id'))->toBeNull();
    expect(floorPlanTables($this, $ctx)['tables'][0]['occupied'])->toBeFalse();

    // And it can be claimed again by a new dine-in order.
    floorPlanOrder($this, $ctx)->assertStatus(201);
});

test('manual table status is blocked while the table has an open order', function () {
    $ctx = floorPlanSetup($this);

    $order = floorPlanOrder($this, $ctx)->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/tables/{$ctx['table']['id']}/status", ['status' => 'cleaning'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TABLE_ERROR');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Done'])->assertStatus(200);

    $reserved = $this->withHeaders($ctx['headers'])->postJson("/api/v1/tables/{$ctx['table']['id']}/status", ['status' => 'reserved']);
    $reserved->assertStatus(200);
    expect($reserved->json('data.status'))->toBe('reserved');
    expect($reserved->json('data.occupied'))->toBeFalse();

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/tables/{$ctx['table']['id']}/status", ['status' => 'napping'])
        ->assertStatus(422);
});

test('tables with sales history cannot be deleted and occupied areas stay', function () {
    $ctx = floorPlanSetup($this);

    $order = floorPlanOrder($this, $ctx)->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Done'])->assertStatus(200);

    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/tables/{$ctx['table']['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'TABLE_ERROR');

    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/floor-areas/{$ctx['area']['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'TABLE_ERROR');

    // A table without history can be removed, then the area is free.
    $spare = $this->withHeaders($ctx['headers'])->postJson('/api/v1/tables', ['name' => 'T99'])->json('data');
    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/tables/{$spare['id']}")->assertStatus(200);

    $this->withHeaders($ctx['headers'])->putJson("/api/v1/tables/{$ctx['table']['id']}", ['floor_area_id' => null])->assertStatus(200);
    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/floor-areas/{$ctx['area']['id']}")->assertStatus(200);
});

test('inactive tables cannot take new orders', function () {
    $ctx = floorPlanSetup($this);

    $this->withHeaders($ctx['headers'])->putJson("/api/v1/tables/{$ctx['table']['id']}", ['is_active' => false])
        ->assertStatus(200);

    floorPlanOrder($this, $ctx)->assertStatus(422)->assertJsonPath('code', 'TABLE_ERROR');

    // The floor plan hides inactive tables unless asked.
    expect(floorPlanTables($this, $ctx)['tables'])->toHaveCount(0);
    $all = $this->withHeaders($ctx['headers'])->getJson('/api/v1/tables?include_inactive=1')->json('data');
    expect($all['tables'])->toHaveCount(1);
});

test('immediate dine-in sales can take a table', function () {
    $ctx = floorPlanSetup($this);

    $sale = $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'order_type' => 'dine_in',
        'table_id' => $ctx['table']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);
    $sale->assertStatus(201);
    expect($sale->json('data.table_id'))->toBe($ctx['table']['id']);
    expect($sale->json('data.order_status'))->toBe('completed');

    // The sale is finished, so the table is free again.
    expect(floorPlanTables($this, $ctx)['tables'][0]['occupied'])->toBeFalse();

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/sales', [
        'order_type' => 'takeaway',
        'table_id' => $ctx['table']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('code', 'TABLE_ERROR');
});

test('floor plan is tenant scoped', function () {
    $ctx = floorPlanSetup($this);

    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)->postJson('/api/v1/businesses', ['name' => 'Other Biz'])->json('data');
    $otherHeaders = $otherAuth + ['X-Business-Id' => $otherBiz['id']];
    $this->flushHeaders();

    $this->withHeaders($otherHeaders)->getJson('/api/v1/tables?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/tables/{$ctx['table']['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->deleteJson("/api/v1/tables/{$ctx['table']['id']}")->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/tables/{$ctx['table']['id']}/status", ['status' => 'cleaning'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/floor-areas/{$ctx['area']['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->deleteJson("/api/v1/floor-areas/{$ctx['area']['id']}")->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson('/api/v1/floor-areas?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);

    $this->withHeaders($otherHeaders)->postJson('/api/v1/tables', [
        'name' => 'X1',
        'floor_area_id' => $ctx['area']['id'],
    ])->assertStatus(422);

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders($ctx['headers'])->getJson('/api/v1/tables')->assertStatus(200);
});

test('floor plan management requires permissions', function () {
    $ctx = floorPlanSetup($this);

    $cashier = User::factory()->create();
    $cashierToken = $cashier->createToken('t')->plainTextToken;
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'name' => 'Cashier', 'slug' => 'cashier']);
    BusinessMembership::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $cashier->id,
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $headers = [
        'Authorization' => "Bearer $cashierToken",
        'X-Business-Id' => $ctx['biz']['id'],
        'X-Outlet-Id' => $ctx['outlet']['id'],
    ];
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($headers)->getJson('/api/v1/tables')->assertStatus(403);
    $this->withHeaders($headers)->getJson('/api/v1/floor-areas')->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/tables', ['name' => 'T77'])->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/floor-areas', ['name' => 'Patio'])->assertStatus(403);
    $this->withHeaders($headers)->postJson("/api/v1/tables/{$ctx['table']['id']}/status", ['status' => 'cleaning'])->assertStatus(403);
});

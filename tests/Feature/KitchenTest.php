<?php

use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;

function kitchenSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Kitchen Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $drinks = $test->withHeaders($bizHeaders)->postJson('/api/v1/categories', ['name' => 'Drinks'])->json('data');
    $food = $test->withHeaders($bizHeaders)->postJson('/api/v1/categories', ['name' => 'Food'])->json('data');

    $latte = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Latte',
        'sku' => 'KIT-LATTE',
        'category_id' => $drinks['id'],
        'selling_price' => 25000,
        'cost_price' => 8000,
        'track_inventory' => false,
    ])->json('data');

    $rice = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Nasi Goreng',
        'sku' => 'KIT-RICE',
        'category_id' => $food['id'],
        'selling_price' => 45000,
        'cost_price' => 15000,
        'track_inventory' => false,
    ])->json('data');

    $bar = $test->withHeaders($headers)->postJson('/api/v1/kitchen/stations', [
        'name' => 'Bar',
        'kind' => 'bar',
        'category_ids' => [$drinks['id']],
        'printer_name' => 'Bar printer',
        'printer_target' => 'tcp://192.168.1.51:9100',
    ])->json('data');

    $grill = $test->withHeaders($headers)->postJson('/api/v1/kitchen/stations', [
        'name' => 'Grill',
        'kind' => 'kitchen',
        'category_ids' => [$food['id']],
        'is_default' => true,
    ])->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'drinks', 'food', 'latte', 'rice', 'bar', 'grill');
}

function kitchenOrder($test, array $ctx, array $items, array $overrides = [])
{
    return $test->withHeaders($ctx['headers'])->postJson('/api/v1/orders', array_merge([
        'order_type' => 'takeaway',
        'items' => $items,
    ], $overrides));
}

function kitchenTickets($test, array $ctx, string $query = '')
{
    return $test->withHeaders($ctx['headers'])->getJson('/api/v1/kitchen/tickets'.$query);
}

test('firing an order splits tickets across stations', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [
        ['product_id' => $ctx['latte']['id'], 'quantity' => 2],
        ['product_id' => $ctx['rice']['id'], 'quantity' => 1, 'notes' => 'Extra spicy'],
    ])->assertStatus(201)->json('data');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen'])
        ->assertStatus(200)
        ->assertJsonPath('data.order_status', 'in_kitchen');

    $board = kitchenTickets($this, $ctx)->assertStatus(200)->json('data');
    expect($board['tickets'])->toHaveCount(2);
    expect($board['counts']['queued'])->toBe(2);

    $byStation = collect($board['tickets'])->keyBy('station.name');
    expect($byStation->keys()->sort()->values()->all())->toBe(['Bar', 'Grill']);

    $bar = $byStation['Bar'];
    expect($bar['items'])->toHaveCount(1);
    expect($bar['items'][0]['name'])->toBe('Latte');
    expect($bar['items'][0]['quantity'])->toBe('2');
    expect($bar['sale']['order_number'])->toBe($order['order_number']);
    expect($bar['station']['printer_target'])->toBe('tcp://192.168.1.51:9100');
    expect($bar['ticket_number'])->toMatch('/^MAIN-K-\d{8}-\d{3}$/');

    $grill = $byStation['Grill'];
    expect($grill['items'][0]['name'])->toBe('Nasi Goreng');
    expect($grill['items'][0]['notes'])->toBe('Extra spicy');

    // Firing again never duplicates the kitchen's work.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen'])
        ->assertStatus(200);
    expect(kitchenTickets($this, $ctx)->json('data.tickets'))->toHaveCount(2);
});

test('items without a routed category go to the default station', function () {
    $ctx = kitchenSetup($this);

    $snack = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/products', [
        'name' => 'Keripik',
        'sku' => 'KIT-SNACK',
        'selling_price' => 15000,
    ])->json('data');

    $order = kitchenOrder($this, $ctx, [['product_id' => $snack['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);

    $tickets = kitchenTickets($this, $ctx)->json('data.tickets');
    expect($tickets)->toHaveCount(1);
    expect($tickets[0]['station']['name'])->toBe('Grill');
});

test('stations are validated per outlet and keep a single default', function () {
    $ctx = kitchenSetup($this);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', ['name' => 'Bar'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', ['name' => 'Cold', 'kind' => 'oven'])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', [
        'name' => 'Cold',
        'category_ids' => [$ctx['food']['id'] + 999],
    ])->assertStatus(422);

    // Codes must be unique inside the outlet.
    $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', ['name' => 'Cold', 'code' => 'BAR'])
        ->assertStatus(422);

    // A new default releases the previous one.
    $cold = $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', [
        'name' => 'Cold',
        'kind' => 'cold',
        'is_default' => true,
    ])->assertStatus(201)->json('data');
    expect($cold['code'])->toBe('COLD');

    $stations = $this->withHeaders($ctx['headers'])->getJson('/api/v1/kitchen/stations')->json('data');
    $defaults = collect($stations)->where('is_default', true);
    expect($defaults)->toHaveCount(1);
    expect($defaults->first()['name'])->toBe('Cold');

    // Codes are generated when they are not supplied.
    $pastry = $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', ['name' => 'Pastry Station'])
        ->assertStatus(201)->json('data');
    expect($pastry['code'])->toBe('PASTRY');
});

test('ticket progress is forward only and rolls the order forward', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [
        ['product_id' => $ctx['latte']['id'], 'quantity' => 1],
        ['product_id' => $ctx['rice']['id'], 'quantity' => 1],
    ])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);

    $tickets = kitchenTickets($this, $ctx)->json('data.tickets');
    [$bar, $grill] = [$tickets[0], $tickets[1]];

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'preparing'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'preparing');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'queued'])
        ->assertStatus(422);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'ready'])
        ->assertStatus(200);

    // One station ready is not the whole order.
    $this->withHeaders($ctx['headers'])->getJson("/api/v1/orders/{$order['id']}")
        ->assertJsonPath('data.order_status', 'in_kitchen');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$grill['id']}/status", ['status' => 'ready'])
        ->assertStatus(200);

    $this->withHeaders($ctx['headers'])->getJson("/api/v1/orders/{$order['id']}")
        ->assertJsonPath('data.order_status', 'ready');

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'served'])->assertStatus(200);
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$grill['id']}/status", ['status' => 'served'])->assertStatus(200);

    $served = $this->withHeaders($ctx['headers'])->getJson("/api/v1/orders/{$order['id']}")->json('data');
    expect($served['order_status'])->toBe('served');
    expect($served['served_at'])->not->toBeNull();

    // Same status is idempotent, backwards is not.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'served'])
        ->assertStatus(200);
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$bar['id']}/status", ['status' => 'preparing'])
        ->assertStatus(422);
});

test('order board status changes cascade to tickets', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [['product_id' => $ctx['latte']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'ready'])
        ->assertStatus(200);

    $tickets = kitchenTickets($this, $ctx, '?status=ready')->json('data.tickets');
    expect($tickets)->toHaveCount(1);
    expect($tickets[0]['status'])->toBe('ready');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'served']);

    $tickets = kitchenTickets($this, $ctx, '?status=served')->json('data.tickets');
    expect($tickets)->toHaveCount(1);
    expect(kitchenTickets($this, $ctx)->json('data.tickets'))->toHaveCount(0);
});

test('cancelling or checking out an order clears its tickets', function () {
    $ctx = kitchenSetup($this);

    $cancelled = kitchenOrder($this, $ctx, [['product_id' => $ctx['latte']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$cancelled['id']}/status", ['status' => 'in_kitchen']);
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/orders/{$cancelled['id']}/cancel", ['reason' => 'Guest left'])
        ->assertStatus(200);

    $voided = kitchenTickets($this, $ctx, '?status=voided')->json('data.tickets');
    expect($voided)->toHaveCount(1);
    expect(kitchenTickets($this, $ctx)->json('data.tickets'))->toHaveCount(0);

    // A voided ticket cannot be bumped back to life.
    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$voided[0]['id']}/status", ['status' => 'ready'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'KITCHEN_ERROR');

    $checkedOut = kitchenOrder($this, $ctx, [['product_id' => $ctx['rice']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$checkedOut['id']}/status", ['status' => 'in_kitchen']);
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$checkedOut['id']}/checkout")->assertStatus(200);

    expect(kitchenTickets($this, $ctx)->json('data.tickets'))->toHaveCount(0);
    expect(kitchenTickets($this, $ctx, '?status=served')->json('data.tickets'))->toHaveCount(1);
});

test('printing returns a plain text ticket and records the print', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [
        ['product_id' => $ctx['latte']['id'], 'quantity' => 2],
    ], ['order_type' => 'dine_in'])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);

    $ticket = kitchenTickets($this, $ctx)->json('data.tickets.0');

    $print = $this->withHeaders($ctx['headers'])
        ->getJson("/api/v1/kitchen/tickets/{$ticket['id']}/print")
        ->assertStatus(200)
        ->json('data');

    expect($print['text'])->toContain($order['order_number']);
    expect($print['text'])->toContain('Latte');
    expect($print['text'])->toContain('Station BAR');
    expect($print['print_count'])->toBe(0);

    $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/printed")
        ->assertStatus(200)
        ->assertJsonPath('data.print_count', 1);

    $second = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/printed")
        ->assertStatus(200)
        ->json('data');
    expect($second['print_count'])->toBe(2);
    expect($second['printed_at'])->not->toBeNull();
});

test('firing works without stations and creates no tickets', function () {
    $ctx = kitchenSetup($this);

    $otherOutlet = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/outlets', ['name' => 'Terrace', 'code' => 'TER'])->json('data');
    $headers = $ctx['bizHeaders'] + ['X-Outlet-Id' => $otherOutlet['id']];

    $order = $this->withHeaders($headers)->postJson('/api/v1/orders', [
        'order_type' => 'takeaway',
        'items' => [['product_id' => $ctx['latte']['id'], 'quantity' => 1]],
    ])->json('data');

    $this->withHeaders($headers)
        ->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen'])
        ->assertStatus(200)
        ->assertJsonPath('data.order_status', 'in_kitchen');

    expect($this->withHeaders($headers)->getJson('/api/v1/kitchen/tickets')->json('data.tickets'))->toHaveCount(0);
});

test('stations with tickets cannot be deleted but can be deactivated', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [['product_id' => $ctx['latte']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);

    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/kitchen/stations/{$ctx['bar']['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'KITCHEN_ERROR');

    $this->withHeaders($ctx['headers'])
        ->putJson("/api/v1/kitchen/stations/{$ctx['bar']['id']}", ['is_active' => false])
        ->assertStatus(200)
        ->assertJsonPath('data.is_active', false);

    $active = $this->withHeaders($ctx['headers'])->getJson('/api/v1/kitchen/stations')->json('data');
    expect(collect($active)->pluck('name')->all())->toBe(['Grill']);

    // A station without history can be removed.
    $spare = $this->withHeaders($ctx['headers'])->postJson('/api/v1/kitchen/stations', ['name' => 'Spare'])->json('data');
    $this->withHeaders($ctx['headers'])->deleteJson("/api/v1/kitchen/stations/{$spare['id']}")->assertStatus(200);
});

test('kitchen data is tenant scoped', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [['product_id' => $ctx['latte']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);
    $ticket = kitchenTickets($this, $ctx)->json('data.tickets.0');

    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)->postJson('/api/v1/businesses', ['name' => 'Other Biz'])->json('data');
    $otherHeaders = $otherAuth + ['X-Business-Id' => $otherBiz['id']];
    $this->flushHeaders();

    $this->withHeaders($otherHeaders)->getJson('/api/v1/kitchen/stations?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson('/api/v1/kitchen/tickets?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/kitchen/stations/{$ctx['bar']['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->deleteJson("/api/v1/kitchen/stations/{$ctx['bar']['id']}")->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/status", ['status' => 'ready'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson("/api/v1/kitchen/tickets/{$ticket['id']}/print")->assertStatus(404);
    $this->withHeaders($otherHeaders)->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/printed")->assertStatus(404);

    // A station from another business cannot be used as a filter either.
    $this->withHeaders($otherHeaders)->getJson('/api/v1/kitchen/tickets?outlet_id='.$ctx['outlet']['id'].'&station_id='.$ctx['bar']['id'])
        ->assertStatus(404);

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders($ctx['headers'])->getJson('/api/v1/kitchen/tickets')->assertStatus(200);
});

test('kitchen permissions are enforced', function () {
    $ctx = kitchenSetup($this);

    $order = kitchenOrder($this, $ctx, [['product_id' => $ctx['latte']['id'], 'quantity' => 1]])->json('data');
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen']);
    $ticket = kitchenTickets($this, $ctx)->json('data.tickets.0');

    $chef = User::factory()->create();
    $chefToken = $chef->createToken('t')->plainTextToken;
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'name' => 'Chef', 'slug' => 'chef']);
    BusinessMembership::create([
        'business_id' => $ctx['biz']['id'],
        'user_id' => $chef->id,
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $headers = [
        'Authorization' => "Bearer $chefToken",
        'X-Business-Id' => $ctx['biz']['id'],
        'X-Outlet-Id' => $ctx['outlet']['id'],
    ];
    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($headers)->getJson('/api/v1/kitchen/stations')->assertStatus(403);
    $this->withHeaders($headers)->getJson('/api/v1/kitchen/tickets')->assertStatus(403);
    $this->withHeaders($headers)->getJson("/api/v1/kitchen/tickets/{$ticket['id']}/print")->assertStatus(403);
    $this->withHeaders($headers)->postJson("/api/v1/kitchen/tickets/{$ticket['id']}/status", ['status' => 'ready'])->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/kitchen/stations', ['name' => 'Pastry'])->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/kitchen/tickets/'.$ticket['id'].'/printed')->assertStatus(403);
});

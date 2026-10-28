<?php

use App\Models\BusinessMembership;
use App\Models\ChannelEvent;
use App\Models\ChannelMenuMapping;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Models\User;

function channelSetup($test): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Channel Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];
    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');
    $headers = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Kopi Susu',
        'sku' => 'CH-KOPI',
        'selling_price' => 20000,
        'track_inventory' => false,
    ])->json('data');

    $channel = $test->withHeaders($bizHeaders)->postJson('/api/v1/channels', [
        'name' => 'GoFood',
        'kind' => 'marketplace',
        'commission_percent' => 20,
        'payment_method_code' => 'qris',
        'outlet_id' => $outlet['id'],
    ])->assertStatus(201)->json('data');

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'channel');
}

function channelMap($test, array $ctx, ?array $rows = null)
{
    $rows ??= [[
        'external_item_id' => 'GF-KOPI',
        'product_id' => $ctx['product']['id'],
        'option_map' => [],
    ]];

    return $test->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/channels/{$ctx['channel']['id']}/mappings", ['mappings' => $rows]);
}

function channelOrderPayload(array $overrides = []): array
{
    return array_merge([
        'external_order_id' => 'GF-1001',
        'order_type' => 'pickup',
        'customer' => ['name' => 'Rina', 'phone' => '0812000111'],
        'items' => [['external_item_id' => 'GF-KOPI', 'quantity' => 2]],
    ], $overrides);
}

function channelPush($test, array $ctx, array $payload, ?string $secret = null, ?string $token = null)
{
    $json = json_encode($payload);

    return $test->call(
        'POST',
        '/api/v1/channel-webhooks/'.($token ?? $ctx['channel']['webhook_token']),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHANNEL_SIGNATURE' => hash_hmac('sha256', $json, $secret ?? $ctx['channel']['secret']),
        ],
        $json
    );
}

test('channels issue connector credentials and can be edited', function () {
    $ctx = channelSetup($this);

    expect($ctx['channel']['code'])->toBe('gofood');
    expect($ctx['channel']['webhook_url'])->toContain('/api/v1/channel-webhooks/');
    expect(strlen($ctx['channel']['secret']))->toBe(48);
    expect($ctx['channel']['has_credentials'])->toBeTrue();

    $list = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/channels')->assertStatus(200)->json('data');
    expect($list)->toHaveCount(1);
    expect($list[0])->not->toHaveKey('secret');

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/channels/{$ctx['channel']['id']}", [
        'name' => 'GoFood Delivery',
        'commission_percent' => 22.5,
        'auto_accept' => true,
    ])->assertStatus(200)->assertJsonPath('data.name', 'GoFood Delivery')->assertJsonPath('data.auto_accept', true);

    expect((string) SalesChannel::find($ctx['channel']['id'])->commission_percent)->toBe('22.5000');

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/channels', ['name' => 'GoFood', 'code' => 'gofood'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'CHANNEL_ERROR');

    $rotated = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/rotate-secret")
        ->assertStatus(200)
        ->json('data');

    expect($rotated['secret'])->not->toBe($ctx['channel']['secret']);

    channelMap($this, $ctx);
    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/channels/{$ctx['channel']['id']}")->assertStatus(200);
    expect(SalesChannel::count())->toBe(0);
});

test('a signed channel push creates an order priced by the server', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $res = channelPush($this, $ctx, channelOrderPayload())->assertStatus(201);

    expect($res->json('data.order_status'))->toBe('open');
    expect($res->json('data.channel_status'))->toBe('received');
    expect($res->json('data.total'))->toBe('40000.0000');
    expect($res->json('data.commission'))->toBe('8000.0000');
    expect($res->json('data.items.0.name'))->toBe('Kopi Susu');
    expect($res->json('data.items.0.quantity'))->toBe('2.0000');

    // The payload never carries a price: two units of a 20.000 item is 40.000.
    $sale = Sale::find($res->json('data.id'));
    expect((string) $sale->total)->toBe('40000.0000');
    expect((string) $sale->channel_commission_percent)->toBe('20.0000');

    expect(Customer::where('business_id', $ctx['biz']['id'])->first()->phone)->toBe('0812000111');

    $again = channelPush($this, $ctx, channelOrderPayload())->assertStatus(200);
    expect($again->json('data.id'))->toBe($res->json('data.id'));
    expect(Sale::where('sales_channel_id', $ctx['channel']['id'])->count())->toBe(1);
});

test('unsigned, unknown or inactive channel pushes are rejected', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    channelPush($this, $ctx, channelOrderPayload(), 'wrong-secret')
        ->assertStatus(401)
        ->assertJsonPath('code', 'CHANNEL_AUTH');

    channelPush($this, $ctx, channelOrderPayload(), null, 'not-a-token')
        ->assertStatus(404);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/channels/{$ctx['channel']['id']}", [
        'name' => 'GoFood',
        'is_active' => false,
    ])->assertStatus(200);

    channelPush($this, $ctx, channelOrderPayload())->assertStatus(404);

    expect(Sale::count())->toBe(0);
});

test('unmapped items and incomplete delivery details are refused', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    channelPush($this, $ctx, channelOrderPayload([
        'items' => [['external_item_id' => 'GF-UNKNOWN', 'quantity' => 1]],
    ]))->assertStatus(422)->assertJsonPath('code', 'CHANNEL_ERROR');

    channelPush($this, $ctx, channelOrderPayload([
        'external_order_id' => 'GF-2002',
        'order_type' => 'delivery',
        'customer' => ['name' => 'Rina', 'phone' => '0812000111'],
    ]))->assertStatus(422)->assertJsonPath('code', 'CHANNEL_ERROR');

    $delivered = channelPush($this, $ctx, channelOrderPayload([
        'external_order_id' => 'GF-2003',
        'order_type' => 'delivery',
        'fulfilment' => ['address' => 'Jl. Braga 12, Bandung'],
    ]))->assertStatus(201)->json('data');

    expect($delivered['fulfilment']['address'])->toBe('Jl. Braga 12, Bandung');
    expect($delivered['fulfilment']['contact_phone'])->toBe('0812000111');
    expect($delivered['order_type'])->toBe('delivery');
});

test('auto accepting a channel fires the order straight to the kitchen', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/channels/{$ctx['channel']['id']}", [
        'name' => 'GoFood',
        'auto_accept' => true,
        'prep_buffer_minutes' => 12,
    ])->assertStatus(200);

    $order = channelPush($this, $ctx, channelOrderPayload())->assertStatus(201)->json('data');

    expect($order['channel_status'])->toBe('accepted');
    expect($order['order_status'])->toBe('in_kitchen');

    $event = ChannelEvent::where('sale_id', $order['id'])->where('type', 'order.accepted')->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe('pending');
    expect($event->payload['prep_buffer_minutes'])->toBe(12);
    expect($event->payload['auto_accepted'])->toBeTrue();
});

test('channel orders can be accepted and rejected with outbox events', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $first = channelPush($this, $ctx, channelOrderPayload())->json('data');
    $accepted = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$first['id']}/accept")
        ->assertStatus(200)
        ->json('data');

    expect($accepted['channel_status'])->toBe('accepted');
    expect($accepted['order_status'])->toBe('in_kitchen');
    expect(ChannelEvent::where('sale_id', $first['id'])->where('type', 'order.accepted')->exists())->toBeTrue();

    $second = channelPush($this, $ctx, channelOrderPayload(['external_order_id' => 'GF-3003']))->json('data');
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$second['id']}/reject", [
            'reason' => 'Out of stock',
        ])->assertStatus(200)->assertJsonPath('data.channel_status', 'rejected');

    $sale = Sale::find($second['id']);
    expect($sale->status)->toBe('voided');
    expect($sale->order_status)->toBe('cancelled');
    expect(ChannelEvent::where('sale_id', $second['id'])->where('type', 'order.rejected')->exists())->toBeTrue();

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$second['id']}/accept")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CHANNEL_ERROR');
});

test('checkout settles a channel order with the channel payment rail', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $order = channelPush($this, $ctx, channelOrderPayload())->json('data');
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$order['id']}/accept")
        ->assertStatus(200);

    $checked = $this->withHeaders($ctx['headers'])
        ->postJson("/api/v1/orders/{$order['id']}/checkout")
        ->assertStatus(200)
        ->json('data');

    expect($checked['channel_status'])->toBe('completed');
    expect($checked['paid_amount'])->toBe('40000.0000');
    expect($checked['payments'])->toHaveCount(1);
    expect($checked['payments'][0]['method'])->toBe('qris');
    expect($checked['payments'][0]['status'])->toBe('completed');
    expect($checked['payments'][0]['reference'])->toBe('GF-1001');

    expect(Payment::where('sale_id', $order['id'])->count())->toBe(1);
    expect(ChannelEvent::where('sale_id', $order['id'])->where('type', 'order.completed')->exists())->toBeTrue();
});

test('order status changes are pushed to the channel once', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $order = channelPush($this, $ctx, channelOrderPayload())->json('data');

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'in_kitchen'])
        ->assertStatus(200);
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'ready'])
        ->assertStatus(200);

    expect(Sale::find($order['id'])->channel_status)->toBe('ready');
    expect(ChannelEvent::where('sale_id', $order['id'])->where('type', 'order.ready')->count())->toBe(1);

    // Repeating the same status must not enqueue a second event.
    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/status", ['status' => 'ready'])
        ->assertStatus(200);
    expect(ChannelEvent::where('sale_id', $order['id'])->where('type', 'order.ready')->count())->toBe(1);

    $this->withHeaders($ctx['headers'])->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Guest left'])
        ->assertStatus(200);

    expect(Sale::find($order['id'])->channel_status)->toBe('cancelled');
    expect(ChannelEvent::where('sale_id', $order['id'])->where('type', 'order.cancelled')->count())->toBe(1);
});

test('the menu snapshot uses the channel external ids and queues a push', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);

    $res = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/menu/push")
        ->assertStatus(200)
        ->json('data');

    expect($res['snapshot']['items'])->toHaveCount(1);
    expect($res['snapshot']['items'][0]['external_item_id'])->toBe('GF-KOPI');
    expect($res['snapshot']['items'][0]['price'])->toBe('20000.0000');
    expect($res['snapshot']['items'][0]['available'])->toBeTrue();
    expect($res['event']['type'])->toBe('menu.pushed');
    expect($res['event']['status'])->toBe('pending');
});

test('menu mappings are replaced as a set', function () {
    $ctx = channelSetup($this);

    channelMap($this, $ctx)->assertStatus(200);
    expect(ChannelMenuMapping::count())->toBe(1);

    channelMap($this, $ctx, [[
        'external_item_id' => 'GF-NEW',
        'product_id' => $ctx['product']['id'],
        'option_map' => [],
    ]])->assertStatus(200);

    expect(ChannelMenuMapping::pluck('external_item_id')->all())->toBe(['GF-NEW']);

    channelMap($this, $ctx, [[
        'external_item_id' => 'GF-BAD',
        'product_id' => 999999,
        'option_map' => [],
    ]])->assertStatus(422)->assertJsonPath('code', 'CHANNEL_ERROR');
});

test('channels are tenant scoped and need the channels permission', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);
    $order = channelPush($this, $ctx, channelOrderPayload())->json('data');

    $other = channelSetup($this);

    $this->withHeaders($other['bizHeaders'])->getJson('/api/v1/channels')->assertStatus(200)->assertJsonCount(1, 'data');
    $this->withHeaders($other['bizHeaders'])->putJson("/api/v1/channels/{$ctx['channel']['id']}", ['name' => 'Hijack'])
        ->assertStatus(404);
    $this->withHeaders($other['bizHeaders'])->deleteJson("/api/v1/channels/{$ctx['channel']['id']}")
        ->assertStatus(404);
    $this->withHeaders($other['bizHeaders'])->getJson("/api/v1/channels/{$ctx['channel']['id']}/mappings")
        ->assertStatus(404);
    $this->withHeaders($other['bizHeaders'])->getJson("/api/v1/channels/{$ctx['channel']['id']}/orders")
        ->assertStatus(404);
    $this->withHeaders($other['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$order['id']}/accept")
        ->assertStatus(404);

    $staff = User::factory()->create();
    $role = Role::create(['business_id' => $ctx['biz']['id'], 'slug' => 'barista', 'name' => 'Barista']);
    $role->permissions()->sync(Permission::whereIn('name', ['orders.view', 'sales.view'])->pluck('id'));
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

    $this->withHeaders($staffHeaders)->getJson('/api/v1/channels')->assertStatus(403);
    $this->withHeaders($staffHeaders)->postJson('/api/v1/channels', ['name' => 'Nope'])->assertStatus(403);
    $this->withHeaders($staffHeaders)->getJson("/api/v1/channels/{$ctx['channel']['id']}/events")->assertStatus(403);
});

test('a channel with orders cannot be deleted', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);
    channelPush($this, $ctx, channelOrderPayload())->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/channels/{$ctx['channel']['id']}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'CHANNEL_IN_USE');
});

test('staff can tag an order taken at the till to a channel', function () {
    $ctx = channelSetup($this);

    $order = $this->withHeaders($ctx['headers'])->postJson('/api/v1/orders', [
        'order_type' => 'pickup',
        'sales_channel_id' => $ctx['channel']['id'],
        'channel_order_ref' => 'WA-778',
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ])->assertStatus(201)->json('data');

    expect($order['channel_order_ref'])->toBe('WA-778');
    expect($order['channel_status'])->toBe('accepted');
    expect($order['channel_commission'])->toBe('4000.0000');
    expect($order['sales_channel']['name'])->toBe('GoFood');
});

test('channel events are acknowledged by the connector', function () {
    $ctx = channelSetup($this);
    channelMap($this, $ctx);
    $order = channelPush($this, $ctx, channelOrderPayload())->json('data');
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/orders/{$order['id']}/accept")
        ->assertStatus(200);

    $event = ChannelEvent::where('sale_id', $order['id'])->first();
    expect($event)->not->toBeNull();
    expect($event->type)->toBe('order.accepted');

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/channels/{$ctx['channel']['id']}/events/{$event->id}/ack", ['status' => 'sent'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'sent');

    expect($event->fresh()->sent_at)->not->toBeNull();
    expect($event->fresh()->attempts)->toBe(1);

    $pending = $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/channels/{$ctx['channel']['id']}/events?status=pending")
        ->json('data');
    expect($pending['total'])->toBe(0);
});

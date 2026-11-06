<?php

use App\Models\Delivery;
use App\Models\User;

function deliverySetup($test, string $suffix = 'DL'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Delivery Biz $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Delivery Outlet $suffix", 'code' => "DL-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    $product = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/products', [
            'name' => "Delivery Item $suffix",
            'sku' => "DL-$suffix",
            'selling_price' => 10000,
            'cost_price' => 4000,
            'track_inventory' => true,
        ])
        ->json('data');

    $test->withHeaders($headers)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 20,
        'reason' => 'seed',
    ])->assertOk();

    $sale = $test->withHeaders($headers)->postJson('/api/v1/sales', [
        'outlet_id' => $outlet['id'],
        'order_type' => 'delivery',
        'fulfilment' => [
            'contact_name' => 'Rina',
            'contact_phone' => '0812000002',
            'address' => 'Jl. Asia Afrika 8',
        ],
        'items' => [['product_id' => $product['id'], 'quantity' => 3]],
    ])->assertStatus(201)->json('data');

    $zone = $test->withHeaders($bizHeaders)->postJson('/api/v1/delivery-zones', [
        'name' => 'Central',
        'code' => 'CTR',
        'fee' => 7000,
        'min_order' => 0,
    ])->assertStatus(201)->json('data');

    $driver = $test->withHeaders($bizHeaders)->postJson('/api/v1/drivers', [
        'name' => 'Budi',
        'phone' => '0812000001',
    ])->assertStatus(201)->json('data');

    $vehicle = $test->withHeaders($bizHeaders)->postJson('/api/v1/vehicles', [
        'plate_number' => 'B 1234 XYZ',
        'type' => 'motorcycle',
    ])->assertStatus(201)->json('data');

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers', 'product', 'sale', 'zone', 'driver', 'vehicle');
}

function deliveryJob($test, array $ctx): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/deliveries', [
        'outlet_id' => $ctx['outlet']['id'],
        'sale_id' => $ctx['sale']['id'],
        'delivery_zone_id' => $ctx['zone']['id'],
        'recipient_name' => 'Rina',
        'recipient_phone' => '0812000002',
        'address' => ['line1' => 'Jl. Asia Afrika 8'],
    ])->assertStatus(201)->json('data');
}

test('fleet records can be created and listed', function () {
    $ctx = deliverySetup($this, 'FLEET');

    $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/delivery-zones')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fee', '7000.0000');

    $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/drivers')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'available');

    $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/vehicles')
        ->assertOk()
        ->assertJsonPath('data.0.plate_number', 'B 1234 XYZ');
});

test('a delivery runs from creation to proof and reconciliation', function () {
    $ctx = deliverySetup($this, 'FLOW');
    $delivery = deliveryJob($this, $ctx);

    expect($delivery['status'])->toBe('pending');
    expect($delivery['fee'])->toBe('7000.0000');
    expect($delivery['lines'])->toHaveCount(1);

    $assigned = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/assign", [
            'driver_id' => $ctx['driver']['id'],
            'vehicle_id' => $ctx['vehicle']['id'],
        ])
        ->assertOk()
        ->json('data');

    expect($assigned['status'])->toBe('assigned');
    expect($assigned['driver_id'])->toBe($ctx['driver']['id']);

    foreach (['picked_up', 'in_transit'] as $status) {
        $this->withHeaders($ctx['bizHeaders'])
            ->postJson("/api/v1/deliveries/{$delivery['id']}/status", ['status' => $status])
            ->assertOk();
    }

    $delivered = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/proof", [
            'recipient_name' => 'Rina',
            'notes' => 'Left with the concierge',
            'collected_amount' => 37000,
        ])
        ->assertOk()
        ->json('data');

    expect($delivered['status'])->toBe('delivered');
    expect($delivered['proof']['notes'])->toBe('Left with the concierge');

    $reconciled = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/reconcile", [
            'collected_amount' => 37000,
            'notes' => 'Cash handed over',
        ])
        ->assertOk()
        ->json('data');

    expect($reconciled['collected_amount'])->toBe('37000.0000');
    expect($reconciled['reconciled_at'])->not->toBeNull();

    // The driver is released for the next job.
    $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/drivers')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'available');
});

test('partial deliveries spawn a follow-up job for the remainder', function () {
    $ctx = deliverySetup($this, 'PART');
    $delivery = deliveryJob($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/assign", ['driver_id' => $ctx['driver']['id']])
        ->assertOk();
    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/status", ['status' => 'picked_up'])
        ->assertOk();

    $result = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/partial", [
            'items' => [['sale_item_id' => $ctx['sale']['items'][0]['id'], 'quantity' => 1]],
        ])
        ->assertOk()
        ->json('data');

    expect($result['delivery']['lines'][0]['delivered_quantity'])->toBe('1.0000');
    expect($result['follow_up'])->not->toBeNull();
    expect($result['follow_up']['lines'][0]['quantity'])->toBe('2.0000');

    expect(Delivery::where('business_id', $ctx['biz']['id'])->count())->toBe(2);
});

test('delivery validation rejects impossible transitions', function () {
    $ctx = deliverySetup($this, 'VAL');
    $delivery = deliveryJob($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/status", ['status' => 'delivered'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_TRANSITION');

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/deliveries/{$delivery['id']}/reconcile", ['collected_amount' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_STATUS');
});

test('deliveries are tenant scoped', function () {
    $owner = deliverySetup($this, 'OWN');
    $delivery = deliveryJob($this, $owner);

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $other = User::factory()->create();
    $auth = ['Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken];
    $biz = $this->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Other Delivery Biz'])->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $this->withHeaders($headers)
        ->getJson("/api/v1/deliveries/{$delivery['id']}")
        ->assertStatus(404);

    $this->withHeaders($headers)
        ->postJson("/api/v1/deliveries/{$delivery['id']}/status", ['status' => 'cancelled'])
        ->assertStatus(404);
});

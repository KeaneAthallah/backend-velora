<?php

use App\Models\User;
use App\Models\WarehouseBin;

/**
 * Warehouse zones and bins: creation, the zone-bound bin route and the
 * per-business rules around both.
 */
function warehouseCtx($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Warehouse $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    return ['user' => $user, 'biz' => $biz, 'bizHeaders' => $bizHeaders];
}

function warehouseZone($test, array $ctx, string $suffix): array
{
    $warehouse = $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/warehouses', [
        'name' => "Main $suffix",
        'code' => "WH-$suffix",
    ])->assertStatus(201)->json('data');

    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/warehouse-zones', [
        'warehouse_id' => $warehouse['id'],
        'name' => "Zone $suffix",
        'code' => "Z-$suffix",
    ])->assertStatus(201)->json('data');
}

test('a warehouse zone accepts bins and lists them', function () {
    $ctx = warehouseCtx($this, 'WB');
    $zone = warehouseZone($this, $ctx, 'WB');

    // Regression: the zone route parameter must bind, otherwise this 404s.
    $bin = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/warehouse-zones/{$zone['id']}/bins", [
            'code' => 'A-01',
            'label' => 'Aisle A',
        ])
        ->assertStatus(201)
        ->json('data');

    expect((int) $bin['warehouse_zone_id'])->toBe((int) $zone['id']);

    $zones = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/warehouse-zones')
        ->assertOk()
        ->json('data');

    expect($zones)->toHaveCount(1)
        ->and($zones[0]['bins'])->toHaveCount(1)
        ->and($zones[0]['bins'][0]['code'])->toBe('A-01');

    expect(WarehouseBin::where('warehouse_zone_id', $zone['id'])->count())->toBe(1);
});

test('warehouses and zones stay inside their business', function () {
    $a = warehouseCtx($this, 'WA');

    $aZone = warehouseZone($this, $a, 'WA');
    $aWarehouse = $this->withHeaders($a['bizHeaders'])
        ->getJson('/api/v1/warehouses')
        ->json('data.0');

    $b = warehouseCtx($this, 'WB2');

    // B cannot attach a bin to A's zone.
    $this->withHeaders($b['bizHeaders'])
        ->postJson("/api/v1/warehouse-zones/{$aZone['id']}/bins", ['code' => 'X-01'])
        ->assertStatus(404);

    // B cannot point a new zone at A's warehouse.
    $this->withHeaders($b['bizHeaders'])->postJson('/api/v1/warehouse-zones', [
        'warehouse_id' => $aWarehouse['id'],
        'name' => 'Foreign',
        'code' => 'Z-F',
    ])->assertStatus(404);

    $this->withHeaders($b['bizHeaders'])
        ->getJson('/api/v1/warehouse-zones')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(WarehouseBin::where('warehouse_zone_id', $aZone['id'])->count())->toBe(0);
});

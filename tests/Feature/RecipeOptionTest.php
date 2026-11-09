<?php

use App\Models\Payment;
use App\Models\User;

/**
 * Optional recipe lines and the modifier options that remove them, the recipe
 * snapshot captured on each sale line, and ingredient safety stock.
 */
function roSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Option Cafe'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Bar', 'code' => 'OPT'])->json('data');

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Latte',
        'sku' => 'LAT-OPT',
        'selling_price' => 30000,
        'cost_price' => 10000,
        'track_inventory' => true,
    ])->json('data');

    $saleHeaders = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];
    $test->withHeaders($saleHeaders)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 100,
        'reason' => 'init',
    ]);

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'product', 'saleHeaders');
}

function roIngredient($test, array $ctx, array $overrides = []): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients', array_merge([
        'name' => 'Beans',
        'sku' => 'RO-'.uniqid(),
        'unit' => 'g',
        'cost_per_unit' => 100,
        'reorder_level' => 100,
        'opening_quantity' => 1000,
        'outlet_id' => $ctx['outlet']['id'],
    ], $overrides))->json('data');
}

function roRecipe($test, array $ctx, array $items): void
{
    $test->withHeaders($ctx['bizHeaders'])
        ->putJson('/api/v1/products/'.$ctx['product']['id'].'/recipe', ['items' => $items])
        ->assertStatus(200);
}

function roBalance($test, array $ctx, array $ingredient): string
{
    $response = $test->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id']);

    $row = collect($response->json('data'))->firstWhere('id', (int) $ingredient['id']);

    return $row['quantity'] ?? 'missing';
}

/**
 * A single-choice group whose only option takes the ingredient out.
 */
function roRemovalOption($test, array $ctx, array $ingredient, string $name = 'No milk'): array
{
    $group = $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Dairy',
        'selection_type' => 'single',
        'options' => [
            ['name' => $name, 'price_delta' => 0, 'removes_ingredient_id' => (int) $ingredient['id']],
        ],
    ]);

    $group->assertStatus(201);
    $created = $group->json('data');

    $test->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/modifier-groups/{$created['id']}/attach", [
        'assignable_type' => 'product',
        'assignable_id' => $ctx['product']['id'],
    ])->assertStatus(200);

    return $created['options'][0];
}

function roSale($test, array $ctx, array $optionIds = [], float $quantity = 1): array
{
    $item = ['product_id' => $ctx['product']['id'], 'quantity' => $quantity];
    if ($optionIds !== []) {
        $item['option_ids'] = $optionIds;
    }

    $response = $test->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [$item],
    ]);

    $response->assertStatus(201);

    return $response->json('data');
}

test('a selected modifier drops the optional ingredient it removes', function () {
    $ctx = roSetup($this);
    $beans = roIngredient($this, $ctx, ['name' => 'Beans']);
    $milk = roIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml']);

    roRecipe($this, $ctx, [
        ['ingredient_id' => $beans['id'], 'quantity' => 18],
        ['ingredient_id' => $milk['id'], 'quantity' => 100, 'optional' => true],
    ]);

    $option = roRemovalOption($this, $ctx, $milk);

    roSale($this, $ctx, [(int) $option['id']]);

    expect(roBalance($this, $ctx, $beans))->toBe('982.0000');
    expect(roBalance($this, $ctx, $milk))->toBe('1000.0000');
});

test('the same recipe still deducts the milk when the option is not chosen', function () {
    $ctx = roSetup($this);
    $milk = roIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml']);
    roRecipe($this, $ctx, [['ingredient_id' => $milk['id'], 'quantity' => 100, 'optional' => true]]);

    roRemovalOption($this, $ctx, $milk);

    roSale($this, $ctx);

    expect(roBalance($this, $ctx, $milk))->toBe('900.0000');
});

test('a required recipe line is not removed by a modifier', function () {
    $ctx = roSetup($this);
    $milk = roIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml']);
    roRecipe($this, $ctx, [['ingredient_id' => $milk['id'], 'quantity' => 100]]);

    $option = roRemovalOption($this, $ctx, $milk);

    roSale($this, $ctx, [(int) $option['id']]);

    expect(roBalance($this, $ctx, $milk))->toBe('900.0000');
});

test('an option can only remove an ingredient from its own business', function () {
    $ctx = roSetup($this);

    $intruder = User::factory()->create();
    $token = $intruder->createToken('t')->plainTextToken;
    // Sanctum caches the resolved user for the request, so drop it before
    // acting as someone else.
    $this->app['auth']->forgetGuards();
    // Headers from the first business would otherwise ride along.
    $this->flushHeaders();
    $auth = ['Authorization' => "Bearer $token"];
    $biz = $this->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Other Cafe'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];
    $otherOutlet = $this->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => 'Other', 'code' => 'OTH'])
        ->json('data');
    // withHeaders persists for the rest of the test, so point the header at the
    // intruder's own outlet instead of the first business's one.
    $bizHeaders['X-Outlet-Id'] = $otherOutlet['id'];

    $created = $this->withHeaders($bizHeaders)->postJson('/api/v1/ingredients', [
        'name' => 'Foreign milk',
        'sku' => 'FOREIGN-1',
        'unit' => 'ml',
    ]);
    $created->assertStatus(201);
    $foreign = $created->json('data');

    $this->app['auth']->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/modifier-groups', [
        'name' => 'Dairy',
        'options' => [['name' => 'No milk', 'removes_ingredient_id' => (int) $foreign['id']]],
    ])->assertStatus(404);
});

test('each sale line snapshots the recipe it deducted', function () {
    $ctx = roSetup($this);
    $beans = roIngredient($this, $ctx, ['name' => 'Beans', 'cost_per_unit' => 100]);
    $milk = roIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml', 'cost_per_unit' => 20]);

    roRecipe($this, $ctx, [
        ['ingredient_id' => $beans['id'], 'quantity' => 18],
        ['ingredient_id' => $milk['id'], 'quantity' => 100, 'optional' => true],
    ]);

    $option = roRemovalOption($this, $ctx, $milk);
    $sale = roSale($this, $ctx, [(int) $option['id']]);

    $snapshot = collect($sale['items'][0]['recipe_snapshot']);

    expect($snapshot)->toHaveCount(1);
    expect($snapshot->first()['ingredient_id'])->toBe((int) $beans['id']);
    expect($snapshot->first()['quantity'])->toBe('18.0000');
    expect($snapshot->first()['unit_cost'])->toBe('100.0000');

    // A later cost edit does not rewrite what the sold line cost.
    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/ingredients/{$beans['id']}", [
        'cost_per_unit' => 250,
    ])->assertStatus(200);

    $shown = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/sales/'.$sale['id']);
    $shown->assertStatus(200);
    expect($shown->json('data.items.0.recipe_snapshot.0.unit_cost'))->toBe('100.0000');
});

test('a refund restores the snapshot, not a recipe edited afterwards', function () {
    $ctx = roSetup($this);
    $beans = roIngredient($this, $ctx, ['name' => 'Beans']);

    roRecipe($this, $ctx, [['ingredient_id' => $beans['id'], 'quantity' => 10]]);

    $sale = roSale($this, $ctx, [], 2);
    expect(roBalance($this, $ctx, $beans))->toBe('980.0000');

    // Recipe grows after the sale; the refund must not hand back 30 a unit.
    roRecipe($this, $ctx, [['ingredient_id' => $beans['id'], 'quantity' => 30]]);

    $payment = Payment::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $sale['id'],
        'method' => 'cash',
        'amount' => $sale['total'],
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $refund = $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/payments/{$payment->id}/refund", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ]);
    $refund->assertStatus(201);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson('/api/v1/refunds/'.$refund->json('data.id').'/approve')
        ->assertStatus(200);

    expect(roBalance($this, $ctx, $beans))->toBe('1000.0000');
});

test('safety stock marks stock critical before the reorder level fires', function () {
    $ctx = roSetup($this);
    $beans = roIngredient($this, $ctx, [
        'name' => 'Beans',
        'reorder_level' => 100,
        'safety_stock' => 500,
        'opening_quantity' => 1000,
    ]);

    $row = collect($this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id'])
        ->json('data'))->firstWhere('id', (int) $beans['id']);

    expect($row['safety_stock'])->toBe('500.0000');
    expect($row['critical'])->toBeFalse();
    expect($row['low_stock'])->toBeFalse();

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients/adjust', [
        'ingredient_id' => $beans['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'quantity' => -600,
        'type' => 'wastage',
        'reason' => 'Prepared batch discarded',
    ])->assertStatus(200);

    $row = collect($this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id'])
        ->json('data'))->firstWhere('id', (int) $beans['id']);

    expect($row['quantity'])->toBe('400.0000');
    expect($row['critical'])->toBeTrue();
    // 400 is still above the reorder level, so the reorder alert stays quiet.
    expect($row['low_stock'])->toBeFalse();

    // The mobile inventory banner reads the dashboard, not the index.
    $dashboard = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients/dashboard?outlet_id='.$ctx['outlet']['id']);
    $dashboard->assertStatus(200);
    $usage = collect($dashboard->json('data'))->firstWhere('ingredient_id', (string) $beans['id']);

    expect($usage['safety_stock'])->toBe('500.0000');
    expect($usage['critical'])->toBeTrue();
    expect($usage['low_stock'])->toBeFalse();
});

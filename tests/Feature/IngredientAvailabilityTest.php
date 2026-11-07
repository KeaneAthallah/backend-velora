<?php

use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;

function invSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Cafe Inventory'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Bar', 'code' => 'BAR'])->json('data');

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Iced Latte',
        'sku' => 'LAT-ICE',
        'selling_price' => 30000,
        'cost_price' => 10000,
        'track_inventory' => true,
    ])->json('data');

    $saleHeaders = $bizHeaders + ['X-Outlet-Id' => $outlet['id']];

    $test->withHeaders($saleHeaders)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 200,
        'reason' => 'init',
    ]);

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'product', 'saleHeaders');
}

function invIngredient($test, array $ctx, array $overrides = []): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients', array_merge([
        'name' => 'Espresso Beans',
        'sku' => 'INV-'.uniqid(),
        'unit' => 'g',
        'cost_per_unit' => 100,
        'reorder_level' => 50,
        'opening_quantity' => 1000,
        'outlet_id' => $ctx['outlet']['id'],
    ], $overrides))->json('data');
}

function invBalance($test, array $ctx, array $ingredient): string
{
    $response = $test->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id']);

    $row = collect($response->json('data'))->firstWhere('id', (int) $ingredient['id']);

    return $row['quantity'] ?? 'missing';
}

function invSale($test, array $ctx, float $quantity = 1, ?array $product = null): array
{
    $response = $test->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => ($product ?? $ctx['product'])['id'], 'quantity' => $quantity]],
    ]);

    $response->assertStatus(201);

    return $response->json('data');
}

test('recipe quantities convert into the ingredient stocking unit', function () {
    $ctx = invSetup($this);
    $beans = invIngredient($this, $ctx, [
        'name' => 'Beans (kilo)',
        'unit' => 'kg',
        'cost_per_unit' => 100000,
        'opening_quantity' => 1,
    ]);

    $saved = $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18, 'unit' => 'g']],
        ]);

    $saved->assertStatus(200);
    expect($saved->json('data.recipe_items.0.unit'))->toBe('g');
    expect($saved->json('data.recipe_items.0.ingredient_unit'))->toBe('kg');
    // 18 g expressed in the ingredient's stocking unit.
    expect($saved->json('data.recipe_items.0.effective_quantity'))->toBe('0.0180');
    // 0.018 kg * 100000 per kg.
    expect($saved->json('data.recipe_cost'))->toBe('1800.0000');

    invSale($this, $ctx, 2);

    // 1 kg - (2 * 0.018 kg)
    expect(invBalance($this, $ctx, $beans))->toBe('0.9640');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$beans['id']);
    expect($movements->json('data.data.0.quantity'))->toBe('-0.0360');
});

test('recipe lines reject units of a different dimension without wiping the recipe', function () {
    $ctx = invSetup($this);
    $beans = invIngredient($this, $ctx, ['unit' => 'g']);
    $milk = invIngredient($this, $ctx, ['name' => 'Fresh Milk', 'unit' => 'ml']);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18, 'unit' => 'g']],
        ])->assertStatus(200);

    // grams cannot become millilitres
    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $milk['id'], 'quantity' => 150, 'unit' => 'g']],
        ])->assertStatus(422)->assertJsonPath('code', 'RECIPE_ERROR');

    // The previous recipe is untouched.
    $recipe = $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/products/{$ctx['product']['id']}/recipe");
    expect($recipe->json('data.recipe_items'))->toHaveCount(1);
    expect($recipe->json('data.recipe_items.0.ingredient_id'))->toBe((string) $beans['id']);
});

test('availability reports the limiting ingredient and the sellable count', function () {
    $ctx = invSetup($this);
    $beans = invIngredient($this, $ctx, ['name' => 'Beans', 'unit' => 'g', 'opening_quantity' => 500]);
    $milk = invIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml', 'opening_quantity' => 300]);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [
                ['ingredient_id' => $beans['id'], 'quantity' => 18, 'unit' => 'g'],
                ['ingredient_id' => $milk['id'], 'quantity' => 150, 'unit' => 'ml'],
            ],
        ])->assertStatus(200);

    // Beans allow 27 cups, milk only 2 — milk limits production.
    $availability = $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/products/{$ctx['product']['id']}/availability?outlet_id={$ctx['outlet']['id']}");

    $availability->assertStatus(200);
    expect($availability->json('data.sellable'))->toBe(2);
    expect($availability->json('data.unlimited'))->toBe(false);
    expect($availability->json('data.limiting.ingredient_name'))->toBe('Milk');
    expect($availability->json('data.lines'))->toHaveCount(2);

    invSale($this, $ctx, 1);

    $after = $this->withHeaders($ctx['bizHeaders'])
        ->getJson("/api/v1/products/{$ctx['product']['id']}/availability?outlet_id={$ctx['outlet']['id']}");
    expect($after->json('data.sellable'))->toBe(1);
    expect($after->json('data.limiting.available'))->toBe('150.0000');

    // Selling more than the milk allows is refused outright.
    $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ])->assertStatus(422)->assertJsonPath('code', 'SALE_ERROR');
});

test('the whole menu reports availability in one call', function () {
    $ctx = invSetup($this);
    $beans = invIngredient($this, $ctx, ['unit' => 'g', 'opening_quantity' => 36]);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18, 'unit' => 'g']],
        ])->assertStatus(200);

    $menu = $this->withHeaders($ctx['saleHeaders'])
        ->getJson('/api/v1/recipes/availability?outlet_id='.$ctx['outlet']['id']);

    $menu->assertStatus(200);
    expect($menu->json('data'))->toHaveCount(1);
    expect($menu->json('data.0.product_id'))->toBe((string) $ctx['product']['id']);
    expect($menu->json('data.0.sellable'))->toBe(2);
    expect($menu->json('data.0.unlimited'))->toBe(false);
    expect($menu->json('data.0.limiting.ingredient_name'))->toBe('Espresso Beans');
});

test('ingredient dashboard rolls up shared usage across products', function () {
    $ctx = invSetup($this);
    $milk = invIngredient($this, $ctx, ['name' => 'Fresh Milk', 'unit' => 'ml', 'opening_quantity' => 3000]);

    $kopi = $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/products', [
        'name' => 'Kopi Susu',
        'sku' => 'KOPI-SUSU',
        'selling_price' => 22000,
        'cost_price' => 9000,
        'track_inventory' => true,
    ])->json('data');

    $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/inventory/adjust', [
        'product_id' => $kopi['id'],
        'quantity' => 200,
        'reason' => 'init',
    ])->assertStatus(200);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $milk['id'], 'quantity' => 200, 'unit' => 'ml']],
        ])->assertStatus(200);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$kopi['id']}/recipe", [
            'items' => [['ingredient_id' => $milk['id'], 'quantity' => 150, 'unit' => 'ml']],
        ])->assertStatus(200);

    $dashboard = $this->withHeaders($ctx['saleHeaders'])
        ->getJson('/api/v1/ingredients/dashboard?outlet_id='.$ctx['outlet']['id']);

    $dashboard->assertStatus(200);
    $row = collect($dashboard->json('data'))->firstWhere('ingredient_id', (string) $milk['id']);

    expect($row['remaining'])->toBe('3000.0000');
    expect($row['consumed'])->toBe('0.0000');
    expect($row['products'])->toHaveCount(2);

    $producible = collect($row['products'])->pluck('producible', 'product_name')->all();
    expect($producible['Iced Latte'])->toBe(15);
    expect($producible['Kopi Susu'])->toBe(20);

    invSale($this, $ctx, 1);
    invSale($this, $ctx, 1, $kopi);

    $dashboard = $this->withHeaders($ctx['saleHeaders'])
        ->getJson('/api/v1/ingredients/dashboard?outlet_id='.$ctx['outlet']['id']);
    $row = collect($dashboard->json('data'))->firstWhere('ingredient_id', (string) $milk['id']);

    // 3000 - 200 - 150
    expect($row['remaining'])->toBe('2650.0000');
    expect($row['consumed'])->toBe('350.0000');
    expect($row['low_stock'])->toBe(false);
});

test('a full refund restores ingredients while partial refunds and voids do not double count', function () {
    $ctx = invSetup($this);
    $beans = invIngredient($this, $ctx, ['unit' => 'g', 'opening_quantity' => 1000]);

    $this->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18, 'unit' => 'g']],
        ])->assertStatus(200);

    $sale = invSale($this, $ctx, 1);
    expect(invBalance($this, $ctx, $beans))->toBe('982.0000');

    $payment = Payment::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $sale['id'],
        'method' => 'cash',
        'amount' => $sale['total'],
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    // A partial refund is amount-only, so stock is meant to stay deducted.
    $partial = Refund::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $sale['id'],
        'payment_id' => $payment->id,
        'amount' => '1.0000',
        'status' => 'pending',
        'requested_by' => $ctx['user']->id,
    ]);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/refunds/{$partial->id}/approve")->assertStatus(200);
    expect(invBalance($this, $ctx, $beans))->toBe('982.0000');

    // Completing the refund restores the recipe quantities.
    $rest = Refund::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $sale['id'],
        'payment_id' => $payment->id,
        'amount' => bcsub((string) $sale['total'], '1.0000', 4),
        'status' => 'pending',
        'requested_by' => $ctx['user']->id,
    ]);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/refunds/{$rest->id}/approve")->assertStatus(200);

    expect(invBalance($this, $ctx, $beans))->toBe('1000.0000');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$beans['id']);
    expect($movements->json('data.data.0.type'))->toBe('void');
    expect($movements->json('data.data.0.quantity'))->toBe('18.0000');

    // Voiding an already-reversed sale must not inflate stock again.
    $second = invSale($this, $ctx, 1);
    expect(invBalance($this, $ctx, $beans))->toBe('982.0000');

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/sales/{$second['id']}/void", ['reason' => 'Wrong order'])->assertStatus(200);
    expect(invBalance($this, $ctx, $beans))->toBe('1000.0000');

    $secondPayment = Payment::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $second['id'],
        'method' => 'cash',
        'amount' => $second['total'],
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    $voidedRefund = Refund::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $second['id'],
        'payment_id' => $secondPayment->id,
        'amount' => $second['total'],
        'status' => 'pending',
        'requested_by' => $ctx['user']->id,
    ]);

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson("/api/v1/refunds/{$voidedRefund->id}/approve")->assertStatus(200);

    // Still 1000 — the void already restored the ingredients.
    expect(invBalance($this, $ctx, $beans))->toBe('1000.0000');
});

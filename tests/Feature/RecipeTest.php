<?php

use App\Models\BusinessMembership;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;

function recipeSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Recipe Biz'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])->json('data');

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Latte',
        'sku' => 'LAT-1',
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

    return compact('user', 'token', 'auth', 'biz', 'bizHeaders', 'outlet', 'product', 'saleHeaders');
}

function recipeIngredient($test, array $ctx, array $overrides = []): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients', array_merge([
        'name' => 'Espresso beans',
        'sku' => 'BEAN-1',
        'unit' => 'g',
        'cost_per_unit' => 100,
        'reorder_level' => 100,
        'opening_quantity' => 1000,
        'outlet_id' => $ctx['outlet']['id'],
    ], $overrides))->json('data');
}

function recipeBalance($test, array $ctx, array $ingredient, ?string $outletId = null): string
{
    $response = $test->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.($outletId ?? $ctx['outlet']['id']));

    $row = collect($response->json('data'))->firstWhere('id', (int) $ingredient['id']);

    return $row['quantity'] ?? 'missing';
}

test('ingredient crud, opening stock, adjustments and movements', function () {
    $ctx = recipeSetup($this);
    $ingredient = recipeIngredient($this, $ctx);

    expect(recipeBalance($this, $ctx, $ingredient))->toBe('1000.0000');

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients/adjust', [
        'ingredient_id' => $ingredient['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'quantity' => 250,
        'type' => 'purchase',
        'unit_cost' => 120,
        'reason' => 'Delivery',
    ])->assertStatus(200);

    expect(recipeBalance($this, $ctx, $ingredient))->toBe('1250.0000');

    $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id'])
        ->assertStatus(200)
        ->assertJsonPath('data.0.cost_per_unit', '120.0000')
        ->assertJsonPath('data.0.low_stock', false);

    $this->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients/adjust', [
        'ingredient_id' => $ingredient['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'quantity' => -2000,
        'reason' => 'Too much',
    ])->assertStatus(422);

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$ingredient['id']);
    $movements->assertStatus(200);
    expect($movements->json('data.data'))->toHaveCount(2);
    expect($movements->json('data.data.0.type'))->toBe('purchase');
    expect($movements->json('data.data.0.quantity'))->toBe('250.0000');

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/ingredients/{$ingredient['id']}", [
        'reorder_level' => 200,
    ])->assertStatus(200)->assertJsonPath('data.reorder_level', '200.0000');

    // Used ingredients keep their history, so they can only be deactivated.
    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/ingredients/{$ingredient['id']}")->assertStatus(422);

    $unused = recipeIngredient($this, $ctx, ['name' => 'Cups', 'sku' => 'CUP-1', 'unit' => 'pcs', 'opening_quantity' => 0]);
    $this->withHeaders($ctx['bizHeaders'])->deleteJson("/api/v1/ingredients/{$unused['id']}")->assertStatus(200);
});

test('recipe sync prices waste and variant overrides', function () {
    $ctx = recipeSetup($this);
    $beans = recipeIngredient($this, $ctx);
    $milk = recipeIngredient($this, $ctx, ['name' => 'Milk', 'sku' => 'MILK-1', 'unit' => 'ml', 'cost_per_unit' => 20]);

    $variant = ProductVariant::create([
        'product_id' => $ctx['product']['id'],
        'name' => 'Large',
        'sku' => 'LAT-1-L',
        'selling_price' => 38000,
        'cost_price' => 12000,
    ]);

    $saved = $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [
            ['ingredient_id' => $beans['id'], 'quantity' => 18, 'waste_percent' => 10],
            ['ingredient_id' => $milk['id'], 'quantity' => 100],
        ],
    ]);

    $saved->assertStatus(200);
    // 18g * 1.10 * 100 = 1980, plus 100ml * 20 = 2000.
    expect($saved->json('data.recipe_cost'))->toBe('3980.0000');
    expect($saved->json('data.recipe_items'))->toHaveCount(2);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'product_variant_id' => $variant->id,
        'items' => [['ingredient_id' => $milk['id'], 'quantity' => 150]],
    ])->assertStatus(200);

    $recipe = $this->withHeaders($ctx['bizHeaders'])->getJson("/api/v1/products/{$ctx['product']['id']}/recipe");
    $recipe->assertStatus(200);
    expect($recipe->json('data.recipe_items'))->toHaveCount(3);
    // Base beans 1980 + variant milk 150 * 20 = 4980.
    expect($recipe->json('data.variant_costs.'.$variant->id))->toBe('4980.0000');
    expect($recipe->json('data.recipe_cost'))->toBe('3980.0000');

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [
            ['ingredient_id' => $beans['id'], 'quantity' => 18],
            ['ingredient_id' => $beans['id'], 'quantity' => 20],
        ],
    ])->assertStatus(422);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [['ingredient_id' => 999999, 'quantity' => 1]],
    ])->assertStatus(404);

    $listing = $this->withHeaders($ctx['bizHeaders'])->getJson('/api/v1/recipes?with_recipe=1');
    $listing->assertStatus(200);
    expect($listing->json('data'))->toHaveCount(1);
    expect($listing->json('data.0.id'))->toBe($ctx['product']['id']);
});

test('sale consumes recipe ingredients and void restores them', function () {
    $ctx = recipeSetup($this);
    $beans = recipeIngredient($this, $ctx);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18]],
    ])->assertStatus(200);

    $sale = $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 2]],
    ]);

    $sale->assertStatus(201);
    expect(recipeBalance($this, $ctx, $beans))->toBe('964.0000');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$beans['id']);
    expect($movements->json('data.data.0.type'))->toBe('consumption');
    expect($movements->json('data.data.0.quantity'))->toBe('-36.0000');
    expect($movements->json('data.data.0.reference_type'))->toBe('sale');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/sales/{$sale->json('data.id')}/void", [
        'reason' => 'Wrong order',
    ])->assertStatus(200);

    expect(recipeBalance($this, $ctx, $beans))->toBe('1000.0000');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$beans['id']);
    expect($movements->json('data.data.0.type'))->toBe('void');
    expect($movements->json('data.data.0.quantity'))->toBe('36.0000');
});

test('insufficient ingredient stock blocks the sale', function () {
    $ctx = recipeSetup($this);
    $beans = recipeIngredient($this, $ctx, ['opening_quantity' => 10]);

    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [['ingredient_id' => $beans['id'], 'quantity' => 18]],
    ])->assertStatus(200);

    $sale = $this->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => 1]],
    ]);

    $sale->assertStatus(422)->assertJsonPath('code', 'SALE_ERROR');
    expect(recipeBalance($this, $ctx, $beans))->toBe('10.0000');
});

test('ingredients and recipes are tenant scoped', function () {
    $ctx = recipeSetup($this);
    $beans = recipeIngredient($this, $ctx);

    $other = User::factory()->create();
    $otherToken = $other->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $otherAuth = ['Authorization' => "Bearer $otherToken"];
    $otherBiz = $this->withHeaders($otherAuth)->postJson('/api/v1/businesses', ['name' => 'Other Biz'])->json('data');
    $otherHeaders = $otherAuth + ['X-Business-Id' => $otherBiz['id']];

    $this->flushHeaders();
    $this->withHeaders($otherHeaders)->putJson("/api/v1/ingredients/{$beans['id']}", ['name' => 'Hacked'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [],
    ])->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id'])->assertStatus(404);
    $this->withHeaders($otherHeaders)->getJson('/api/v1/recipes')->assertStatus(200)
        ->assertJsonCount(0, 'data');

    $this->withHeaders($otherHeaders)->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [['ingredient_id' => $beans['id'], 'quantity' => 1]],
    ])->assertStatus(404);

    $this->app['auth']->forgetGuards();
    $this->withHeaders($ctx['bizHeaders'])->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
        'items' => [['ingredient_id' => $beans['id'], 'quantity' => 1]],
    ])->assertStatus(200);
});

test('ingredient and recipe management requires permissions', function () {
    $ctx = recipeSetup($this);

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
    $this->flushHeaders();

    $this->withHeaders($headers)->getJson('/api/v1/ingredients')->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/ingredients', ['name' => 'Nope'])->assertStatus(403);
    $this->withHeaders($headers)->postJson('/api/v1/ingredients/adjust', [
        'ingredient_id' => 1,
        'outlet_id' => $ctx['outlet']['id'],
        'quantity' => 1,
        'reason' => 'Nope',
    ])->assertStatus(403);
    $this->withHeaders($headers)->getJson('/api/v1/recipes')->assertStatus(403);
    $this->withHeaders($headers)->getJson("/api/v1/products/{$ctx['product']['id']}/recipe")->assertStatus(403);
});

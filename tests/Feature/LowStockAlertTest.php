<?php

use App\Models\User;
use App\Models\UserNotification;

function lsSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Cafe Alerts'])->json('data');
    $bizHeaders = $auth + ['X-Business-Id' => $biz['id']];

    $outlet = $test->withHeaders($bizHeaders)->postJson('/api/v1/outlets', ['name' => 'Bar', 'code' => 'BAR'])->json('data');

    $product = $test->withHeaders($bizHeaders)->postJson('/api/v1/products', [
        'name' => 'Kopi Susu',
        'sku' => 'KOPI-ALERT',
        'selling_price' => 22000,
        'cost_price' => 9000,
        'track_inventory' => true,
    ])->json('data');

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'product');
}

function lsIngredient($test, array $ctx, array $overrides = []): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients', array_merge([
        'name' => 'Fresh Milk',
        'sku' => 'LS-'.uniqid(),
        'unit' => 'ml',
        'cost_per_unit' => 20,
        'reorder_level' => 500,
        'opening_quantity' => 400,
        'outlet_id' => $ctx['outlet']['id'],
    ], $overrides))->json('data');
}

function lsRecipe($test, array $ctx, array $ingredient, float $quantity = 150): void
{
    $test->withHeaders($ctx['bizHeaders'])
        ->putJson("/api/v1/products/{$ctx['product']['id']}/recipe", [
            'items' => [[
                'ingredient_id' => $ingredient['id'],
                'quantity' => $quantity,
                'unit' => $ingredient['unit'],
            ]],
        ])->assertStatus(200);
}

test('an ingredient at its reorder level alerts members and names the affected menu items', function () {
    $ctx = lsSetup($this);
    $milk = lsIngredient($this, $ctx);
    lsRecipe($this, $ctx, $milk);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    $notification = UserNotification::where('user_id', $ctx['user']->id)
        ->where('type', 'inventory.low_stock')
        ->first();

    expect($notification)->not->toBeNull();
    expect($notification->title)->toBe('Low stock: Fresh Milk');
    // 400 ml at 150 ml a cup leaves two cups.
    expect($notification->body)->toContain('Kopi Susu can make 2 more');
    expect($notification->data['ingredient_id'])->toBe((string) $milk['id']);
    expect($notification->data['outlet_id'])->toBe((string) $ctx['outlet']['id']);
    expect($notification->data['products'][0]['producible'])->toBe(2);

    // A second run the same day does not repeat itself.
    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);
    expect(UserNotification::where('type', 'inventory.low_stock')->count())->toBe(1);
});

test('ingredients comfortably above their reorder level stay quiet', function () {
    $ctx = lsSetup($this);
    $milk = lsIngredient($this, $ctx, ['opening_quantity' => 5000]);
    lsRecipe($this, $ctx, $milk);

    $this->artisan('inventory:scan-low-stock')->assertExitCode(0);

    expect(UserNotification::where('type', 'inventory.low_stock')->count())->toBe(0);
});

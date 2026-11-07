<?php

use App\Models\Payment;
use App\Models\User;

/**
 * Item-level refunds: a refund that names the returned sale lines must put back
 * exactly those lines' recipe ingredients, on a partial refund as well as a full
 * one, and never restore more than the sale deducted.
 */
function riSetup($test): array
{
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)->postJson('/api/v1/businesses', ['name' => 'Cafe Refunds'])->json('data');
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

    return compact('user', 'auth', 'biz', 'bizHeaders', 'outlet', 'product', 'saleHeaders');
}

function riIngredient($test, array $ctx, array $overrides = []): array
{
    return $test->withHeaders($ctx['bizHeaders'])->postJson('/api/v1/ingredients', array_merge([
        'name' => 'Beans',
        'sku' => 'RI-'.uniqid(),
        'unit' => 'g',
        'cost_per_unit' => 100,
        'reorder_level' => 50,
        'opening_quantity' => 1000,
        'outlet_id' => $ctx['outlet']['id'],
    ], $overrides))->json('data');
}

function riRecipe($test, array $ctx, array $ingredients, ?array $product = null): void
{
    $items = collect($ingredients)
        ->map(fn (array $entry) => [
            'ingredient_id' => $entry[0]['id'],
            'quantity' => $entry[1],
            'unit' => $entry[0]['unit'] ?? 'g',
        ])
        ->all();

    $test->withHeaders($ctx['bizHeaders'])
        ->putJson('/api/v1/products/'.($product ?? $ctx['product'])['id'].'/recipe', ['items' => $items])
        ->assertStatus(200);
}

function riBalance($test, array $ctx, array $ingredient): string
{
    $response = $test->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredients?outlet_id='.$ctx['outlet']['id']);

    $row = collect($response->json('data'))->firstWhere('id', (int) $ingredient['id']);

    return $row['quantity'] ?? 'missing';
}

function riSale($test, array $ctx, float $quantity = 1): array
{
    $response = $test->withHeaders($ctx['saleHeaders'])->postJson('/api/v1/sales', [
        'outlet_id' => $ctx['outlet']['id'],
        'items' => [['product_id' => $ctx['product']['id'], 'quantity' => $quantity]],
    ]);

    $response->assertStatus(201);

    return $response->json('data');
}

function riPayment(array $ctx, array $sale, ?string $amount = null): Payment
{
    return Payment::create([
        'business_id' => $ctx['biz']['id'],
        'sale_id' => $sale['id'],
        'method' => 'cash',
        'amount' => $amount ?? $sale['total'],
        'status' => 'completed',
        'paid_at' => now(),
    ]);
}

test('a partial refund that returns lines restores only those lines', function () {
    $ctx = riSetup($this);
    $beans = riIngredient($this, $ctx, ['name' => 'Beans', 'unit' => 'g']);
    $milk = riIngredient($this, $ctx, ['name' => 'Milk', 'unit' => 'ml', 'opening_quantity' => 1000]);

    riRecipe($this, $ctx, [[$beans, 18], [$milk, 150]]);

    $sale = riSale($this, $ctx, 3);
    expect(riBalance($this, $ctx, $beans))->toBe('946.0000');
    expect(riBalance($this, $ctx, $milk))->toBe('550.0000');

    $payment = riPayment($ctx, $sale);
    $saleItemId = $sale['items'][0]['id'];

    // No amount: it is priced from the returned line's share of the total.
    $request = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]],
    ]);

    $request->assertStatus(201);
    expect($request->json('data.amount'))->toBe('30000.0000');
    expect($request->json('data.items.0.sale_item_id'))->toBe($saleItemId);
    expect($request->json('data.items.0.quantity'))->toBe('1.0000');

    $this->withHeaders($ctx['bizHeaders'])
        ->postJson('/api/v1/refunds/'.$request->json('data.id').'/approve')
        ->assertStatus(200);

    // One cup came back; two are still consumed.
    expect(riBalance($this, $ctx, $beans))->toBe('964.0000');
    expect(riBalance($this, $ctx, $milk))->toBe('700.0000');

    $movements = $this->withHeaders($ctx['bizHeaders'])
        ->getJson('/api/v1/ingredient-movements?ingredient_id='.$beans['id']);
    expect($movements->json('data.data.0.type'))->toBe('void');
    expect($movements->json('data.data.0.quantity'))->toBe('18.0000');
    expect($movements->json('data.data.0.reason'))->toBe('Refund #'.$request->json('data.id'));
});

test('refund lines cannot exceed what the sale still holds', function () {
    $ctx = riSetup($this);
    $beans = riIngredient($this, $ctx, ['unit' => 'g']);
    riRecipe($this, $ctx, [[$beans, 18]]);

    $sale = riSale($this, $ctx, 2);
    $payment = riPayment($ctx, $sale);
    $saleItemId = $sale['items'][0]['id'];

    $first = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'amount' => 1,
        'items' => [['sale_item_id' => $saleItemId, 'quantity' => 2]],
    ]);
    $first->assertStatus(201);

    // Pending refunds already reserve their quantities.
    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'amount' => 1,
        'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('code', 'REFUND_ERROR');

    // A line from another sale is rejected outright.
    $other = riSale($this, $ctx, 1);
    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'amount' => 1,
        'items' => [['sale_item_id' => $other['items'][0]['id'], 'quantity' => 1]],
    ])->assertStatus(422)->assertJsonPath('code', 'REFUND_ERROR');
});

test('item refunds compose and a later full refund cannot inflate stock', function () {
    $ctx = riSetup($this);
    $beans = riIngredient($this, $ctx, ['unit' => 'g']);
    riRecipe($this, $ctx, [[$beans, 18]]);

    $sale = riSale($this, $ctx, 3);
    $payment = riPayment($ctx, $sale);
    $saleItemId = $sale['items'][0]['id'];
    expect(riBalance($this, $ctx, $beans))->toBe('946.0000');

    $refundOne = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]],
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/refunds/{$refundOne['id']}/approve")->assertStatus(200);
    expect(riBalance($this, $ctx, $beans))->toBe('964.0000');

    // The last two cups come back on a second, separate refund.
    $refundRest = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'items' => [['sale_item_id' => $saleItemId, 'quantity' => 2]],
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/refunds/{$refundRest['id']}/approve")->assertStatus(200);
    expect(riBalance($this, $ctx, $beans))->toBe('1000.0000');

    // Nothing is left on the sale, so an amount-only approval restores nothing.
    $paymentTwo = riPayment($ctx, $sale, '0.5000');
    $amountOnly = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$paymentTwo->id}/refund", [
        'amount' => '0.5000',
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/refunds/{$amountOnly['id']}/approve")->assertStatus(200);
    expect(riBalance($this, $ctx, $beans))->toBe('1000.0000');
});

test('a recipe edited after the sale cannot restore more than was consumed', function () {
    $ctx = riSetup($this);
    $beans = riIngredient($this, $ctx, ['unit' => 'g']);
    riRecipe($this, $ctx, [[$beans, 18]]);

    $sale = riSale($this, $ctx, 1);
    expect(riBalance($this, $ctx, $beans))->toBe('982.0000');

    // The recipe now uses far more beans than the cup that was sold.
    riRecipe($this, $ctx, [[$beans, 50]]);

    $payment = riPayment($ctx, $sale);
    $refund = $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/payments/{$payment->id}/refund", [
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
    ])->json('data');

    $this->withHeaders($ctx['bizHeaders'])->postJson("/api/v1/refunds/{$refund['id']}/approve")->assertStatus(200);

    // Capped at the 18 g originally deducted, not the new 50 g.
    expect(riBalance($this, $ctx, $beans))->toBe('1000.0000');
});

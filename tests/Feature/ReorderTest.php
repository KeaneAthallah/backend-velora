<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Ingredient;
use App\Models\PurchaseOrderItemSuggestedSupplierParcel;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;

/**
 * The reorder copilot's read half (forecast) works off shipped tables; its write
 * half (drafting purchase orders with ingredient lines) stays behind its own
 * flag because `purchase_order_items` is product-based.
 */
function reorderContext($test, string $suffix = 'R'): array
{
    $test->flushHeaders();
    $owner = User::factory()->create();
    $token = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Reorder Biz $suffix"])
        ->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    // A second user with no membership in the business.
    $outsider = User::factory()->create();
    $outsiderToken = $outsider->createToken('t')->plainTextToken;

    return compact('owner', 'biz', 'headers', 'outsider', 'outsiderToken');
}

function reorderDataset($test, array $ctx): array
{
    $outlet = $test->withHeaders($ctx['headers'])
        ->postJson('/api/v1/outlets', ['name' => 'Main', 'code' => 'MAIN'])
        ->json('data');

    $saleHeaders = $ctx['headers'] + ['X-Outlet-Id' => $outlet['id']];

    $product = $test->withHeaders($ctx['headers'])->postJson('/api/v1/products', [
        'name' => 'Iced Latte',
        'sku' => 'RB-LATTE',
        'selling_price' => 30000,
        'cost_price' => 10000,
        'track_inventory' => true,
    ])->json('data');

    $test->withHeaders($saleHeaders)->postJson('/api/v1/inventory/adjust', [
        'product_id' => $product['id'],
        'quantity' => 200,
        'reason' => 'init',
    ])->assertStatus(200);

    $ingredient = $test->withHeaders($ctx['headers'])->postJson('/api/v1/ingredients', [
        'name' => 'Espresso Beans',
        'sku' => 'RB-BEANS',
        'unit' => 'g',
        'cost_per_unit' => 100,
        'reorder_level' => 100,
        // Below the 180 g the demand model wants on hand, so a reorder is due.
        'opening_quantity' => 50,
        'outlet_id' => $outlet['id'],
    ])->json('data');

    // Shelf life and lead time drive the waste-risk and lead-time models; the
    // ingredient endpoint does not accept them yet, so set them directly.
    Ingredient::whereKey($ingredient['id'])->update([
        'shelf_life_days' => 5,
        'lead_time_days' => 3,
    ]);

    // The forecast still reads the legacy `recipes`/`recipe_ingredients` pair.
    $recipe = Recipe::create([
        'business_id' => $ctx['biz']['id'],
        'product_id' => $product['id'],
        'name' => 'Iced Latte',
        'yield_quantity' => 1,
    ]);
    RecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'ingredient_id' => $ingredient['id'],
        'quantity_per_unit' => 18,
        'unit' => 'g',
    ]);

    // 14 cups over the 14-day lookback: 18 g/day, and stock is low enough that
    // the forecast must suggest a reorder.
    $test->withHeaders($saleHeaders)->postJson('/api/v1/sales', [
        'outlet_id' => $outlet['id'],
        'items' => [['product_id' => $product['id'], 'quantity' => 14]],
    ])->assertStatus(201);

    // A supplier parcel for the ingredient: the forecast names it so a reorder
    // breakdown can show what a case actually costs and how long it takes.
    $supplier = Supplier::create([
        'business_id' => $ctx['biz']['id'],
        'name' => 'Bean Co',
        'lead_time_days' => 4,
    ]);
    PurchaseOrderItemSuggestedSupplierParcel::create([
        'business_id' => $ctx['biz']['id'],
        'product_id' => $product['id'],
        'ingredient_id' => $ingredient['id'],
        'supplier_id' => $supplier->id,
        'parcel_quantity' => 1000,
        'parcel_cost' => 90000,
    ]);

    return compact('outlet', 'saleHeaders', 'product', 'ingredient', 'recipe');
}

it('forecasts demand from recipes and sales', function () {
    $ctx = reorderContext($this, 'Forecast');
    $data = reorderDataset($this, $ctx);

    $response = $this->withHeaders($ctx['headers'])->getJson('/api/v1/reorder/forecast');

    $response->assertStatus(200);
    $response->assertJsonPath('success', true);

    $ingredient = $response->json('data.ingredients.0');
    expect($ingredient['id'])->toBe((string) $data['ingredient']['id']);
    expect($ingredient['name'])->toBe('Espresso Beans');
    expect($ingredient['forecast']['method'])->toBe('recipe-demand');
    // 14 cups over a 14-day lookback at 18 g each.
    expect((float) $ingredient['forecast']['dailyDemand'])->toBeGreaterThan(0.0);
    expect((float) $ingredient['forecast']['reorderQuantity'])->toBeGreaterThan(0.0);
    expect($ingredient['forecast']['leadTimeDays'])->toBe(3);
    expect($ingredient['leadTimeDays'])->toBe(3);
    expect($ingredient['consumption']['recipeDriven'])->toBeTrue();

    // Supplier parcels come back per ingredient, cheapest first, with lead time.
    expect($ingredient['suggestedParcels'])->toHaveCount(1);
    expect($ingredient['suggestedParcels'][0]['supplierName'])->toBe('Bean Co');
    expect((float) $ingredient['suggestedParcels'][0]['parcelQuantity'])->toBe(1000.0);
    expect($ingredient['suggestedParcels'][0]['supplierLeadTimeDays'])->toBe(4);
    // 90,000 for 1,000 g, priced per gram for a like-for-like comparison.
    expect((float) $ingredient['suggestedParcels'][0]['unitCost'])->toEqualWithDelta(90.0, 0.0001);

    // Picking that parcel previews the forecast its 4-day lead time produces,
    // not the ingredient's own 3-day lead time.
    $preview = $ingredient['suggestedParcels'][0]['preview'];
    $daily = (float) $ingredient['forecast']['dailyDemand'];
    $coverage = (int) $response->json('data.settings.minCoverageDays');
    expect($preview['leadTimeDays'])->toBe(4);
    expect((float) $preview['leadTimeDemand'])->toEqualWithDelta($daily * 4, 0.0001);
    expect((float) $preview['targetStock'])
        ->toEqualWithDelta($daily * 4 + $daily * $coverage, 0.0001);
    expect((float) $preview['reorderQuantity'])
        ->toEqualWithDelta(max(0.0, $daily * 4 + $daily * $coverage - 50), 0.0001);
    expect($ingredient['forecast']['leadTimeDays'])->toBe(3);

    // The reorder is priced in whole 1,000 g parcels at 90,000 each: 90 per gram,
    // and whatever the case rounds past the cover target stays as surplus.
    $parcels = (int) ceil((float) $preview['reorderQuantity'] / 1000);
    expect($preview['parcelsRequired'])->toBe($parcels);
    expect((float) $preview['totalCost'])->toEqualWithDelta($parcels * 90000, 0.0001);
    expect((float) $preview['leftoverStock'])->toEqualWithDelta(
        50 + $parcels * 1000 - (float) $preview['targetStock'],
        0.0001,
    );

    // The budget is priced in the business currency, not a hardcoded USD.
    expect($response->json('data.purchaseMoneyBudget.amount'))->toBeGreaterThan(0);
    expect($response->json('data.purchaseMoneyBudget.currency'))
        ->toBe(Business::find($ctx['biz']['id'])->currency);

    // A forecast never writes a purchase order.
    expect(DB::table('purchase_orders')->count())->toBe(0);
});

it('keeps purchase-order drafting from the forecast gated', function () {
    $ctx = reorderContext($this, 'Draft');

    $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/reorder/suggest')
        ->assertStatus(501)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'NOT_IMPLEMENTED');

    expect(config('features.reorder_copilot'))->toBeTrue();
    expect(config('features.reorder_purchase_orders'))->toBeFalse();
});

it('enforces RBAC before forecasting', function () {
    $ctx = reorderContext($this, 'Rbac');

    // The previous request authenticated the owner; drop the cached guard.
    $this->app['auth']->forgetGuards();

    // No membership at all in this business.
    $this->withHeaders([
        'Authorization' => "Bearer {$ctx['outsiderToken']}",
        'X-Business-Id' => $ctx['biz']['id'],
    ])->getJson('/api/v1/reorder/forecast')->assertStatus(403);

    // Member of the business whose role lacks purchasing.manage.
    $role = Role::create([
        'business_id' => $ctx['biz']['id'],
        'slug' => 'viewer',
        'name' => 'Viewer',
        'is_system' => false,
    ]);
    $member = User::factory()->create();
    BusinessMembership::create([
        'user_id' => $member->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $token = $member->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();

    $this->withHeaders([
        'Authorization' => "Bearer $token",
        'X-Business-Id' => $ctx['biz']['id'],
    ])->getJson('/api/v1/reorder/forecast')->assertStatus(403);
});

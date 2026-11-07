<?php

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Service;
use App\Models\Storefront;
use App\Models\StorefrontCustomerAccount;
use App\Models\StorefrontOrder;
use App\Models\SubscriptionUsageRecord;
use App\Models\User;

/**
 * Regressions for defects the structural audit found: broken model loading,
 * soft deletes without a `deleted_at` column, and relations pointing at
 * columns that do not exist.
 */
function regressionCtx($test, string $suffix): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Regression $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Outlet $suffix", 'code' => "RG-$suffix"])
        ->json('data');

    return [
        'user' => $user,
        'auth' => $auth,
        'biz' => $biz,
        'bizHeaders' => $bizHeaders,
        'outlet' => $outlet,
        'headers' => array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]),
    ];
}

test('services can be listed, created, updated and deleted', function () {
    $ctx = regressionCtx($this, 'SV');

    // `Service` uses SoftDeletes; the table shipped without `deleted_at`, so
    // every query on this endpoint failed.
    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/services')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    $created = $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/services', ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 75000])
        ->assertStatus(201)
        ->json('data');

    $this->withHeaders($ctx['headers'])
        ->putJson("/api/v1/services/{$created['id']}", ['price' => 80000])
        ->assertStatus(200)
        ->assertJsonPath('data.price', 80000);

    $this->withHeaders($ctx['headers'])
        ->deleteJson("/api/v1/services/{$created['id']}")
        ->assertStatus(200);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/services')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    expect(Service::withoutGlobalScopes()->count())->toBe(1)
        ->and(Service::count())->toBe(0);
});

test('the customer activity feed is readable and tenant scoped', function () {
    $ctx = regressionCtx($this, 'ACT');

    $customer = Customer::create([
        'business_id' => $ctx['biz']['id'],
        'name' => 'Walk-in',
    ]);

    // `CustomerActivityLog` declared `protected $timestamps`, which made the
    // class impossible to load.
    $this->withHeaders($ctx['headers'])
        ->getJson("/api/v1/customers/{$customer->id}/activity")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    $other = $this->withHeaders($ctx['auth'])
        ->postJson('/api/v1/businesses', ['name' => 'Regression Other'])
        ->json('data');

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders(array_merge($ctx['auth'], ['X-Business-Id' => $other['id']]))
        ->getJson("/api/v1/customers/{$customer->id}/activity")
        ->assertStatus(404);
});

test('recipe ingredient relations resolve from both sides', function () {
    $ctx = regressionCtx($this, 'RC');

    $product = Product::create([
        'business_id' => $ctx['biz']['id'],
        'name' => 'Cappuccino',
        'sku' => 'RG-CAP',
        'selling_price' => 24000,
        'cost_price' => 9000,
        'track_inventory' => false,
        'is_active' => true,
    ]);

    $ingredient = Ingredient::create([
        'business_id' => $ctx['biz']['id'],
        'name' => 'Milk',
        'sku' => 'RG-MILK',
        'unit' => 'litre',
        'cost_per_unit' => 5200,
    ]);

    $recipe = Recipe::create([
        'business_id' => $ctx['biz']['id'],
        'product_id' => $product->id,
        'name' => 'Cappuccino recipe',
        'yield_quantity' => 1,
    ]);

    // The `recipe_ingredients` table was modelled twice, and the PSR-4-named
    // class did not exist, so both relations threw "class not found".
    RecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'ingredient_id' => $ingredient->id,
        'quantity_per_unit' => 0.2,
        'unit' => 'litre',
    ]);

    expect($recipe->ingredients()->count())->toBe(1)
        ->and($ingredient->recipes()->first()->recipe_id)->toBe($recipe->id)
        ->and($ingredient->recipes()->first()->recipe->name)->toBe('Cappuccino recipe');
});

test('product search resolves instead of hitting the show route', function () {
    $ctx = regressionCtx($this, 'PS');

    // `apiResource('products')` used to be registered first, so `products/search`
    // was matched by `products/{product}` and 404'd on a lookup for "search".
    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/products/search?search=milk')
        ->assertStatus(200);
});

test('csv exports stream and count against the monthly quota', function () {
    $ctx = regressionCtx($this, 'EX');

    $first = $this->withHeaders($ctx['headers'])->get('/api/v1/exports/products');
    $first->assertStatus(200);
    expect($first->headers->get('content-type'))->toContain('text/csv')
        ->and($first->streamedContent())->toContain('Name');

    // Metering used to insert a second usage row for the same period and blow
    // up on the unique index, so every export 500'd.
    $this->withHeaders($ctx['headers'])->get('/api/v1/exports/products')->assertStatus(200);

    $usage = SubscriptionUsageRecord::where('business_id', $ctx['biz']['id'])
        ->where('metric', 'exports_per_month')
        ->first();

    expect($usage)->not->toBeNull()
        ->and((int) $usage->used_count)->toBe(2);
});

test('a storefront account lists its own carts and orders', function () {
    $ctx = regressionCtx($this, 'SF');

    $storefront = Storefront::create([
        'business_id' => $ctx['biz']['id'],
        'outlet_id' => $ctx['outlet']['id'],
        'name' => 'Corner shop',
        'slug' => 'corner-shop-sf',
    ]);

    $account = StorefrontCustomerAccount::create([
        'business_id' => $ctx['biz']['id'],
        'storefront_id' => $storefront->id,
        'name' => 'Ayu',
        'email' => 'ayu@example.test',
        'password_hash' => 'x',
    ]);

    Cart::create([
        'business_id' => $ctx['biz']['id'],
        'storefront_id' => $storefront->id,
        'customer_account_id' => $account->id,
        'token' => 'cart-token-sf',
    ]);

    StorefrontOrder::create([
        'business_id' => $ctx['biz']['id'],
        'storefront_id' => $storefront->id,
        'outlet_id' => $ctx['outlet']['id'],
        'customer_account_id' => $account->id,
        'number' => 'SO-SF-1',
        'customer_name' => 'Ayu',
    ]);

    // Both relations guessed `storefront_customer_account_id`; the column is
    // `customer_account_id`.
    expect($account->carts()->count())->toBe(1)
        ->and($account->orders()->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
});

it('can seed a minimal coffee shop dataset', function () {
    $business = Business::create([
        'name' => 'Velora Coffee Test',
        'slug' => 'velora-coffee-test',
        'currency' => 'USD',
        'timezone' => 'America/New_York',
        'country_code' => 'US',
        'language_code' => 'en',
    ]);

    $outlet = Outlet::create([
        'business_id' => $business->id,
        'name' => 'Main store',
        'address' => '1 Main St',
        'code' => 'MAIN',
    ]);

    $supplier = \App\Models\Supplier::create([
        'business_id' => $business->id,
        'name' => 'Dairy Direct',
        'contact' => 'Dairy Team',
        'email' => 'dairy@example.com',
        'phone' => '+15551234',
        'address' => '10 Dairy Lane',
        'lead_time_days' => 2,
    ]);

    $product = Product::create([
        'business_id' => $business->id,
        'name' => 'Cappuccino',
        'sku' => 'CP-001',
        'selling_price' => 22000,
        'cost_price' => 8500,
        'track_inventory' => false,
        'is_active' => true,
    ]);

    $ingredient = Ingredient::create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'name' => 'Milk',
        'sku' => 'ING-MILK',
        'unit' => 'litre',
        'cost_per_unit' => 5200,
        'track_inventory' => true,
        'reorder_level' => 20,
        'opening_quantity' => 120,
        'lead_time_days' => 3,
    ]);

    $recipe = Recipe::create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'name' => 'Cappuccino recipe',
        'yield_quantity' => 1,
    ]);
    RecipeIngredient::create([
        'recipe_id' => $recipe->id,
        'ingredient_id' => $ingredient->id,
        'quantity_per_unit' => 0.2,
        'unit' => 'litre',
    ]);

    $sale = Sale::create([
        'business_id' => $business->id,
        'outlet_id' => $outlet->id,
        'status' => 'completed',
        'total' => '22000.0000',
        'receipt_number' => 'R-0001',
        'completed_at' => Carbon::now(),
    ]);
    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 10,
        'unit_price' => 22000,
        'total_price' => 220000,
        'line_total' => 220000,
    ]);

    expect($recipe->product_id)->toBe($product->id);
});

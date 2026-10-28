<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 128)->nullable();
            $table->string('unit', 32)->default('g');
            $table->decimal('cost_per_unit', 18, 4)->default(0);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'name']);
            $table->unique(['business_id', 'sku']);
        });

        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('waste_percent', 8, 4)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'ingredient_id'], 'recipe_items_business_ingredient_index');
            $table->index(['product_id', 'product_variant_id'], 'recipe_items_product_scope_index');
        });

        Schema::create('ingredient_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'ingredient_id']);
            $table->index(['business_id', 'outlet_id'], 'ingredient_balances_business_outlet_index');
        });

        Schema::create('ingredient_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // purchase, adjustment, consumption, wastage, void
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4)->default(0);
            $table->string('reference_type', 32)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'outlet_id', 'ingredient_id'], 'ingredient_movements_scope_index');
            $table->index(['reference_type', 'reference_id'], 'ingredient_movements_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_movements');
        Schema::dropIfExists('ingredient_balances');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('ingredients');
    }
};

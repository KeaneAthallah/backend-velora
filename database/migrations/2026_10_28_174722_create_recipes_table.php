<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('yield_quantity', 18, 4)->default(1);
            $table->timestamps();
            $table->index(['business_id', 'product_id']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // No foreign key here: the `ingredients` table is introduced by the
            // later ingredient/recipe migration, and MySQL cannot create a
            // constraint against a table that does not exist yet. The column
            // and its index keep the legacy relation usable until then.
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_per_unit', 18, 4);
            $table->string('unit', 32)->default('g');
            $table->timestamps();
            $table->index(['recipe_id', 'ingredient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};

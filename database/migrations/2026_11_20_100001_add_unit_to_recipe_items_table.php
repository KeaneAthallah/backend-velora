<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recipe line may be written in a different unit than the ingredient is
     * stocked in (18 g of beans against a kilo-stocked ingredient). Null keeps
     * the legacy behaviour: the quantity is already in the ingredient's unit.
     */
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->string('unit', 32)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};

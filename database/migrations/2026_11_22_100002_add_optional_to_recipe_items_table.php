<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            // Lines a customer can leave out. Only these are skipped when a
            // selected modifier removes the ingredient (see
            // modifier_options.removes_ingredient_id).
            $table->boolean('optional')->default(false)->after('waste_percent');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_items', function (Blueprint $table) {
            $table->dropColumn('optional');
        });
    }
};

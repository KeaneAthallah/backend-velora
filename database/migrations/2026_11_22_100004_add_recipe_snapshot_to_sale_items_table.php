<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // The recipe actually deducted for this line, per unit, captured at
            // sale time. Recipe edits (and modifier removals) after the sale no
            // longer change what a refund puts back or what the line cost.
            $table->json('recipe_snapshot')->nullable()->after('option_ids');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('recipe_snapshot');
        });
    }
};

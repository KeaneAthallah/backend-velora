<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredient-based purchase-order lines.
 *
 * The purchasing flow drafts product lines (`product_id` + `unit_cost`); the
 * reorder copilot drafts ingredient lines, so it needs its own columns. All are
 * nullable and unused by purchasing, so existing behaviour is unchanged while
 * `/reorder/suggest` stays gated behind `reorder_purchase_orders`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->after('ingredient_id')
                ->constrained()->nullOnDelete();
            $table->string('unit', 32)->nullable()->after('quantity');
            $table->decimal('cost_per_unit', 18, 4)->nullable()->after('unit_cost');
            $table->decimal('estimated_total', 18, 4)->nullable()->after('line_total');
            $table->decimal('parcel_quantity', 18, 4)->nullable();
            $table->decimal('parcel_cost', 18, 4)->nullable();
            $table->string('status', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn([
                'unit', 'cost_per_unit', 'estimated_total', 'parcel_quantity', 'parcel_cost', 'status',
            ]);
        });
    }
};

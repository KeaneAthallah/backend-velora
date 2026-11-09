<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredient-based purchasing lines.
 *
 * An ingredient does not have to belong to a product, but
 * `purchase_order_items.product_id` (and the goods-receipt line that records
 * what arrived) were NOT NULL, so the reorder copilot could only draft lines for
 * ingredients that happened to be linked to one. The line's real subject is
 * `ingredient_id`; `product_id` stays as the catalogue link when it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};

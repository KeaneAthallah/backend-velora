<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder copilot tables: per-ingredient usage patterns, the supplier parcels
 * those patterns suggest, and the per-line approval trail on a purchase order.
 *
 * `purchase_order_item_approvals` is additive — the existing purchasing flow
 * never writes it, only `/reorder/items/{item}/approve|reject` does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_photo_and_use_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Units of the ingredient used per day when this pattern applies.
            $table->decimal('consumption_rate', 18, 4)->default(0);
            // 0–1: how much of demand this pattern is estimated to account for.
            $table->decimal('photo_share_estimate', 8, 4)->default(1);
            $table->timestamps();
            $table->index(['business_id', 'ingredient_id'], 'ingredient_patterns_business_ingredient_index');
        });

        // MySQL caps identifiers at 64 characters, which
        // `purchase_order_item_suggested_supplier_parcels_business_id_foreign`
        // exceeds, so every constraint here is named explicitly.
        Schema::create('purchase_order_item_suggested_supplier_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained(indexName: 'poi_parcels_business_fk')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained(indexName: 'poi_parcels_product_fk')->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained(indexName: 'poi_parcels_ingredient_fk')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained(indexName: 'poi_parcels_supplier_fk')->cascadeOnDelete();
            $table->decimal('parcel_quantity', 18, 4);
            $table->decimal('parcel_cost', 18, 4);
            $table->timestamps();
            $table->index(['business_id', 'product_id'], 'suggested_parcels_business_product_index');
            $table->index(['business_id', 'ingredient_id'], 'suggested_parcels_business_ingredient_index');
        });

        Schema::create('purchase_order_item_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('cost_per_unit', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index('purchase_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_approvals');
        Schema::dropIfExists('purchase_order_item_suggested_supplier_parcels');
        Schema::dropIfExists('ingredient_photo_and_use_patterns');
    }
};

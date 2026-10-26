<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug', 128);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'slug']);
            $table->index(['business_id', 'parent_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 128);
            $table->text('description')->nullable();
            $table->string('unit', 32)->default('pcs');
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'category_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 128);
            $table->string('barcode', 128)->nullable();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->json('attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'sku']);
            $table->index('barcode');
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('barcode', 128)->unique();
            $table->timestamps();
        });

        Schema::create('outlet_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->decimal('selling_price', 18, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['outlet_id', 'product_id', 'product_variant_id'], 'opp_unique');
            $table->index(['outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_product_prices');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};

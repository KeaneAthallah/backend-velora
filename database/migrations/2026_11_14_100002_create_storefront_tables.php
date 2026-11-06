<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefronts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('domain', 160)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->text('description')->nullable();
            $table->json('theme')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'is_active']);
        });

        Schema::create('storefront_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->decimal('price_override', 18, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['storefront_id', 'product_id']);
        });

        Schema::create('storefront_customer_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email', 160);
            $table->string('phone', 32)->nullable();
            $table->string('password_hash');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['storefront_id', 'email']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('storefront_customer_accounts')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 16)->default('active'); // active, converted, abandoned
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['storefront_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('storefront_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('storefront_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_account_id')->nullable()->constrained('storefront_customer_accounts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32)->unique();
            $table->string('status', 24)->default('pending');
            $table->string('fulfilment_type', 16)->default('delivery'); // delivery, pickup
            $table->string('payment_status', 16)->default('unpaid');
            $table->string('payment_method', 32)->nullable();
            $table->string('customer_name');
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->json('delivery_address')->nullable();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('delivery_fee', 18, 4)->default(0);
            $table->decimal('discount', 18, 4)->default(0);
            $table->decimal('tax', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('tracking_token', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['storefront_id', 'placed_at']);
        });

        Schema::create('storefront_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_order_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->text('message')->nullable();
            $table->string('actor_type', 16)->default('system'); // customer, staff, system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();
            $table->index('storefront_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_order_events');
        Schema::dropIfExists('storefront_orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('storefront_customer_accounts');
        Schema::dropIfExists('storefront_items');
        Schema::dropIfExists('storefronts');
    }
};

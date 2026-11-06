<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->decimal('fee', 18, 4)->default(0);
            $table->decimal('min_order', 18, 4)->default(0);
            $table->decimal('max_distance_km', 8, 2)->nullable();
            $table->json('postcodes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'outlet_id']);
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('license_number', 64)->nullable();
            $table->string('status', 16)->default('available'); // available, on_delivery, off_duty
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number', 32);
            $table->string('type', 32)->default('motorcycle'); // motorcycle, car, van, truck
            $table->string('model', 120)->nullable();
            $table->decimal('capacity_kg', 10, 2)->nullable();
            $table->string('status', 16)->default('available'); // available, in_use, maintenance
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'plate_number']);
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storefront_order_id')->nullable()->constrained('storefront_orders')->nullOnDelete();
            $table->foreignId('delivery_zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('number', 32)->unique();
            $table->string('status', 24)->default('pending'); // pending, scheduled, assigned, picked_up, in_transit, delivered, failed, cancelled
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 32)->nullable();
            $table->json('address')->nullable();
            $table->decimal('fee', 18, 4)->default(0);
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('proof')->nullable();
            $table->decimal('collected_amount', 18, 4)->default(0);
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'scheduled_at']);
            $table->index(['driver_id', 'status']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 4);
            $table->decimal('delivered_quantity', 18, 4)->default(0);
            $table->timestamps();
            $table->index('delivery_id');
        });

        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->text('note')->nullable();
            $table->string('actor_type', 16)->default('staff');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
        Schema::dropIfExists('delivery_lines');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('delivery_zones');
    }
};

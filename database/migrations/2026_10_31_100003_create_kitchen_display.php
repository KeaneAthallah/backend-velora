<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 12);
            $table->string('kind', 20)->default('kitchen');
            $table->string('printer_name', 80)->nullable();
            $table->string('printer_target', 120)->nullable();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('auto_print')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'name'], 'kitchen_stations_outlet_name_unique');
            $table->unique(['outlet_id', 'code'], 'kitchen_stations_outlet_code_unique');
            $table->index(['outlet_id', 'is_active'], 'kitchen_stations_outlet_active_index');
        });

        Schema::create('kitchen_station_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_station_id')->constrained('kitchen_stations')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['kitchen_station_id', 'category_id'], 'kitchen_station_categories_unique');
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->constrained('kitchen_stations')->cascadeOnDelete();
            $table->string('ticket_number', 32);
            $table->string('status', 20)->default('queued');
            $table->json('items');
            $table->string('note', 255)->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'ticket_number'], 'kitchen_tickets_number_unique');
            $table->unique(['sale_id', 'kitchen_station_id'], 'kitchen_tickets_sale_station_unique');
            $table->index(['outlet_id', 'status'], 'kitchen_tickets_outlet_status_index');
        });

        Schema::create('kitchen_ticket_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'business_date'], 'kitchen_ticket_counters_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_counters');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('kitchen_station_categories');
        Schema::dropIfExists('kitchen_stations');
    }
};

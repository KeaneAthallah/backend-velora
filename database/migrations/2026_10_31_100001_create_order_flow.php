<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('order_type', 20)->default('takeaway')->after('shift_id');
            $table->string('order_status', 20)->default('completed')->after('order_type');
            $table->string('order_number', 32)->nullable()->after('receipt_number');
            $table->json('fulfilment')->nullable()->after('notes');
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->unique(['business_id', 'order_number']);
            $table->index(['business_id', 'outlet_id', 'order_status'], 'sales_order_status_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->json('option_ids')->nullable()->after('modifiers');
        });

        Schema::create('order_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('order_type', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'business_date', 'order_type'], 'order_counters_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_counters');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('option_ids');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'order_number']);
            $table->dropIndex('sales_order_status_index');
            $table->dropColumn(['order_type', 'order_status', 'order_number', 'fulfilment', 'fired_at', 'ready_at', 'served_at']);
        });
    }
};

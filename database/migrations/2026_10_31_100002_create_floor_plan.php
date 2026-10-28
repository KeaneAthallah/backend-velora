<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['outlet_id', 'name'], 'floor_areas_outlet_name_unique');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('floor_area_id')->nullable()->constrained('floor_areas')->nullOnDelete();
            $table->string('name', 32);
            $table->unsignedSmallInteger('seats')->default(2);
            $table->string('shape', 20)->default('square');
            $table->unsignedSmallInteger('position_x')->default(0);
            $table->unsignedSmallInteger('position_y')->default(0);
            $table->string('status', 20)->default('available');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['outlet_id', 'name'], 'dining_tables_outlet_name_unique');
            $table->index(['outlet_id', 'status'], 'dining_tables_outlet_status_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('table_id')->nullable()->after('customer_id')->constrained('dining_tables')->nullOnDelete();
            $table->index(['business_id', 'outlet_id', 'table_id'], 'sales_table_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_table_index');
            $table->dropConstrainedForeignId('table_id');
        });

        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('floor_areas');
    }
};

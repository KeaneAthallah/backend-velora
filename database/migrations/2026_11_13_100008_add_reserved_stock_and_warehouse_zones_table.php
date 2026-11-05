<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_zone_id')->constrained('warehouse_zones')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_zone_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_zones');
    }
};

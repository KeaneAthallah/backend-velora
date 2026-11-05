<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->decimal('base_factor', 18, 4)->default(1);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->decimal('factor', 18, 4)->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
        Schema::dropIfExists('units_of_measure');
    }
};

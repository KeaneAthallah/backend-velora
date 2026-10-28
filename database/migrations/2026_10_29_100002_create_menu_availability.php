<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dayparts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->json('days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('product_dayparts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daypart_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'daypart_id']);
        });

        Schema::create('menu_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'outlet_id', 'product_id'], 'menu_availability_product_index');
            $table->index(['business_id', 'outlet_id', 'modifier_option_id'], 'menu_availability_option_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_availability');
        Schema::dropIfExists('product_dayparts');
        Schema::dropIfExists('dayparts');
    }
};

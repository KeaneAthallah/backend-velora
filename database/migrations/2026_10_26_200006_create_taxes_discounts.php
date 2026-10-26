<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rate', 8, 4); // percentage
            $table->enum('type', ['inclusive', 'exclusive'])->default('exclusive');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('business_id');
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 18, 4);
            $table->enum('scope', ['product', 'transaction'])->default('transaction');
            $table->decimal('min_purchase', 18, 4)->nullable();
            $table->decimal('max_discount', 18, 4)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();
            $table->index('business_id');
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed', 'buy_x_get_y'])->default('percentage');
            $table->decimal('value', 18, 4)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('tax_rates');
    }
};

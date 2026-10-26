<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->unique(['outlet_id', 'code']);
        });

        Schema::create('register_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['open', 'closed', 'cancelled'])->default('open');
            $table->decimal('opening_cash', 18, 4)->default(0);
            $table->decimal('expected_cash', 18, 4)->default(0);
            $table->decimal('actual_cash', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'outlet_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_shift_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['in', 'out']);
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // add FK from sales to shifts
        Schema::table('sales', function (Blueprint $table) {
            $table->foreign('shift_id')->references('id')->on('register_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('register_shifts');
        Schema::dropIfExists('cash_registers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->decimal('rate', 8, 4)->default(0);
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->index(['business_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_commissions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24)->default('fixed'); // fixed, hourly
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('hourly_rate')->default(0);
            $table->json('allowances')->nullable();
            $table->json('deductions')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'user_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('period', 16); // YYYY-MM
            $table->string('status', 24)->default('draft'); // draft, approved, paid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('base_amount')->default(0);
            $table->unsignedBigInteger('allowances_total')->default(0);
            $table->unsignedBigInteger('deductions_total')->default(0);
            $table->unsignedBigInteger('overtime_amount')->default(0);
            $table->unsignedBigInteger('bonus_amount')->default(0);
            $table->unsignedBigInteger('commission_amount')->default(0);
            $table->unsignedBigInteger('net_amount')->default(0);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_structures');
    }
};

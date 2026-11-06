<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32); // comparison, product_analytics, operational, financial
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->string('format', 16)->default('csv'); // csv, excel, pdf
            $table->string('schedule', 16)->default('none'); // none, daily, weekly, monthly
            $table->string('schedule_time', 5)->nullable(); // HH:MM
            $table->json('recipients')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'type']);
            $table->index(['business_id', 'next_run_at']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_id')->nullable()->constrained('saved_reports')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('format', 16)->default('csv');
            $table->string('status', 16)->default('completed'); // queued, running, completed, failed
            $table->unsignedInteger('row_count')->default(0);
            $table->json('filters')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status', 16)->nullable(); // pending, delivered, failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'created_at']);
            $table->index('saved_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('saved_reports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->timestamp('break_start_at')->nullable();
            $table->timestamp('break_end_at')->nullable();
            $table->string('status', 24)->default('present'); // present, late, early_leave, absent
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'user_id', 'check_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 24); // asset, liability, equity, revenue, expense, cogs
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('opening_balance')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type']);
            $table->index(['business_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

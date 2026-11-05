<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('type', 24); // income, expense, transfer
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('category', 64)->nullable();
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->string('reference', 128)->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->string('status', 24)->default('posted'); // draft, posted, voided
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'transaction_date']);
            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};

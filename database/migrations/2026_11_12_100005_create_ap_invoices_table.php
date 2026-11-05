<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('number', 64);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('status', 24)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'due_date']);
        });

        Schema::create('ap_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ap_invoice_id')->constrained('ap_invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('payment_date');
            $table->string('method', 32)->nullable();
            $table->string('reference', 128)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_invoice_payments');
        Schema::dropIfExists('ap_invoices');
    }
};

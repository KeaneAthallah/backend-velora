<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable(); // FK added later
            $table->string('receipt_number', 64);
            $table->enum('status', ['draft', 'completed', 'voided', 'refunded', 'partially_refunded'])->default('draft');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('change_amount', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'receipt_number']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'outlet_id', 'status']);
            $table->index(['cashier_id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('sku', 128)->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->decimal('quantity', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('sale_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 64); // cash, bank_transfer, qr, card, other
            $table->decimal('amount', 18, 4);
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded', 'partially_refunded'])->default('pending');
            $table->string('reference', 128)->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->json('provider_response')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['sale_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};

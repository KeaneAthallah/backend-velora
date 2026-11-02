<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->char('currency', 3)->default('IDR');
            $table->unsignedBigInteger('monthly_price')->default(0);
            $table->unsignedBigInteger('yearly_price')->default(0);
            $table->json('limits')->nullable();
            $table->json('features')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->boolean('enabled')->default(false);
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'feature_key'], 'spf_plan_feature_unique');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('pending_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->string('status', 24)->default('trialing');
            $table->string('billing_period', 16)->default('monthly');
            $table->string('pending_billing_period', 16)->nullable();
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_subscription_id', 128)->nullable();
            $table->unsignedBigInteger('price_amount')->default(0);
            $table->char('currency', 3)->default('IDR');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'ends_at']);
            $table->index(['plan_id', 'status']);
        });

        Schema::create('subscription_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('used_count')->default(0);
            $table->timestamps();
            $table->unique(['business_id', 'metric', 'period_start'], 'sub_usage_metric_unique');
            $table->index(['business_id', 'period_start']);
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->string('status', 24)->default('open');
            $table->string('billing_period', 16);
            $table->char('currency', 3)->default('IDR');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->json('line_items')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('subscription_invoices')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_reference', 128)->nullable()->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('amount')->default(0);
            $table->char('currency', 3)->default('IDR');
            $table->string('method', 32)->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('event_type', 64)->nullable();
            $table->string('status', 24)->default('received');
            $table->string('signature')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id'], 'pwe_provider_event_unique');
            $table->index(['status', 'created_at']);
        });

        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->foreignId('from_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_histories');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('subscription_usage_records');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plan_features');
        Schema::dropIfExists('subscription_plans');
    }
};

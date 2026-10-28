<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One loyalty programme per business. Kept separate from the business
        // row so the earning/redeeming rules can evolve without widening the
        // tenant table.
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            // Points earned per 1.0000 of currency spent (0.0001 = 1 point per 10,000).
            $table->decimal('points_per_amount', 18, 4)->default(0.0001);
            // Currency value of a single point when redeeming.
            $table->decimal('redeem_value', 18, 4)->default(100);
            $table->decimal('min_redeem_points', 18, 4)->default(10);
            $table->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['earn', 'redeem', 'adjust', 'reverse']);
            $table->decimal('points', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'customer_id']);
            $table->index('sale_id');
        });

        // Promotions already existed as a dormant table; codes, limits and
        // capping are what make them usable at the register.
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('code', 64)->nullable()->after('name');
            $table->decimal('min_spend', 18, 4)->nullable()->after('value');
            $table->decimal('max_discount', 18, 4)->nullable()->after('min_spend');
            $table->unsignedInteger('usage_limit')->nullable()->after('max_discount');
            $table->unsignedInteger('usage_count')->default(0)->after('usage_limit');
            $table->unsignedInteger('per_customer_limit')->nullable()->after('usage_count');
            $table->unique(['business_id', 'code']);
        });

        Schema::create('promotion_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64)->nullable();
            $table->decimal('amount', 18, 4);
            $table->timestamps();
            $table->index(['business_id', 'promotion_id']);
            $table->index('customer_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->string('promotion_code', 64)->nullable()->after('promotion_id');
            $table->decimal('promotion_discount', 18, 4)->default(0)->after('discount_amount');
            $table->decimal('loyalty_discount', 18, 4)->default(0)->after('promotion_discount');
            $table->decimal('loyalty_points_redeemed', 18, 4)->default(0)->after('loyalty_discount');
            $table->decimal('loyalty_points_earned', 18, 4)->default(0)->after('loyalty_points_redeemed');
        });

        // Order-level discounts are distributed across lines, but re-pricing an
        // open order needs the discount that was typed on the line itself.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('line_discount_amount', 18, 4)->default(0)->after('discount_amount');
        });

        DB::statement('UPDATE sale_items SET line_discount_amount = discount_amount');

        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('total_spent', 18, 4)->default(0)->after('loyalty_points');
            $table->unsignedInteger('visits_count')->default(0)->after('total_spent');
            $table->timestamp('last_visit_at')->nullable()->after('visits_count');
            $table->timestamp('loyalty_joined_at')->nullable()->after('last_visit_at');
            $table->index(['business_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'phone']);
            $table->dropColumn(['total_spent', 'visits_count', 'last_visit_at', 'loyalty_joined_at']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('line_discount_amount');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn([
                'promotion_code',
                'promotion_discount',
                'loyalty_discount',
                'loyalty_points_redeemed',
                'loyalty_points_earned',
            ]);
        });

        Schema::dropIfExists('promotion_redemptions');

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->dropColumn(['code', 'min_spend', 'max_discount', 'usage_limit', 'usage_count', 'per_customer_limit']);
        });

        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_programs');
    }
};

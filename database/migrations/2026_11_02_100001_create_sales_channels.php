<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // Null sells through whichever outlet the inbound order names.
            $table->foreignId('outlet_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('code', 32);
            $table->string('kind', 20)->default('own_web'); // own_web, marketplace, whatsapp, phone, custom
            $table->decimal('commission_percent', 8, 4)->default(0);
            // Payment rail that settles channel orders at checkout.
            $table->string('payment_method_code', 64)->nullable();
            $table->boolean('auto_accept')->default(false);
            $table->unsignedSmallInteger('prep_buffer_minutes')->default(0);
            $table->text('secret')->nullable(); // encrypted at rest
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'is_active'], 'sales_channels_business_active_index');
            $table->index(['outlet_id', 'is_active'], 'sales_channels_outlet_active_index');
        });

        Schema::create('channel_menu_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $table->string('external_item_id', 128);
            // Empty string instead of null so the unique key covers plain items too.
            $table->string('external_variant_id', 128)->default('');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            // external option id => modifier_options.id
            $table->json('option_map')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(
                ['sales_channel_id', 'external_item_id', 'external_variant_id'],
                'channel_menu_mappings_scope_unique'
            );
            $table->index(['business_id', 'product_id'], 'channel_menu_mappings_business_product_index');
        });

        Schema::create('channel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['sales_channel_id', 'status'], 'channel_events_channel_status_index');
            $table->index(['sale_id', 'type'], 'channel_events_sale_type_index');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('sales_channel_id')->nullable()->after('shift_id')
                ->constrained('sales_channels')->nullOnDelete();
            $table->string('channel_order_ref', 128)->nullable()->after('sales_channel_id');
            $table->string('channel_status', 20)->nullable()->after('channel_order_ref');
            $table->decimal('channel_commission_percent', 8, 4)->default(0)->after('channel_status');
            $table->decimal('channel_commission', 18, 4)->default(0)->after('channel_commission_percent');
            $table->unique(
                ['business_id', 'sales_channel_id', 'channel_order_ref'],
                'sales_channel_order_ref_unique'
            );
            $table->index(['sales_channel_id', 'channel_status'], 'sales_channel_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_channel_order_ref_unique');
            $table->dropIndex('sales_channel_status_index');
            $table->dropConstrainedForeignId('sales_channel_id');
            $table->dropColumn(['channel_order_ref', 'channel_status', 'channel_commission_percent', 'channel_commission']);
        });

        Schema::dropIfExists('channel_events');
        Schema::dropIfExists('channel_menu_mappings');
        Schema::dropIfExists('sales_channels');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('serial_number', 128)->nullable();
            $table->string('batch_number', 128)->nullable();
            $table->date('expiration_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->boolean('is_bundle')->default(false);
            $table->json('bundle_items')->nullable();
            $table->unsignedInteger('reorder_level')->default(0)->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['serial_number', 'batch_number', 'expiration_date', 'warranty_until', 'is_bundle', 'bundle_items', 'reorder_level']);
        });
    }
};

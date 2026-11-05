<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_limit')->default(0)->after('metadata');
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('credit_limit');
            $table->unsignedBigInteger('balance')->default(0)->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'payment_terms_days', 'balance']);
        });
    }
};

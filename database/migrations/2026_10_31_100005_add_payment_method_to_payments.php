<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plain column rather than a FK constraint: SQLite cannot add foreign
        // keys to an existing table, and the code string remains the source of
        // truth for historical reports.
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_method_id')->nullable()->after('sale_id');
            $table->index('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });
    }
};

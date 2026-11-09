<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // Hard floor below `reorder_level`: stock at or under this is
            // reported as critical even before the reorder alert fires.
            $table->decimal('safety_stock', 18, 4)->default(0)->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('safety_stock');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_options', function (Blueprint $table) {
            // "No milk" points at the milk ingredient, so a selected option can
            // drop the matching optional recipe line at consumption time.
            $table->foreignId('removes_ingredient_id')->nullable()->after('product_variant_id')
                ->constrained('ingredients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('modifier_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removes_ingredient_id');
        });
    }
};

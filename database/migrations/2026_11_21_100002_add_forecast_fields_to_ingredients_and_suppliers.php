<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields the reorder forecast reads.
 *
 * `shelf_life_days` drives the waste-risk model, `lead_time_days` the lead-time
 * demand, and `preferred_supplier_id` the fallback supplier when no suggested
 * parcel exists. All nullable: an untracked ingredient simply has no shelf life
 * (`Ingredient::SHELF_LIFE_UNTRACKED`) and falls back to the 7-day default lead
 * time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('reorder_level');
            $table->decimal('shelf_life_days', 8, 2)->nullable()->after('lead_time_days');
            $table->foreignId('preferred_supplier_id')->nullable()->after('shelf_life_days')
                ->constrained('suppliers')->nullOnDelete();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
            $table->dropColumn(['lead_time_days', 'shelf_life_days']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('lead_time_days');
        });
    }
};

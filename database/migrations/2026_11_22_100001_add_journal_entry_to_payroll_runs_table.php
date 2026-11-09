<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // Set once the run is posted to the ledger; the entry itself stays
            // in the journal module so it can be reversed like any other.
            $table->foreignId('journal_entry_id')->nullable()->after('approved_at')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};

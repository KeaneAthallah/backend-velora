<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    use HasFactory;

    protected $table = 'payroll_runs';

    protected $fillable = ['business_id', 'period', 'status', 'approved_by', 'approved_at', 'journal_entry_id'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    /** The posted ledger entry for this run, if it has been posted. */
    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

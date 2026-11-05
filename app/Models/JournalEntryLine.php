<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntryLine extends Model
{
    use HasFactory;

    protected $table = 'journal_entry_lines';

    protected $fillable = ['journal_entry_id', 'account_id', 'debit', 'credit', 'description'];

    protected function casts(): array
    {
        return ['debit' => 'integer', 'credit' => 'integer'];
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}

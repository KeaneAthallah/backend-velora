<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'number', 'entry_date', 'reference_type', 'reference_id', 'description', 'status', 'posted_by', 'posted_at', 'reversed_by_entry_id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function reversedBy()
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_by_entry_id');
    }
}

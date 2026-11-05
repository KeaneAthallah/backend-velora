<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'code', 'name', 'type', 'parent_id', 'opening_balance', 'is_active', 'description'];

    protected function casts(): array
    {
        return ['opening_balance' => 'integer', 'is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function parent()
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }
}

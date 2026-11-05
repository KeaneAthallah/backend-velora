<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveCategory extends Model
{
    use HasFactory;

    protected $table = 'leave_categories';

    protected $fillable = ['business_id', 'name', 'default_days', 'is_paid'];

    protected function casts(): array
    {
        return ['default_days' => 'integer', 'is_paid' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

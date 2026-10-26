<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'description', 'type', 'value', 'starts_at', 'ends_at', 'is_active', 'conditions'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'conditions' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

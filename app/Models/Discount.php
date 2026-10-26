<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'type', 'value', 'scope', 'min_purchase', 'max_discount', 'starts_at', 'ends_at', 'is_active', 'requires_approval'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'min_purchase' => 'decimal:4', 'max_discount' => 'decimal:4', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'requires_approval' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

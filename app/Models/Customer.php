<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'phone', 'email', 'address', 'loyalty_number', 'loyalty_points', 'metadata'];

    protected function casts(): array
    {
        return ['loyalty_points' => 'decimal:4', 'metadata' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

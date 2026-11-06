<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'plate_number', 'type', 'model', 'capacity_kg', 'status', 'is_active',
    ];

    protected function casts(): array
    {
        return ['capacity_kg' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }
}

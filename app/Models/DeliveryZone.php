<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryZone extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'outlet_id', 'name', 'code', 'fee', 'min_order',
        'max_distance_km', 'postcodes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:4',
            'min_order' => 'decimal:4',
            'max_distance_km' => 'decimal:2',
            'postcodes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }
}

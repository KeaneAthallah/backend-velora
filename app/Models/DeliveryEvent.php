<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id', 'status', 'note', 'actor_type', 'actor_id', 'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }
}

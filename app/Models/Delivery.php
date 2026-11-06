<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending', 'scheduled', 'assigned', 'picked_up',
        'in_transit', 'delivered', 'failed', 'cancelled',
    ];

    public const ACTIVE_STATUSES = ['pending', 'scheduled', 'assigned', 'picked_up', 'in_transit'];

    protected $fillable = [
        'business_id', 'outlet_id', 'sale_id', 'storefront_order_id', 'delivery_zone_id',
        'driver_id', 'vehicle_id', 'number', 'status', 'recipient_name', 'recipient_phone',
        'address', 'fee', 'distance_km', 'sequence', 'scheduled_at', 'dispatched_at',
        'delivered_at', 'proof', 'collected_amount', 'reconciled_at', 'reconciled_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'proof' => 'array',
            'fee' => 'decimal:4',
            'collected_amount' => 'decimal:4',
            'distance_km' => 'decimal:2',
            'sequence' => 'integer',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function storefrontOrder()
    {
        return $this->belongsTo(StorefrontOrder::class);
    }

    public function zone()
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function lines()
    {
        return $this->hasMany(DeliveryLine::class);
    }

    public function events()
    {
        return $this->hasMany(DeliveryEvent::class);
    }
}

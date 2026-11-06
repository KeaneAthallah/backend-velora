<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorefrontOrder extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending', 'confirmed', 'preparing', 'ready',
        'out_for_delivery', 'delivered', 'completed', 'cancelled', 'failed',
    ];

    protected $fillable = [
        'business_id', 'storefront_id', 'outlet_id', 'sale_id', 'customer_account_id', 'customer_id',
        'number', 'status', 'fulfilment_type', 'payment_status', 'payment_method', 'customer_name',
        'customer_phone', 'customer_email', 'delivery_address', 'subtotal', 'delivery_fee',
        'discount', 'tax', 'total', 'notes', 'scheduled_at', 'placed_at', 'confirmed_at',
        'completed_at', 'cancelled_at', 'cancellation_reason', 'tracking_token',
    ];

    protected function casts(): array
    {
        return [
            'delivery_address' => 'array',
            'subtotal' => 'decimal:4',
            'delivery_fee' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'total' => 'decimal:4',
            'scheduled_at' => 'datetime',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function storefront()
    {
        return $this->belongsTo(Storefront::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function customerAccount()
    {
        return $this->belongsTo(StorefrontCustomerAccount::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function events()
    {
        return $this->hasMany(StorefrontOrderEvent::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }
}

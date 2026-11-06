<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use HasFactory;

    public const EVENTS = [
        'sale.completed', 'sale.voided', 'order.created', 'order.status_changed',
        'payment.recorded', 'inventory.low_stock', 'subscription.updated',
    ];

    protected $fillable = [
        'business_id', 'name', 'url', 'secret', 'events', 'is_active', 'description',
        'failure_count', 'last_success_at', 'last_failure_at',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'failure_count' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?: [];

        return $events === [] || in_array('*', $events, true) || in_array($event, $events, true);
    }
}

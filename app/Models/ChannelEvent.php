<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Outbound message queue for a sales channel connector.
 *
 * Velora does not call marketplaces itself. Every state change that a channel
 * partner needs to hear about lands here as a pending event with its payload;
 * the connector (or an operator) pulls the queue and acknowledges each event
 * once it has been delivered.
 */
class ChannelEvent extends Model
{
    use HasFactory;

    public const TYPES = [
        'order.accepted', 'order.rejected', 'order.ready', 'order.dispatched',
        'order.completed', 'order.cancelled', 'menu.pushed',
    ];

    public const STATUSES = ['pending', 'sent', 'failed'];

    protected $fillable = [
        'business_id', 'sales_channel_id', 'sale_id', 'type', 'payload',
        'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function channel()
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

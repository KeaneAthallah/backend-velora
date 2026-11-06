<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'delivered', 'failed'];

    protected $fillable = [
        'webhook_endpoint_id', 'business_id', 'event', 'payload', 'response_status',
        'response_body', 'attempts', 'status', 'delivered_at', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_status' => 'integer',
            'attempts' => 'integer',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

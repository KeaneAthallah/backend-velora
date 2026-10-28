<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SalesChannel extends Model
{
    use HasFactory;

    public const KINDS = ['own_web', 'marketplace', 'whatsapp', 'phone', 'custom'];

    public const STATUSES = ['received', 'accepted', 'ready', 'dispatched', 'completed', 'rejected', 'cancelled'];

    protected $fillable = [
        'business_id', 'outlet_id', 'name', 'code', 'kind', 'commission_percent',
        'payment_method_code', 'auto_accept', 'prep_buffer_minutes', 'secret',
        'webhook_token', 'is_active', 'settings', 'last_order_at',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:4',
            'auto_accept' => 'boolean',
            'prep_buffer_minutes' => 'integer',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'settings' => 'array',
            'last_order_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function mappings()
    {
        return $this->hasMany(ChannelMenuMapping::class);
    }

    public function events()
    {
        return $this->hasMany(ChannelEvent::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Give the channel a fresh connector credential pair. The secret is stored
     * encrypted and only ever returned to the caller once, at creation or when
     * it is rotated.
     */
    public function issueCredentials(): string
    {
        $secret = Str::random(48);

        $this->forceFill([
            'webhook_token' => Str::random(40),
            'secret' => $secret,
        ])->save();

        return $secret;
    }

    public function webhookUrl(): ?string
    {
        if (! $this->webhook_token) {
            return null;
        }

        return url('/api/v1/channel-webhooks/'.$this->webhook_token);
    }
}

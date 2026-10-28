<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'code', 'description', 'type', 'value', 'min_spend', 'max_discount',
        'usage_limit', 'usage_count', 'per_customer_limit', 'starts_at', 'ends_at', 'is_active', 'conditions',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4', 'min_spend' => 'decimal:4', 'max_discount' => 'decimal:4',
            'usage_limit' => 'integer', 'usage_count' => 'integer', 'per_customer_limit' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'conditions' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function redemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    /**
     * Promotions without a code are applied automatically when the cart qualifies.
     */
    public function scopeAutomatic($query)
    {
        return $query->whereNull('code');
    }

    public function scopeLive($query, ?\DateTimeInterface $at = null)
    {
        $at = $at ?? now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($at) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at);
            });
    }
}

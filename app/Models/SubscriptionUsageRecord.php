<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionUsageRecord extends Model
{
    use HasFactory;

    protected $table = 'subscription_usage_records';

    protected $fillable = [
        'business_id', 'subscription_id', 'metric', 'period_start',
        'period_end', 'used_count',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'used_count' => 'integer',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED,
    ];

    public const PERIODS = ['monthly', 'yearly'];

    protected $fillable = [
        'business_id', 'plan_id', 'pending_plan_id', 'status', 'billing_period',
        'pending_billing_period', 'trial_starts_at', 'trial_ends_at', 'starts_at',
        'current_period_start', 'current_period_end', 'ends_at', 'grace_ends_at',
        'cancel_at_period_end', 'canceled_at', 'ended_at', 'suspended_at',
        'provider', 'provider_subscription_id', 'price_amount', 'currency', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ended_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'price_amount' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function pendingPlan()
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function invoices()
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function histories()
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    public function isTrialing(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    /** True while the subscription's paid-through date is in the future. */
    public function isWithinPeriod(): bool
    {
        if (! $this->ends_at) {
            return false;
        }

        return $this->ends_at->isFuture();
    }

    /**
     * Entitlement check. A canceled subscription keeps its access until the
     * paid-through date, and a past-due one keeps access through the grace
     * window, as required by the billing lifecycle.
     */
    public function grantsAccess(): bool
    {
        if ($this->status === self::STATUS_SUSPENDED) {
            return false;
        }

        if ($this->status === self::STATUS_EXPIRED) {
            return false;
        }

        if ($this->status === self::STATUS_PAST_DUE) {
            return $this->grace_ends_at !== null && $this->grace_ends_at->isFuture();
        }

        if (! $this->isWithinPeriod()) {
            return false;
        }

        return in_array($this->status, [
            self::STATUS_TRIALING,
            self::STATUS_ACTIVE,
            self::STATUS_CANCELED,
        ], true);
    }

    public function daysRemaining(): int
    {
        if (! $this->ends_at) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($this->ends_at, false) / 86400));
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_PAST_DUE && $this->grace_ends_at && $this->grace_ends_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        if (in_array($this->status, [self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_CANCELED], true)
            && $this->ends_at
            && $this->ends_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function featureEnabled(string $key): bool
    {
        $industry = $this->business?->industry;

        return (bool) $this->plan?->featureEnabledFor($key, $industry);
    }

    public function limit(string $metric): ?int
    {
        return $this->plan?->limit($metric);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'slug', 'name', 'description', 'sort_order', 'is_public', 'is_active',
        'trial_days', 'currency', 'monthly_price', 'yearly_price',
        'limits', 'features', 'metadata', 'supported_industries',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'trial_days' => 'integer',
            'monthly_price' => 'integer',
            'yearly_price' => 'integer',
            'limits' => 'array',
            'features' => 'array',
            'metadata' => 'array',
            'supported_industries' => 'array',
        ];
    }

    public function features()
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function planIndustryFeatures()
    {
        return $this->hasMany(PlanIndustryFeature::class, 'plan_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function featureEnabled(string $key): bool
    {
        $features = $this->features ?? [];

        if (array_key_exists($key, $features)) {
            return (bool) $features[$key];
        }

        return (bool) $this->features()->where('feature_key', $key)->where('enabled', true)->exists();
    }

    public function supportsIndustry(?string $industry): bool
    {
        $supported = $this->supported_industries;

        if (empty($supported)) {
            return true; // null/empty = all industries
        }

        return in_array($industry, $supported, true);
    }

    public function featureEnabledFor(string $key, ?string $industry = null): bool
    {
        if ($industry) {
            $override = $this->planIndustryFeatures()
                ->where('industry', $industry)
                ->where('feature_key', $key)
                ->first();

            if ($override) {
                return (bool) $override->enabled;
            }
        }

        return $this->featureEnabled($key);
    }

    public function limit(string $metric): ?int
    {
        $limits = $this->limits ?? [];

        if (! array_key_exists($metric, $limits)) {
            return null;
        }

        return $limits[$metric] === null ? null : (int) $limits[$metric];
    }

    public function priceFor(string $period): int
    {
        return (int) ($period === 'yearly' ? $this->yearly_price : $this->monthly_price);
    }

    public function isTrialPlan(): bool
    {
        return $this->slug === 'trial';
    }

    /** Public representation used by the pricing page and admin UI. */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_public' => $this->is_public,
            'is_active' => $this->is_active,
            'is_trial' => $this->isTrialPlan(),
            'trial_days' => $this->trial_days,
            'currency' => $this->currency,
            'prices' => [
                'monthly' => (int) $this->monthly_price,
                'yearly' => (int) $this->yearly_price,
            ],
            'limits' => $this->limits ?? [],
            'features' => $this->features ?? [],
        ];
    }
}

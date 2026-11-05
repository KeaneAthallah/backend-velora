<?php

namespace App\Services\Subscription;

use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionUsageRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(protected PlanService $plans) {}

    /* ------------------------------------------------------------------ */
    /* Resolution */
    /* ------------------------------------------------------------------ */

    public function current(Business|int $business): ?Subscription
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        return Subscription::with(['plan', 'pendingPlan'])
            ->where('business_id', $businessId)
            ->first();
    }

    /**
     * Return the business subscription, creating the 30-day trial on first
     * access. This is the only place a trial is born, so registration,
     * onboarding, and a pre-existing business all converge here.
     */
    public function forBusiness(Business|int $business, ?User $actor = null): Subscription
    {
        $model = $business instanceof Business ? $business : Business::findOrFail($business);

        $existing = $this->current($model);
        if ($existing) {
            $this->refreshStatus($existing);

            return $existing;
        }

        return DB::transaction(function () use ($model, $actor) {
            $locked = Subscription::withTrashed()
                ->where('business_id', $model->id)
                ->lockForUpdate()
                ->first();

            if ($locked) {
                if ($locked->trashed()) {
                    $locked->restore();
                }

                return $locked->load(['plan', 'pendingPlan']);
            }

            return $this->createTrial($model, $actor);
        });
    }

    public function createTrial(Business $business, ?User $actor = null): Subscription
    {
        $plan = $this->plans->trialPlan();

        if (! $plan) {
            throw new SubscriptionException(
                'No trial plan is configured. Run the subscription plan seeder.',
                'SUBSCRIPTION_NOT_CONFIGURED',
                500
            );
        }

        $days = $plan->trial_days ?: (int) config('subscriptions.trial_days', 30);
        $starts = now();
        $ends = $starts->copy()->addDays($days);

        $subscription = Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
            'billing_period' => 'monthly',
            'trial_starts_at' => $starts,
            'trial_ends_at' => $ends,
            'starts_at' => $starts,
            'current_period_start' => $starts,
            'current_period_end' => $ends,
            'ends_at' => $ends,
            'price_amount' => 0,
            'currency' => $plan->currency ?: config('subscriptions.currency', 'IDR'),
        ]);

        $this->history($subscription, 'trial_started', null, Subscription::STATUS_TRIALING, null, $plan->id, $actor, [
            'trial_days' => $days,
            'trial_ends_at' => $ends->toIso8601String(),
        ]);

        return $subscription->load(['plan', 'pendingPlan']);
    }

    public function planFor(Business|int $business): Plan
    {
        return $this->forBusiness($business)->plan;
    }

    /* ------------------------------------------------------------------ */
    /* Status helpers */
    /* ------------------------------------------------------------------ */

    public function isActive(Business|Subscription|int $subject): bool
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        return $subscription->grantsAccess();
    }

    public function status(Business|Subscription|int $subject): string
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        return $subscription->effectiveStatus();
    }

    public function expiration(Business|Subscription|int $subject): ?Carbon
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        return $subscription->ends_at;
    }

    public function daysRemaining(Business|Subscription|int $subject): int
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        return $subscription->daysRemaining();
    }

    /**
     * Persist an expiry that has already happened in time. Kept lazy so no
     * scheduler is required for correctness; the console command does the
     * same work in bulk for reporting.
     */
    public function refreshStatus(Subscription $subscription): Subscription
    {
        $effective = $subscription->effectiveStatus();

        if ($effective !== $subscription->status) {
            $from = $subscription->status;
            $subscription->status = $effective;
            $subscription->ended_at = $effective === Subscription::STATUS_EXPIRED ? now() : $subscription->ended_at;
            $subscription->save();
            $this->history($subscription, 'expired', $from, $effective, $subscription->plan_id, $subscription->plan_id);
        }

        return $subscription;
    }

    /* ------------------------------------------------------------------ */
    /* Feature access */
    /* ------------------------------------------------------------------ */

    public function canAccessFeature(Business|Subscription|int $subject, string $feature): bool
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        if (! $subscription->grantsAccess()) {
            return false;
        }

        return $subscription->featureEnabled($feature);
    }

    public function assertFeature(Business|Subscription|int $subject, string $feature): void
    {
        if ($this->canAccessFeature($subject, $feature)) {
            return;
        }

        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        $catalog = config('subscriptions.feature_catalog', []);
        $label = $catalog[$feature]['label'] ?? $feature;
        $upgrade = $this->upgradeTarget($subscription);

        throw new SubscriptionException(
            $label.' is available on the '.($upgrade['name'] ?? 'Pro').' plan.',
            'FEATURE_NOT_AVAILABLE',
            403,
            [
                'feature' => $feature,
                'feature_label' => $label,
                'current_plan' => $subscription->plan?->slug,
                'current_plan_name' => $subscription->plan?->name,
                'status' => $subscription->effectiveStatus(),
                'upgrade_plan' => $upgrade['slug'] ?? null,
                'upgrade_plan_name' => $upgrade['name'] ?? null,
            ]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Usage and quotas */
    /* ------------------------------------------------------------------ */

    public function limit(Business|Subscription|int $subject, string $metric): ?int
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        return $subscription->limit($metric);
    }

    public function usage(Business|Subscription|int $subject, string $metric): int
    {
        $businessId = $subject instanceof Subscription
            ? $subject->business_id
            : ($subject instanceof Business ? $subject->id : (int) $subject);

        return match ($metric) {
            'products' => Product::where('business_id', $businessId)->count(),
            'customers' => Customer::where('business_id', $businessId)->count(),
            'outlets' => Outlet::where('business_id', $businessId)->count(),
            'users' => BusinessMembership::where('business_id', $businessId)->where('status', 'active')->count(),
            'transactions_per_month' => Sale::where('business_id', $businessId)
                ->where('status', 'completed')
                ->whereBetween('created_at', $this->periodBounds())
                ->count(),
            'exports_per_month' => $this->counterUsage($businessId, $metric),
            default => 0,
        };
    }

    public function remaining(Business|Subscription|int $subject, string $metric): ?int
    {
        $limit = $this->limit($subject, $metric);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->usage($subject, $metric));
    }

    public function checkLimit(Business|Subscription|int $subject, string $metric, int $adding = 1): bool
    {
        $limit = $this->limit($subject, $metric);

        if ($limit === null) {
            return true;
        }

        return $this->usage($subject, $metric) + $adding <= $limit;
    }

    /**
     * Enforce a quota before a write. The subscription row is locked so two
     * concurrent requests cannot both pass the last remaining slot.
     */
    public function enforceLimit(Business|Subscription|int $subject, string $metric, int $adding = 1): void
    {
        $subscription = $subject instanceof Subscription
            ? $subject
            : $this->forBusiness($subject);

        DB::transaction(function () use ($subscription, $metric, $adding) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if (! $locked->grantsAccess()) {
                throw new SubscriptionException(
                    'Your subscription has ended. Upgrade to keep creating records.',
                    'SUBSCRIPTION_REQUIRED',
                    402,
                    [
                        'status' => $locked->effectiveStatus(),
                        'current_plan' => $locked->plan?->slug,
                    ]
                );
            }

            $limit = $locked->limit($metric);
            if ($limit === null) {
                return;
            }

            $used = $this->usage($locked, $metric);
            if ($used + $adding <= $limit) {
                return;
            }

            $catalog = config('subscriptions.usage_metrics', []);
            $label = $catalog[$metric]['label'] ?? $metric;
            $upgrade = $this->upgradeTarget($locked);

            throw new SubscriptionException(
                'You have reached the '.$label.' limit for the '.($locked->plan?->name ?? 'current').' plan.',
                'USAGE_LIMIT_REACHED',
                402,
                [
                    'metric' => $metric,
                    'metric_label' => $label,
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => max(0, $limit - $used),
                    'current_plan' => $locked->plan?->slug,
                    'current_plan_name' => $locked->plan?->name,
                    'upgrade_plan' => $upgrade['slug'] ?? null,
                    'upgrade_plan_name' => $upgrade['name'] ?? null,
                ]
            );
        });
    }

    /** Increment a counter metric (exports) for the current billing month. */
    public function recordUsage(Business|Subscription|int $subject, string $metric, int $amount = 1): SubscriptionUsageRecord
    {
        $businessId = $subject instanceof Subscription
            ? $subject->business_id
            : ($subject instanceof Business ? $subject->id : (int) $subject);

        $subscription = $subject instanceof Subscription ? $subject : $this->forBusiness($businessId);
        [$start, $end] = $this->periodBounds();

        $record = SubscriptionUsageRecord::firstOrCreate(
            [
                'business_id' => $businessId,
                'metric' => $metric,
                'period_start' => $start->toDateString(),
            ],
            [
                'subscription_id' => $subscription->id,
                'period_end' => $end->toDateString(),
                'used_count' => 0,
            ]
        );

        $record->increment('used_count', $amount);

        return $record->refresh();
    }

    private function counterUsage(int $businessId, string $metric): int
    {
        [$start] = $this->periodBounds();

        return (int) SubscriptionUsageRecord::where('business_id', $businessId)
            ->where('metric', $metric)
            ->where('period_start', $start->toDateString())
            ->value('used_count');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodBounds(): array
    {
        $start = now()->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle transitions */
    /* ------------------------------------------------------------------ */

    public function activatePaid(
        Subscription $subscription,
        Plan $plan,
        string $period,
        ?SubscriptionPayment $payment = null,
        ?User $actor = null,
    ): Subscription {
        $period = in_array($period, Subscription::PERIODS, true) ? $period : 'monthly';

        return DB::transaction(function () use ($subscription, $plan, $period, $payment, $actor) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->status;
            $fromPlan = $locked->plan_id;

            // If the customer still has time left (trial or paid), the new
            // period starts when that time ends. An expired subscription
            // starts today.
            $start = now();
            if ($locked->grantsAccess() && $locked->ends_at && $locked->ends_at->isFuture()) {
                $start = $locked->ends_at->copy();
            }
            $end = $this->periodEnd($start, $period);

            $locked->fill([
                'plan_id' => $plan->id,
                'pending_plan_id' => null,
                'pending_billing_period' => null,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_period' => $period,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'ends_at' => $end,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'ended_at' => null,
                'suspended_at' => null,
                'provider' => $payment?->provider ?? $locked->provider,
                'price_amount' => $plan->priceFor($period),
                'currency' => $plan->currency ?: $locked->currency,
            ]);
            $locked->save();

            $this->history(
                $locked,
                $fromPlan !== $plan->id ? 'plan_changed' : 'subscription_activated',
                $fromStatus,
                Subscription::STATUS_ACTIVE,
                $fromPlan,
                $plan->id,
                $actor,
                ['billing_period' => $period, 'payment_id' => $payment?->id]
            );

            return $locked->load(['plan', 'pendingPlan']);
        });
    }

    /** Apply a plan change immediately (admin action or a settled upgrade). */
    public function changePlan(Subscription $subscription, Plan $plan, string $period, ?User $actor = null): Subscription
    {
        $period = in_array($period, Subscription::PERIODS, true) ? $period : $subscription->billing_period;

        return DB::transaction(function () use ($subscription, $plan, $period, $actor) {
            $locked = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $fromStatus = $locked->status;
            $fromPlan = $locked->plan_id;

            $locked->fill([
                'plan_id' => $plan->id,
                'billing_period' => $period,
                'pending_plan_id' => null,
                'pending_billing_period' => null,
                'status' => $locked->grantsAccess() ? $locked->status : Subscription::STATUS_ACTIVE,
                'price_amount' => $plan->priceFor($period),
                'currency' => $plan->currency ?: $locked->currency,
                'ended_at' => null,
            ]);

            if (! $locked->ends_at || $locked->ends_at->isPast()) {
                $start = now();
                $locked->current_period_start = $start;
                $locked->current_period_end = $this->periodEnd($start, $period);
                $locked->ends_at = $locked->current_period_end;
                $locked->status = Subscription::STATUS_ACTIVE;
                $locked->grace_ends_at = null;
            }

            $locked->save();

            $this->history($locked, 'plan_changed', $fromStatus, $locked->status, $fromPlan, $plan->id, $actor, [
                'billing_period' => $period,
                'immediate' => true,
            ]);

            return $locked->load(['plan', 'pendingPlan']);
        });
    }

    /** Downgrades take effect at the end of the paid period. */
    public function scheduleDowngrade(Subscription $subscription, Plan $plan, string $period, ?User $actor = null): Subscription
    {
        $period = in_array($period, Subscription::PERIODS, true) ? $period : $subscription->billing_period;

        $subscription->fill([
            'pending_plan_id' => $plan->id,
            'pending_billing_period' => $period,
        ]);
        $subscription->save();

        $this->history($subscription, 'downgrade_scheduled', $subscription->status, $subscription->status, $subscription->plan_id, $plan->id, $actor, [
            'billing_period' => $period,
            'effective_at' => $subscription->ends_at?->toIso8601String(),
        ]);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function cancel(Subscription $subscription, ?User $actor = null): Subscription
    {
        if ($subscription->status === Subscription::STATUS_EXPIRED) {
            throw new SubscriptionException('This subscription has already ended.', 'SUBSCRIPTION_ALREADY_EXPIRED', 422);
        }

        $from = $subscription->status;
        $subscription->fill([
            'status' => Subscription::STATUS_CANCELED,
            'cancel_at_period_end' => true,
            'canceled_at' => now(),
        ]);
        $subscription->save();

        $this->history($subscription, 'subscription_canceled', $from, Subscription::STATUS_CANCELED, $subscription->plan_id, $subscription->plan_id, $actor, [
            'access_until' => $subscription->ends_at?->toIso8601String(),
        ]);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function resume(Subscription $subscription, ?User $actor = null): Subscription
    {
        if (! in_array($subscription->status, [Subscription::STATUS_CANCELED, Subscription::STATUS_PAST_DUE], true)) {
            throw new SubscriptionException('Only a canceled or past-due subscription can be resumed.', 'SUBSCRIPTION_NOT_CANCELED', 422);
        }

        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            throw new SubscriptionException('This subscription has ended. Renew it instead.', 'SUBSCRIPTION_EXPIRED', 422);
        }

        $from = $subscription->status;
        $subscription->fill([
            'status' => Subscription::STATUS_ACTIVE,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'grace_ends_at' => null,
        ]);
        $subscription->save();

        $this->history($subscription, 'subscription_resumed', $from, Subscription::STATUS_ACTIVE, $subscription->plan_id, $subscription->plan_id, $actor);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function markPastDue(Subscription $subscription, ?string $reason = null, ?User $actor = null): Subscription
    {
        $from = $subscription->status;
        $graceDays = (int) config('subscriptions.grace_period_days', 3);

        $subscription->fill([
            'status' => Subscription::STATUS_PAST_DUE,
            'grace_ends_at' => now()->addDays($graceDays),
        ]);
        $subscription->save();

        $this->history($subscription, 'payment_failed', $from, Subscription::STATUS_PAST_DUE, $subscription->plan_id, $subscription->plan_id, $actor, [
            'reason' => $reason,
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
        ]);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function expire(Subscription $subscription, ?User $actor = null, string $action = 'expired'): Subscription
    {
        $from = $subscription->status;
        $subscription->fill([
            'status' => Subscription::STATUS_EXPIRED,
            'ended_at' => now(),
            'cancel_at_period_end' => false,
        ]);
        $subscription->save();

        $this->history($subscription, $action, $from, Subscription::STATUS_EXPIRED, $subscription->plan_id, $subscription->plan_id, $actor);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function suspend(Subscription $subscription, ?User $actor = null, ?string $reason = null): Subscription
    {
        $from = $subscription->status;
        $subscription->fill([
            'status' => Subscription::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
        $subscription->save();

        $this->history($subscription, 'subscription_suspended', $from, Subscription::STATUS_SUSPENDED, $subscription->plan_id, $subscription->plan_id, $actor, [
            'reason' => $reason,
        ]);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function reactivate(Subscription $subscription, ?User $actor = null): Subscription
    {
        $from = $subscription->status;

        if (! $subscription->ends_at || $subscription->ends_at->isPast()) {
            $start = now();
            $subscription->current_period_start = $start;
            $subscription->current_period_end = $this->periodEnd($start, $subscription->billing_period);
            $subscription->ends_at = $subscription->current_period_end;
        }

        $subscription->fill([
            'status' => Subscription::STATUS_ACTIVE,
            'suspended_at' => null,
            'ended_at' => null,
            'grace_ends_at' => null,
        ]);
        $subscription->save();

        $this->history($subscription, 'subscription_reactivated', $from, Subscription::STATUS_ACTIVE, $subscription->plan_id, $subscription->plan_id, $actor);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    public function extendTrial(Subscription $subscription, int $days, ?User $actor = null): Subscription
    {
        if ($days < 1 || $days > 365) {
            throw new SubscriptionException('Trial extensions must be between 1 and 365 days.', 'INVALID_TRIAL_EXTENSION', 422);
        }

        $from = $subscription->status;
        $base = $subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()
            ? $subscription->trial_ends_at->copy()
            : now();
        $end = $base->addDays($days);

        $subscription->fill([
            'status' => Subscription::STATUS_TRIALING,
            'trial_starts_at' => $subscription->trial_starts_at ?? now(),
            'trial_ends_at' => $end,
            'current_period_end' => $end,
            'ends_at' => $end,
            'ended_at' => null,
            'grace_ends_at' => null,
        ]);
        $subscription->save();

        $this->history($subscription, 'trial_extended', $from, Subscription::STATUS_TRIALING, $subscription->plan_id, $subscription->plan_id, $actor, [
            'days' => $days,
            'trial_ends_at' => $end->toIso8601String(),
        ]);

        return $subscription->fresh(['plan', 'pendingPlan']);
    }

    /** Bulk expiry used by the scheduler and the admin overview. */
    public function runLifecycle(): array
    {
        $expired = 0;

        Subscription::with('plan')
            ->whereIn('status', [
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_CANCELED,
            ])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->get()
            ->each(function (Subscription $subscription) use (&$expired) {
                $this->expire($subscription, null, 'subscription_expired');
                $expired++;
            });

        Subscription::with('plan')
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now())
            ->get()
            ->each(function (Subscription $subscription) use (&$expired) {
                $this->expire($subscription, null, 'grace_period_ended');
                $expired++;
            });

        return ['expired' => $expired];
    }

    public function periodEnd(Carbon $from, string $period): Carbon
    {
        return $period === 'yearly'
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();
    }

    /* ------------------------------------------------------------------ */
    /* Presentation */
    /* ------------------------------------------------------------------ */

    /**
     * Compact entitlement summary embedded in `/me`, so the shell can show the
     * trial banner and hide Pro surfaces without a second request. Usage is
     * deliberately omitted here; the subscription dashboard fetches it.
     */
    public function summary(Business $business): array
    {
        $subscription = $this->forBusiness($business);
        $this->refreshStatus($subscription);
        $plan = $subscription->plan;
        $effective = $subscription->effectiveStatus();

        return [
            'status' => $effective,
            'raw_status' => $subscription->status,
            'is_active' => $subscription->grantsAccess(),
            'billing_period' => $subscription->billing_period,
            'plan' => $plan ? [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'is_trial' => $plan->isTrialPlan(),
            ] : null,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'trial_days_remaining' => $effective === Subscription::STATUS_TRIALING ? $subscription->daysRemaining() : 0,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'days_remaining' => $subscription->daysRemaining(),
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'features' => $this->resolvedFeatures($plan, $business->industry),
            'limits' => $plan?->limits ?? [],
            'trial_reminders' => config('subscriptions.trial_reminder_days', []),
        ];
    }

    public function snapshot(Business $business, ?Subscription $subscription = null): array
    {
        $subscription = $subscription ?? $this->forBusiness($business);
        $this->refreshStatus($subscription);
        $plan = $subscription->plan;
        $effective = $subscription->effectiveStatus();
        $grants = $subscription->grantsAccess();

        $usage = [];
        foreach (config('subscriptions.usage_metrics', []) as $metric => $meta) {
            $limit = $plan?->limit($metric);
            $used = $this->usage($business, $metric);
            $percent = $limit && $limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 0;
            $level = $limit === null
                ? 'unlimited'
                : ($used >= $limit ? 'reached' : ($percent >= 90 ? 'critical' : ($percent >= 80 ? 'warning' : 'ok')));

            $usage[] = [
                'metric' => $metric,
                'label' => $meta['label'],
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'percent' => $percent,
                'level' => $level,
                'unlimited' => $limit === null,
            ];
        }

        $openInvoice = SubscriptionInvoice::where('business_id', $business->id)
            ->where('status', SubscriptionInvoice::STATUS_OPEN)
            ->orderByDesc('id')
            ->first();

        return [
            'business_id' => (string) $business->id,
            'subscription' => [
                'id' => (string) $subscription->id,
                'status' => $effective,
                'raw_status' => $subscription->status,
                'is_active' => $grants,
                'billing_period' => $subscription->billing_period,
                'trial_starts_at' => $subscription->trial_starts_at?->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'trial_days_remaining' => $effective === Subscription::STATUS_TRIALING ? $subscription->daysRemaining() : 0,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'days_remaining' => $subscription->daysRemaining(),
                'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'canceled_at' => $subscription->canceled_at?->toIso8601String(),
                'next_billing_date' => $grants ? $subscription->ends_at?->toIso8601String() : null,
                'provider' => $subscription->provider,
                'price_amount' => (int) $subscription->price_amount,
                'currency' => $subscription->currency,
                'pending_plan' => $subscription->pendingPlan ? $subscription->pendingPlan->toApiArray() : null,
            ],
            'plan' => $plan?->toApiArray(),
            'usage' => $usage,
            'features' => $this->resolvedFeatures($plan, $business->industry),
            'available_plans' => $this->plans->publicPlans($business->industry)
                ->reject(fn (Plan $candidate) => $candidate->id === $plan?->id)
                ->map(fn (Plan $candidate) => $candidate->toApiArray())
                ->values()
                ->all(),
            'open_invoice' => $openInvoice ? $this->invoicePayload($openInvoice) : null,
            'trial_reminders' => config('subscriptions.trial_reminder_days', []),
            'provider_status' => $this->providerStatus(),
        ];
    }

    public function invoicePayload(SubscriptionInvoice $invoice): array
    {
        $invoice->loadMissing(['plan', 'payments']);

        return [
            'id' => (string) $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'billing_period' => $invoice->billing_period,
            'currency' => $invoice->currency,
            'subtotal' => (int) $invoice->subtotal,
            'discount' => (int) $invoice->discount,
            'tax' => (int) $invoice->tax,
            'total' => (int) $invoice->total,
            'amount_paid' => (int) $invoice->amount_paid,
            'line_items' => $invoice->line_items ?? [],
            'plan' => $invoice->plan ? [
                'slug' => $invoice->plan->slug,
                'name' => $invoice->plan->name,
            ] : null,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
            'payments' => $invoice->payments->map(fn (SubscriptionPayment $payment) => $this->paymentPayload($payment))->values()->all(),
        ];
    }

    public function paymentPayload(SubscriptionPayment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'status' => $payment->status,
            'amount' => (int) $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }

    public function providerStatus(): array
    {
        $providers = config('subscriptions.providers', []);

        return [
            'manual' => [
                'enabled' => (bool) ($providers['manual']['enabled'] ?? false),
                'label' => $providers['manual']['label'] ?? 'Bank transfer',
            ],
            'midtrans' => [
                'enabled' => (bool) ($providers['midtrans']['enabled'] ?? false)
                    && filled($providers['midtrans']['server_key'] ?? null)
                    && filled($providers['midtrans']['client_key'] ?? null),
                'label' => $providers['midtrans']['label'] ?? 'Midtrans',
            ],
            'xendit' => [
                'enabled' => (bool) ($providers['xendit']['enabled'] ?? false)
                    && filled($providers['xendit']['secret_key'] ?? null),
                'label' => $providers['xendit']['label'] ?? 'Xendit',
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function resolvedFeatures(?Plan $plan, ?string $industry): array
    {
        if (! $plan) {
            return [];
        }

        $features = [];
        $keys = array_merge(
            array_keys($plan->features ?? []),
            $plan->planIndustryFeatures()->where('industry', $industry)->pluck('feature_key')->all()
        );
        foreach (array_unique($keys) as $key) {
            $features[$key] = (bool) $plan->featureEnabledFor($key, $industry);
        }
        // include industry feature keys not present
        foreach (config('industries.feature_keys', []) as $key => $_) {
            if (! array_key_exists($key, $features)) {
                $features[$key] = (bool) $plan->featureEnabledFor($key, $industry);
            }
        }

        return $features;
    }

    private function upgradeTarget(Subscription $subscription): ?array
    {
        $current = $subscription->plan?->sort_order ?? -1;

        $target = $this->plans->publicPlans()
            ->filter(fn (Plan $plan) => ! $plan->isTrialPlan() && $plan->sort_order > $current)
            ->sortBy('sort_order')
            ->first();

        return $target ? ['slug' => $target->slug, 'name' => $target->name] : null;
    }

    public function history(
        Subscription $subscription,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $fromPlanId,
        ?int $toPlanId,
        ?User $actor = null,
        array $metadata = [],
    ): SubscriptionHistory {
        return SubscriptionHistory::create([
            'business_id' => $subscription->business_id,
            'subscription_id' => $subscription->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_plan_id' => $fromPlanId,
            'to_plan_id' => $toPlanId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}

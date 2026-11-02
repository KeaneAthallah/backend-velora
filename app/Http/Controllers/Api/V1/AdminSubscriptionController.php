<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Subscription\Payments\BillingService;
use App\Services\Subscription\PlanService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
        protected PlanService $plans,
        protected BillingService $billing,
    ) {}

    public function index(Request $request)
    {
        $this->requireAdmin($request);

        $query = Business::query()->with(['subscription.plan', 'subscription.pendingPlan']);

        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->whereHas('subscription', fn ($builder) => $builder->where('status', $status));
        }
        if ($request->filled('plan')) {
            $plan = $request->input('plan');
            $query->whereHas('subscription.plan', fn ($builder) => $builder->where('slug', $plan));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('slug', 'like', $term));
        }

        $businesses = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 20));

        $businesses->getCollection()->transform(function (Business $business) {
            return $this->adminRow($business);
        });

        return $this->success($businesses);
    }

    public function overview(Request $request)
    {
        $this->requireAdmin($request);

        $statuses = [
            Subscription::STATUS_TRIALING,
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_CANCELED,
            Subscription::STATUS_EXPIRED,
            Subscription::STATUS_SUSPENDED,
        ];

        $counts = [];
        foreach ($statuses as $status) {
            $counts[$status] = Subscription::where('status', $status)->count();
        }

        $totalBusinesses = Business::count();
        $counts['none'] = max(0, $totalBusinesses - Subscription::count());

        $active = Subscription::with('plan')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->get();

        $mrr = (int) $active->sum(function (Subscription $subscription) {
            if (! $subscription->plan || $subscription->plan->isTrialPlan()) {
                return 0;
            }

            return $subscription->billing_period === 'yearly'
                ? (int) round($subscription->price_amount / 12)
                : (int) $subscription->price_amount;
        });

        $monthRevenue = (int) SubscriptionInvoice::where('status', SubscriptionInvoice::STATUS_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total');

        $allRevenue = (int) SubscriptionInvoice::where('status', SubscriptionInvoice::STATUS_PAID)->sum('total');

        $planDistribution = Plan::withCount(['subscriptions as active_count' => function ($query) {
            $query->where('status', Subscription::STATUS_ACTIVE);
        }])->orderBy('sort_order')->get()->map(fn (Plan $plan) => [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'active' => (int) $plan->active_count,
            'monthly_price' => (int) $plan->monthly_price,
            'yearly_price' => (int) $plan->yearly_price,
        ]);

        $recentPayments = SubscriptionPayment::with(['business:id,name', 'invoice:id,number'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => (string) $payment->id,
                'business' => $payment->business?->name,
                'invoice' => $payment->invoice?->number,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'amount' => (int) $payment->amount,
                'currency' => $payment->currency,
                'created_at' => $payment->created_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ]);

        return $this->success([
            'businesses' => $totalBusinesses,
            'counts' => $counts,
            'mrr' => $mrr,
            'currency' => config('subscriptions.currency', 'IDR'),
            'revenue_this_month' => $monthRevenue,
            'revenue_all_time' => $allRevenue,
            'plan_distribution' => $planDistribution,
            'recent_payments' => $recentPayments,
            'trial_days' => (int) config('subscriptions.trial_days', 30),
            'grace_days' => (int) config('subscriptions.grace_period_days', 3),
        ]);
    }

    public function show(Request $request, Business $business)
    {
        $this->requireAdmin($request);

        $subscription = $this->subscriptions->forBusiness($business);

        return $this->success([
            'business' => [
                'id' => (string) $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'status' => $business->status,
                'currency' => $business->currency,
                'created_at' => $business->created_at?->toIso8601String(),
            ],
            'snapshot' => $this->subscriptions->snapshot($business, $subscription),
            'invoices' => SubscriptionInvoice::where('business_id', $business->id)
                ->with('plan')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SubscriptionInvoice $invoice) => $this->subscriptions->invoicePayload($invoice))
                ->values(),
            'payments' => SubscriptionPayment::where('business_id', $business->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SubscriptionPayment $payment) => $this->subscriptions->paymentPayload($payment))
                ->values(),
            'history' => SubscriptionHistory::where('business_id', $business->id)
                ->with(['actor:id,name', 'fromPlan:id,slug,name', 'toPlan:id,slug,name'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function changePlan(Request $request, Business $business)
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'slug')],
            'billing_period' => ['required', Rule::in(Subscription::PERIODS)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = $this->plans->findBySlug($data['plan']);
        if (! $plan) {
            return $this->error('Plan not found', 404, [], 'PLAN_NOT_FOUND');
        }

        $subscription = $this->subscriptions->forBusiness($business);
        $before = $subscription->toArray();
        $updated = $this->subscriptions->changePlan($subscription, $plan, $data['billing_period'], $request->user());

        $this->audit($request, $business, 'subscription.plan_changed', $subscription, $before, $updated->toArray(), [
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->success([
            'subscription' => $this->subscriptions->snapshot($business, $updated),
        ], 'Plan changed');
    }

    public function extendTrial(Request $request, Business $business)
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $subscription = $this->subscriptions->forBusiness($business);
        $before = $subscription->toArray();
        $updated = $this->subscriptions->extendTrial($subscription, (int) $data['days'], $request->user());

        $this->audit($request, $business, 'subscription.trial_extended', $subscription, $before, $updated->toArray(), [
            'days' => (int) $data['days'],
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->success([
            'subscription' => $this->subscriptions->snapshot($business, $updated),
        ], 'Trial extended');
    }

    public function suspend(Request $request, Business $business)
    {
        $this->requireAdmin($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $subscription = $this->subscriptions->forBusiness($business);
        $before = $subscription->toArray();
        $updated = $this->subscriptions->suspend($subscription, $request->user(), $data['reason'] ?? null);

        $this->audit($request, $business, 'subscription.suspended', $subscription, $before, $updated->toArray(), [
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->success(['subscription' => $this->subscriptions->snapshot($business, $updated)], 'Subscription suspended');
    }

    public function reactivate(Request $request, Business $business)
    {
        $this->requireAdmin($request);

        $subscription = $this->subscriptions->forBusiness($business);
        $before = $subscription->toArray();
        $updated = $this->subscriptions->reactivate($subscription, $request->user());

        $this->audit($request, $business, 'subscription.reactivated', $subscription, $before, $updated->toArray());

        return $this->success(['subscription' => $this->subscriptions->snapshot($business, $updated)], 'Subscription reactivated');
    }

    public function confirmPayment(Request $request, SubscriptionPayment $payment)
    {
        $this->requireAdmin($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $business = Business::findOrFail($payment->business_id);
        $before = $payment->toArray();
        $result = $this->billing->confirmManualPayment($payment, $request->user());

        $this->audit($request, $business, 'subscription.payment_confirmed', $payment, $before, $payment->fresh()->toArray(), [
            'note' => $data['note'] ?? null,
        ]);

        return $this->success($result, 'Payment confirmed and subscription activated');
    }

    public function payments(Request $request)
    {
        $this->requireAdmin($request);

        $query = SubscriptionPayment::with(['business:id,name', 'invoice.plan']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }

        $payments = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 25));
        $payments->getCollection()->transform(fn (SubscriptionPayment $payment) => [
            'id' => (string) $payment->id,
            'business_id' => (string) $payment->business_id,
            'business' => $payment->business?->name,
            'invoice' => $payment->invoice?->number,
            'plan' => $payment->invoice?->plan?->name,
            'provider' => $payment->provider,
            'provider_reference' => $payment->provider_reference,
            'status' => $payment->status,
            'amount' => (int) $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'failure_reason' => $payment->failure_reason,
            'created_at' => $payment->created_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ]);

        return $this->success($payments);
    }

    public function invoices(Request $request)
    {
        $this->requireAdmin($request);

        $query = SubscriptionInvoice::with(['business:id,name', 'plan']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }

        return $this->success($query->orderByDesc('id')->paginate((int) $request->input('per_page', 25)));
    }

    public function histories(Request $request)
    {
        $this->requireAdmin($request);

        $query = SubscriptionHistory::with(['business:id,name', 'actor:id,name', 'fromPlan:id,slug,name', 'toPlan:id,slug,name']);

        if ($request->filled('business_id')) {
            $query->where('business_id', $request->input('business_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        return $this->success($query->orderByDesc('id')->paginate((int) $request->input('per_page', 50)));
    }

    public function webhookEvents(Request $request)
    {
        $this->requireAdmin($request);

        $query = PaymentWebhookEvent::query();
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->success($query->orderByDesc('id')->paginate((int) $request->input('per_page', 50)));
    }

    public function plans(Request $request)
    {
        $this->requireAdmin($request);

        return $this->success([
            'plans' => $this->plans->allPlans()->map(fn (Plan $plan) => $plan->toApiArray())->values()->all(),
            'feature_catalog' => collect(config('subscriptions.feature_catalog', []))
                ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label'], 'group' => $meta['group']])
                ->values()
                ->all(),
            'usage_metrics' => collect(config('subscriptions.usage_metrics', []))
                ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label']])
                ->values()
                ->all(),
        ]);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $this->requireAdmin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'monthly_price' => ['sometimes', 'integer', 'min:0'],
            'yearly_price' => ['sometimes', 'integer', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ]);

        $before = $plan->toArray();
        $plan->update(collect($data)->only([
            'name', 'description', 'monthly_price', 'yearly_price',
            'trial_days', 'is_public', 'is_active', 'limits', 'features',
        ])->all());

        foreach (($data['features'] ?? []) as $key => $enabled) {
            $catalog = config('subscriptions.feature_catalog', []);
            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $key],
                ['enabled' => (bool) $enabled, 'label' => $catalog[$key]['label'] ?? $key]
            );
        }

        $this->audit($request, null, 'plan.updated', $plan, $before, $plan->fresh()->toArray());

        return $this->success($plan->fresh()->toApiArray(), 'Plan updated');
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function adminRow(Business $business): array
    {
        $subscription = $business->subscription;

        return [
            'id' => (string) $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'business_status' => $business->status,
            'created_at' => $business->created_at?->toIso8601String(),
            'subscription' => $subscription ? [
                'id' => (string) $subscription->id,
                'status' => $subscription->effectiveStatus(),
                'billing_period' => $subscription->billing_period,
                'plan' => $subscription->plan?->slug,
                'plan_name' => $subscription->plan?->name,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'days_remaining' => $subscription->daysRemaining(),
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'pending_plan' => $subscription->pendingPlan?->slug,
            ] : null,
            'usage' => [
                'products' => $this->subscriptions->usage($business, 'products'),
                'customers' => $this->subscriptions->usage($business, 'customers'),
                'outlets' => $this->subscriptions->usage($business, 'outlets'),
                'users' => $this->subscriptions->usage($business, 'users'),
                'transactions_per_month' => $this->subscriptions->usage($business, 'transactions_per_month'),
            ],
        ];
    }

    private function requireAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_platform_admin, 403, 'Platform administrator required');
    }

    private function audit(
        Request $request,
        ?Business $business,
        string $action,
        object $resource,
        array $before,
        array $after,
        array $extra = [],
    ): void {
        AuditLog::create([
            'business_id' => $business?->id,
            'outlet_id' => null,
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'resource_type' => $resource::class,
            'resource_id' => (string) ($resource->id ?? ''),
            'before' => array_merge($before, $extra),
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }
}

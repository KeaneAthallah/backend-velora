<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\AuthorizationService;
use App\Services\Subscription\Payments\BillingService;
use App\Services\Subscription\Payments\PaymentGatewayManager;
use App\Services\Subscription\PlanService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SubscriptionService $subscriptions,
        protected PlanService $plans,
        protected BillingService $billing,
        protected PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $snapshot = $this->subscriptions->snapshot($business);
        $snapshot['providers'] = $this->gateways->available();
        $snapshot['can_manage'] = $this->authz->hasPermission($request->user(), 'businesses.manage', $business->id);

        return $this->success($snapshot);
    }

    public function plans(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $industry = $business->industry;

        return $this->success([
            'plans' => $this->plans->publicPlans($industry)->map(fn ($plan) => $plan->toApiArray())->values()->all(),
            'providers' => $this->gateways->available(),
            'current' => $this->subscriptions->snapshot($business)['plan'],
        ]);
    }

    public function checkout(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'slug')],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $plan = $this->plans->findBySlug($data['plan']);
        if (! $plan) {
            return $this->error('Plan not found', 404, [], 'PLAN_NOT_FOUND');
        }

        if (! $plan->supportsIndustry($business->industry)) {
            return $this->error('Plan is not available for this industry.', 422, [], 'PLAN_INDUSTRY_MISMATCH');
        }

        // Enterprise plans are not self-serve.
        if ($plan->slug === 'enterprise' || ($plan->metadata['pricing'] ?? null) === 'custom') {
            return $this->error('Enterprise plans require a sales conversation.', 422, [], 'PLAN_NOT_SELF_SERVE');
        }

        $result = $this->billing->createCheckout(
            $business,
            $plan,
            $data['billing_period'],
            $data['provider'] ?? 'manual',
            $request->user(),
            $data['idempotency_key'] ?? null,
        );

        return $this->success($result, 'Checkout created', 201);
    }

    public function changePlan(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('subscription_plans', 'slug')],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'provider' => ['nullable', 'string', 'max:32'],
        ]);

        $target = $this->plans->findBySlug($data['plan']);
        if (! $target || $target->isTrialPlan()) {
            return $this->error('Choose Basic or Pro to change your plan.', 422, [], 'INVALID_PLAN');
        }

        if (! $target->supportsIndustry($business->industry)) {
            return $this->error('Plan is not available for this industry.', 422, [], 'PLAN_INDUSTRY_MISMATCH');
        }

        $subscription = $this->subscriptions->forBusiness($business);
        $current = $subscription->plan;
        $isUpgrade = ! $current || $target->sort_order > $current->sort_order;
        $samePlan = $current && $target->id === $current->id;

        if ($isUpgrade || ($samePlan && $data['billing_period'] !== $subscription->billing_period)) {
            $checkout = $this->billing->createCheckout(
                $business,
                $target,
                $data['billing_period'],
                $data['provider'] ?? 'manual',
                $request->user(),
            );

            return $this->success([
                'requires_payment' => true,
                'checkout' => $checkout,
                'subscription' => $this->subscriptions->snapshot($business),
            ], 'Complete payment to activate the new plan', 201);
        }

        if ($samePlan) {
            return $this->success([
                'requires_payment' => false,
                'subscription' => $this->subscriptions->snapshot($business),
            ], 'You are already on this plan');
        }

        $updated = $this->subscriptions->scheduleDowngrade($subscription, $target, $data['billing_period'], $request->user());

        return $this->success([
            'requires_payment' => false,
            'scheduled' => true,
            'effective_at' => $updated->ends_at?->toIso8601String(),
            'subscription' => $this->subscriptions->snapshot($business),
        ], 'Downgrade scheduled for the end of the current period');
    }

    public function renew(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $data = $request->validate([
            'billing_period' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'provider' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = $this->subscriptions->forBusiness($business);
        $plan = $subscription->pendingPlan ?? $subscription->plan;

        if (! $plan || $plan->isTrialPlan()) {
            return $this->error('Choose Basic or Pro to renew.', 422, [], 'INVALID_PLAN');
        }

        $period = $data['billing_period'] ?? $subscription->pending_billing_period ?? $subscription->billing_period;

        $result = $this->billing->createCheckout(
            $business,
            $plan,
            $period,
            $data['provider'] ?? 'manual',
            $request->user(),
        );

        return $this->success($result, 'Renewal checkout created', 201);
    }

    public function cancel(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $subscription = $this->subscriptions->forBusiness($business);
        $updated = $this->subscriptions->cancel($subscription, $request->user());

        return $this->success([
            'subscription' => $this->subscriptions->snapshot($business, $updated),
        ], 'Subscription canceled. Access continues until the end of the paid period.');
    }

    public function resume(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $subscription = $this->subscriptions->forBusiness($business);
        $updated = $this->subscriptions->resume($subscription, $request->user());

        return $this->success([
            'subscription' => $this->subscriptions->snapshot($business, $updated),
        ], 'Subscription resumed');
    }

    public function invoices(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $invoices = SubscriptionInvoice::where('business_id', $business->id)
            ->with('plan')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return $this->success($invoices);
    }

    public function invoice(Request $request, SubscriptionInvoice $invoice)
    {
        $business = $this->business($request);
        if ($invoice->business_id !== $business->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        return $this->success($this->subscriptions->invoicePayload($invoice));
    }

    public function payments(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $payments = SubscriptionPayment::where('business_id', $business->id)
            ->with('invoice.plan')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return $this->success($payments);
    }

    public function syncPayment(Request $request, SubscriptionPayment $payment)
    {
        $business = $this->business($request);
        if ($payment->business_id !== $business->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $result = $this->billing->syncPayment($payment);

        return $this->success($result, 'Payment status refreshed');
    }

    public function sandboxComplete(Request $request, SubscriptionPayment $payment)
    {
        $business = $this->business($request);
        if ($payment->business_id !== $business->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'businesses.manage', $business->id);

        $result = $this->billing->completeSandbox($payment, $request->user());

        return $this->success($result, 'Sandbox payment completed');
    }

    public function history(Request $request)
    {
        $business = $this->business($request);
        $this->authz->requirePermission($request->user(), 'businesses.view', $business->id);

        $histories = SubscriptionHistory::where('business_id', $business->id)
            ->with(['actor:id,name', 'fromPlan:id,slug,name', 'toPlan:id,slug,name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->success($histories->map(fn (SubscriptionHistory $h) => [
            'id' => (string) $h->id,
            'action' => $h->action,
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'from_plan' => $h->fromPlan ? ['slug' => $h->fromPlan->slug, 'name' => $h->fromPlan->name] : null,
            'to_plan' => $h->toPlan ? ['slug' => $h->toPlan->slug, 'name' => $h->toPlan->name] : null,
            'metadata' => $h->metadata ?? [],
            'actor' => $h->actor ? ['id' => (string) $h->actor->id, 'name' => $h->actor->name] : null,
            'created_at' => $h->created_at?->toIso8601String(),
        ]));
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 422, 'Business context required');

        return $business;
    }
}

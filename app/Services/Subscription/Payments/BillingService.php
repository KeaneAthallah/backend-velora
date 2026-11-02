<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Subscription\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected SubscriptionService $subscriptions,
    ) {}

    /**
     * Create (or reuse) an invoice + pending payment, then ask the provider for
     * a checkout URL. Nothing is activated here: only a verified webhook, an
     * administrator confirmation, or a provider status poll may do that.
     */
    public function createCheckout(
        Business $business,
        Plan $plan,
        string $period,
        string $provider,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): array {
        if ($plan->isTrialPlan() || $plan->priceFor($period) <= 0) {
            throw new SubscriptionException('Choose a paid plan to check out.', 'INVALID_PLAN', 422);
        }

        $period = in_array($period, Subscription::PERIODS, true) ? $period : 'monthly';
        $gateway = $this->gateways->gateway($provider);
        $subscription = $this->subscriptions->forBusiness($business);

        if ($idempotencyKey) {
            $existing = SubscriptionPayment::with('invoice')
                ->where('business_id', $business->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $this->checkoutPayload($existing);
            }
        }

        $result = DB::transaction(function () use ($business, $plan, $period, $provider, $idempotencyKey, $subscription) {
            $amount = $plan->priceFor($period);

            $invoice = SubscriptionInvoice::where('business_id', $business->id)
                ->where('plan_id', $plan->id)
                ->where('billing_period', $period)
                ->where('status', SubscriptionInvoice::STATUS_OPEN)
                ->where('total', $amount)
                ->orderByDesc('id')
                ->first();

            if (! $invoice) {
                $invoice = SubscriptionInvoice::create([
                    'business_id' => $business->id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                    'number' => $this->nextInvoiceNumber($business->id),
                    'status' => SubscriptionInvoice::STATUS_OPEN,
                    'billing_period' => $period,
                    'currency' => $plan->currency ?: config('subscriptions.currency', 'IDR'),
                    'subtotal' => $amount,
                    'discount' => 0,
                    'tax' => 0,
                    'total' => $amount,
                    'amount_paid' => 0,
                    'line_items' => [[
                        'description' => 'Velora '.$plan->name.' — '.$period,
                        'quantity' => 1,
                        'unit_amount' => $amount,
                        'amount' => $amount,
                    ]],
                    'issued_at' => now(),
                    'due_at' => now()->addDays(7),
                ]);
            }

            $payment = SubscriptionPayment::where('invoice_id', $invoice->id)
                ->where('provider', $provider)
                ->where('status', SubscriptionPayment::STATUS_PENDING)
                ->first();

            if (! $payment) {
                $payment = SubscriptionPayment::create([
                    'business_id' => $business->id,
                    'invoice_id' => $invoice->id,
                    'subscription_id' => $subscription->id,
                    'provider' => $provider,
                    'status' => SubscriptionPayment::STATUS_PENDING,
                    'amount' => $amount,
                    'currency' => $invoice->currency,
                    'method' => $provider,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addDay(),
                ]);
            }

            return [$invoice, $payment];
        });

        [$invoice, $payment] = $result;

        try {
            $checkout = $gateway->createCheckout($invoice, [
                'customer' => [
                    'first_name' => $business->name,
                    'email' => $actor?->email,
                ],
            ]);
        } catch (\Throwable $e) {
            $payment->update([
                'status' => SubscriptionPayment::STATUS_FAILED,
                'failure_reason' => Str::limit($e->getMessage(), 250),
            ]);

            throw $e;
        }

        $payment->update([
            'provider_reference' => $checkout['reference'],
            'raw_payload' => array_merge($checkout['raw'] ?? [], [
                'redirect_url' => $checkout['redirect_url'] ?? null,
                'instructions' => $checkout['instructions'] ?? null,
            ]),
        ]);

        $this->subscriptions->history(
            $this->subscriptions->forBusiness($business),
            'checkout_started',
            $subscription->status,
            $subscription->status,
            $subscription->plan_id,
            $plan->id,
            $actor,
            ['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'provider' => $provider]
        );

        return $this->checkoutPayload($payment->fresh(['invoice.plan']), $checkout);
    }

    /** A provider callback. Verified, de-duplicated, then applied once. */
    public function handleWebhook(string $provider, Request $request): array
    {
        $gateway = $this->gateways->gateway($provider);
        $event = $gateway->verifyWebhook($request);

        $record = PaymentWebhookEvent::firstOrCreate(
            ['provider' => $provider, 'event_id' => $event['event_id']],
            [
                'event_type' => $event['event_type'] ?? null,
                'status' => PaymentWebhookEvent::STATUS_RECEIVED,
                'signature' => $request->header('x-callback-token') ?: $request->input('signature_key'),
                'payload' => $event['raw'] ?? [],
            ]
        );

        if (! $record->wasRecentlyCreated && $record->status === PaymentWebhookEvent::STATUS_PROCESSED) {
            return ['status' => 'already_processed', 'event_id' => $event['event_id']];
        }

        $payment = SubscriptionPayment::with(['invoice.plan', 'subscription'])
            ->where('provider', $provider)
            ->where('provider_reference', $event['reference'])
            ->first();

        if (! $payment) {
            $payment = SubscriptionPayment::with(['invoice.plan', 'subscription'])
                ->where('provider', $provider)
                ->whereHas('invoice', fn ($query) => $query->where('number', $event['reference']))
                ->first();
        }

        if (! $payment) {
            $record->update([
                'status' => PaymentWebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
                'error' => 'No payment matched reference '.$event['reference'],
            ]);

            return ['status' => 'ignored', 'event_id' => $event['event_id']];
        }

        try {
            if ($event['status'] === 'paid') {
                $this->assertAmount($payment, $event['amount'] ?? null);
                $this->applyPaid($payment, null, $event['paid_at'] ?? null);
            } elseif (in_array($event['status'], ['failed', 'expired'], true)) {
                $this->applyFailure($payment, $event['status'], $event['raw']['status_message'] ?? null);
            } elseif ($event['status'] === 'refunded') {
                $this->applyRefund($payment);
            }
        } catch (\Throwable $e) {
            $record->update([
                'status' => PaymentWebhookEvent::STATUS_FAILED,
                'processed_at' => now(),
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }

        $record->update([
            'status' => PaymentWebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return [
            'status' => 'processed',
            'event_id' => $event['event_id'],
            'payment_id' => (string) $payment->id,
        ];
    }

    /** Poll the provider for a payment the customer says they completed. */
    public function syncPayment(SubscriptionPayment $payment): array
    {
        if ($payment->isPaid()) {
            return ['status' => 'paid', 'payment' => $this->subscriptions->paymentPayload($payment)];
        }

        $gateway = $this->gateways->gateway($payment->provider);
        $result = $gateway->fetchStatus($payment);

        if ($result['status'] === 'paid') {
            $this->assertAmount($payment, $result['amount'] ?? null);
            $this->applyPaid($payment, null, $result['paid_at'] ?? null);
        } elseif (in_array($result['status'], ['failed', 'expired'], true)) {
            $this->applyFailure($payment, $result['status'], null);
        }

        return [
            'status' => $payment->fresh()->status,
            'payment' => $this->subscriptions->paymentPayload($payment->fresh()),
        ];
    }

    /** An administrator has verified the bank transfer and settles it. */
    public function confirmManualPayment(SubscriptionPayment $payment, User $actor): array
    {
        if ($payment->provider !== 'manual') {
            throw new SubscriptionException(
                'Only bank-transfer payments can be confirmed manually.',
                'PAYMENT_NOT_MANUAL',
                422
            );
        }

        if (! $payment->isPaid()) {
            $this->applyPaid($payment, $actor, now()->toIso8601String());
        }

        return [
            'status' => 'paid',
            'payment' => $this->subscriptions->paymentPayload($payment->fresh()),
        ];
    }

    /** Local-only sandbox completion; guarded by SandboxGateway::isConfigured. */
    public function completeSandbox(SubscriptionPayment $payment, User $actor): array
    {
        $this->gateways->gateway('sandbox');

        if ($payment->provider !== 'sandbox') {
            throw new SubscriptionException('Not a sandbox payment.', 'PAYMENT_NOT_SANDBOX', 422);
        }

        if (! $payment->isPaid()) {
            $this->applyPaid($payment, $actor, now()->toIso8601String());
        }

        return [
            'status' => 'paid',
            'payment' => $this->subscriptions->paymentPayload($payment->fresh()),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Transitions */
    /* ------------------------------------------------------------------ */

    private function applyPaid(SubscriptionPayment $payment, ?User $actor = null, ?string $paidAt = null): void
    {
        DB::transaction(function () use ($payment, $actor, $paidAt) {
            $locked = SubscriptionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                return;
            }

            $invoice = SubscriptionInvoice::whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();

            if ($invoice->isPaid()) {
                return;
            }

            $locked->fill([
                'status' => SubscriptionPayment::STATUS_PAID,
                'paid_at' => $paidAt ? Carbon::parse($paidAt) : now(),
                'failure_reason' => null,
            ]);
            $locked->save();

            $invoice->fill([
                'status' => SubscriptionInvoice::STATUS_PAID,
                'amount_paid' => (int) $invoice->total,
                'paid_at' => $locked->paid_at,
            ]);
            $invoice->save();

            $subscription = $locked->subscription
                ?? $this->subscriptions->forBusiness($invoice->business_id);

            $this->subscriptions->activatePaid(
                $subscription,
                $invoice->plan,
                $invoice->billing_period,
                $locked,
                $actor
            );

            $this->subscriptions->history(
                $subscription->fresh(),
                'payment_succeeded',
                null,
                Subscription::STATUS_ACTIVE,
                $invoice->plan_id,
                $invoice->plan_id,
                $actor,
                ['payment_id' => $locked->id, 'invoice_id' => $invoice->id, 'amount' => (int) $invoice->total]
            );
        });
    }

    private function applyFailure(SubscriptionPayment $payment, string $status, ?string $reason): void
    {
        $payment->update([
            'status' => $status === 'expired'
                ? SubscriptionPayment::STATUS_EXPIRED
                : SubscriptionPayment::STATUS_FAILED,
            'failure_reason' => $reason ? Str::limit($reason, 250) : null,
        ]);

        $subscription = $payment->subscription ?? $this->subscriptions->forBusiness($payment->business_id);

        // Only a failed renewal for the current paid-through period should
        // move the workspace to past_due. A failed upgrade attempt must not
        // disturb a healthy subscription.
        $invoice = $payment->invoice;
        $isRenewal = $invoice && $invoice->plan_id === $subscription->plan_id
            && $subscription->ends_at
            && $subscription->ends_at->lessThanOrEqualTo(now()->addDay());

        if ($isRenewal && in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            $this->subscriptions->markPastDue($subscription, $reason);
        }
    }

    private function applyRefund(SubscriptionPayment $payment): void
    {
        $payment->update(['status' => SubscriptionPayment::STATUS_REFUNDED]);

        if ($payment->invoice) {
            $payment->invoice->update([
                'status' => SubscriptionInvoice::STATUS_UNCOLLECTIBLE,
                'amount_paid' => 0,
            ]);
        }
    }

    private function assertAmount(SubscriptionPayment $payment, ?int $amount): void
    {
        if ($amount === null) {
            return;
        }

        if ((int) $amount !== (int) $payment->amount) {
            throw new SubscriptionException(
                'The provider amount does not match the invoice total.',
                'PAYMENT_AMOUNT_MISMATCH',
                422,
                ['expected' => (int) $payment->amount, 'received' => (int) $amount]
            );
        }
    }

    private function checkoutPayload(SubscriptionPayment $payment, ?array $checkout = null): array
    {
        $payment->loadMissing('invoice.plan');
        $raw = $payment->raw_payload ?? [];

        return [
            'invoice' => $this->subscriptions->invoicePayload($payment->invoice),
            'payment' => $this->subscriptions->paymentPayload($payment),
            'checkout' => [
                'provider' => $payment->provider,
                'status' => $payment->status,
                'redirect_url' => $checkout['redirect_url'] ?? ($raw['redirect_url'] ?? null),
                'instructions' => $checkout['instructions'] ?? ($raw['instructions'] ?? null),
                'reference' => $payment->provider_reference,
            ],
        ];
    }

    private function nextInvoiceNumber(int $businessId): string
    {
        do {
            $number = 'INV-'.now()->format('Ym').'-'.$businessId.'-'.strtoupper(Str::random(6));
        } while (SubscriptionInvoice::where('number', $number)->exists());

        return $number;
    }
}

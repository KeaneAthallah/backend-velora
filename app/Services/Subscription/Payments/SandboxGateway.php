<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;

/**
 * Local-only sandbox used to exercise the full checkout UI without real money.
 *
 * It is disabled by default, requires SUBSCRIPTION_SANDBOX_ENABLED=true, and
 * refuses to run outside the local/testing environments. Completion still goes
 * through the same server-side "mark paid" path as a verified webhook; the
 * frontend can never activate a subscription itself.
 */
class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function isConfigured(): bool
    {
        return (bool) config('subscriptions.sandbox.enabled', false)
            && app()->environment(['local', 'testing']);
    }

    public function createCheckout(SubscriptionInvoice $invoice, array $options = []): array
    {
        $this->ensureConfigured();

        return [
            'reference' => 'SBX-'.$invoice->number,
            'status' => 'pending',
            'redirect_url' => null,
            'instructions' => 'Sandbox mode: complete this payment from the subscription page. No real money moves.',
            'raw' => ['sandbox' => true],
        ];
    }

    public function verifyWebhook(Request $request): array
    {
        throw new SubscriptionException('The sandbox does not use webhooks.', 'WEBHOOK_NOT_SUPPORTED', 422);
    }

    public function fetchStatus(SubscriptionPayment $payment): array
    {
        return ['status' => 'pending', 'amount' => (int) $payment->amount, 'raw' => []];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SubscriptionException(
                'The payment sandbox is disabled.',
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                503
            );
        }
    }
}

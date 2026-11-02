<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;

/**
 * Bank transfer. No money moves through Velora; an administrator confirms the
 * settlement after checking the bank statement. This is deliberately the only
 * provider that is enabled without credentials.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function isConfigured(): bool
    {
        return (bool) config('subscriptions.providers.manual.enabled', true);
    }

    public function createCheckout(SubscriptionInvoice $invoice, array $options = []): array
    {
        $reference = 'MAN-'.$invoice->number;

        return [
            'reference' => $reference,
            'status' => 'pending',
            'redirect_url' => null,
            'instructions' => $this->instructions($invoice),
            'raw' => ['reference' => $reference],
        ];
    }

    public function verifyWebhook(Request $request): array
    {
        throw new SubscriptionException(
            'Bank transfers are confirmed by an administrator, not by a webhook.',
            'WEBHOOK_NOT_SUPPORTED',
            422
        );
    }

    public function fetchStatus(SubscriptionPayment $payment): array
    {
        return ['status' => 'pending', 'amount' => (int) $payment->amount, 'raw' => []];
    }

    private function instructions(SubscriptionInvoice $invoice): string
    {
        $provider = config('subscriptions.providers.manual', []);
        $lines = [$provider['instructions'] ?? 'Transfer the invoice total and reply with the reference.'];

        if (filled($provider['bank_name'] ?? null)) {
            $lines[] = 'Bank: '.$provider['bank_name'];
        }
        if (filled($provider['account_name'] ?? null)) {
            $lines[] = 'Account name: '.$provider['account_name'];
        }
        if (filled($provider['account_number'] ?? null)) {
            $lines[] = 'Account number: '.$provider['account_number'];
        }

        $lines[] = 'Reference: MAN-'.$invoice->number;

        return implode("\n", $lines);
    }
}

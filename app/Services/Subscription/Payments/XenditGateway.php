<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class XenditGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'xendit';
    }

    public function isConfigured(): bool
    {
        return (bool) config('subscriptions.providers.xendit.enabled', false)
            && filled(config('subscriptions.providers.xendit.secret_key'));
    }

    public function createCheckout(SubscriptionInvoice $invoice, array $options = []): array
    {
        $this->ensureConfigured();

        $invoice->loadMissing('plan');
        $payload = [
            'external_id' => $invoice->number,
            'amount' => (int) $invoice->total,
            'currency' => $invoice->currency,
            'description' => 'Velora '.($invoice->plan?->name ?? 'subscription').' ('.$invoice->billing_period.')',
            'invoice_duration' => 86400,
            'success_redirect_url' => config('subscriptions.checkout_return_url'),
            'failure_redirect_url' => config('subscriptions.checkout_return_url'),
        ];

        if (! empty($options['customer']['email'])) {
            $payload['payer_email'] = $options['customer']['email'];
        }

        $response = Http::withBasicAuth(config('subscriptions.providers.xendit.secret_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->post(config('subscriptions.providers.xendit.invoice_url'), $payload);

        if (! $response->successful()) {
            throw new SubscriptionException(
                'Xendit could not start the checkout. '.$response->json('message', 'Try again shortly.'),
                'PAYMENT_PROVIDER_ERROR',
                502
            );
        }

        return [
            'reference' => $invoice->number,
            'status' => 'pending',
            'redirect_url' => $response->json('invoice_url'),
            'instructions' => null,
            'raw' => [
                'provider_invoice_id' => $response->json('id'),
                'invoice_url' => $response->json('invoice_url'),
            ],
        ];
    }

    public function verifyWebhook(Request $request): array
    {
        $this->ensureConfigured();

        $token = (string) $request->header('x-callback-token', '');
        $expected = (string) config('subscriptions.providers.xendit.callback_token', '');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            throw new SubscriptionException('Xendit callback token verification failed.', 'INVALID_SIGNATURE', 401);
        }

        $externalId = (string) $request->input('external_id', '');
        if ($externalId === '') {
            throw new SubscriptionException('Invalid Xendit callback.', 'INVALID_WEBHOOK', 400);
        }

        $rawStatus = strtoupper((string) $request->input('status', ''));
        $status = match ($rawStatus) {
            'PAID', 'SETTLED' => 'paid',
            'PENDING' => 'pending',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'pending',
        };

        $eventId = (string) ($request->input('id') ?: $externalId.':'.$rawStatus);

        return [
            'event_id' => $eventId,
            'event_type' => $rawStatus,
            'reference' => $externalId,
            'status' => $status,
            'amount' => is_numeric($request->input('amount')) ? (int) round((float) $request->input('amount')) : null,
            'paid_at' => $status === 'paid' ? ($request->input('paid_at') ?: now()->toIso8601String()) : null,
            'raw' => $request->all(),
        ];
    }

    public function fetchStatus(SubscriptionPayment $payment): array
    {
        $this->ensureConfigured();

        $providerId = $payment->raw_payload['provider_invoice_id'] ?? $payment->provider_reference;
        $response = Http::withBasicAuth(config('subscriptions.providers.xendit.secret_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.xendit.co/v2/invoices/'.$providerId);

        if (! $response->successful()) {
            throw new SubscriptionException('Xendit status could not be read.', 'PAYMENT_PROVIDER_ERROR', 502);
        }

        $rawStatus = strtoupper((string) $response->json('status', ''));
        $status = match ($rawStatus) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'pending',
        };

        return [
            'status' => $status,
            'amount' => is_numeric($response->json('amount')) ? (int) round((float) $response->json('amount')) : null,
            'paid_at' => $status === 'paid' ? now()->toIso8601String() : null,
            'raw' => $response->json() ?? [],
        ];
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SubscriptionException(
                'Xendit is not configured. Set XENDIT_ENABLED, XENDIT_SECRET_KEY and XENDIT_CALLBACK_TOKEN.',
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                503
            );
        }
    }
}

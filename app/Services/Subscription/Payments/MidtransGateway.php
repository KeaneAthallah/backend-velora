<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MidtransGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'midtrans';
    }

    public function isConfigured(): bool
    {
        return (bool) config('subscriptions.providers.midtrans.enabled', false)
            && filled(config('subscriptions.providers.midtrans.server_key'))
            && filled(config('subscriptions.providers.midtrans.client_key'));
    }

    public function createCheckout(SubscriptionInvoice $invoice, array $options = []): array
    {
        $this->ensureConfigured();

        $invoice->loadMissing('plan');
        $payload = [
            'transaction_details' => [
                'order_id' => $invoice->number,
                'gross_amount' => (int) $invoice->total,
            ],
            'item_details' => [[
                'id' => $invoice->plan?->slug ?? 'subscription',
                'price' => (int) $invoice->total,
                'quantity' => 1,
                'name' => 'Velora '.($invoice->plan?->name ?? 'subscription').' ('.$invoice->billing_period.')',
            ]],
            'callbacks' => [
                'finish' => config('subscriptions.checkout_return_url'),
            ],
        ];

        if (! empty($options['customer'])) {
            $payload['customer_details'] = $options['customer'];
        }

        $response = Http::withBasicAuth(config('subscriptions.providers.midtrans.server_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->post(config('subscriptions.providers.midtrans.snap_url'), $payload);

        if (! $response->successful()) {
            throw new SubscriptionException(
                'Midtrans could not start the checkout. '.$response->json('error_messages.0', 'Try again shortly.'),
                'PAYMENT_PROVIDER_ERROR',
                502
            );
        }

        return [
            'reference' => $invoice->number,
            'status' => 'pending',
            'redirect_url' => $response->json('redirect_url'),
            'instructions' => null,
            'raw' => [
                'token' => $response->json('token'),
                'redirect_url' => $response->json('redirect_url'),
            ],
        ];
    }

    public function verifyWebhook(Request $request): array
    {
        $this->ensureConfigured();

        $orderId = (string) $request->input('order_id', '');
        $statusCode = (string) $request->input('status_code', '');
        $grossAmount = (string) $request->input('gross_amount', '');
        $signature = (string) $request->input('signature_key', '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
            throw new SubscriptionException('Invalid Midtrans notification.', 'INVALID_WEBHOOK', 400);
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.config('subscriptions.providers.midtrans.server_key'));

        if (! hash_equals($expected, $signature)) {
            throw new SubscriptionException('Midtrans signature verification failed.', 'INVALID_SIGNATURE', 401);
        }

        $transactionStatus = (string) $request->input('transaction_status', '');
        $fraudStatus = (string) $request->input('fraud_status', 'accept');
        $status = $this->mapStatus($transactionStatus, $fraudStatus);
        $eventId = (string) ($request->input('transaction_id') ?: $orderId.':'.$transactionStatus);

        return [
            'event_id' => $eventId,
            'event_type' => $transactionStatus,
            'reference' => $orderId,
            'status' => $status,
            'amount' => is_numeric($grossAmount) ? (int) round((float) $grossAmount) : null,
            'paid_at' => $status === 'paid' ? now()->toIso8601String() : null,
            'raw' => $request->all(),
        ];
    }

    public function fetchStatus(SubscriptionPayment $payment): array
    {
        $this->ensureConfigured();

        $base = rtrim((string) config('subscriptions.providers.midtrans.api_url'), '/');
        $response = Http::withBasicAuth(config('subscriptions.providers.midtrans.server_key'), '')
            ->acceptJson()
            ->timeout(20)
            ->get($base.'/'.$payment->provider_reference.'/status');

        if (! $response->successful()) {
            throw new SubscriptionException('Midtrans status could not be read.', 'PAYMENT_PROVIDER_ERROR', 502);
        }

        $status = $this->mapStatus(
            (string) $response->json('transaction_status', ''),
            (string) $response->json('fraud_status', 'accept')
        );

        return [
            'status' => $status,
            'amount' => is_numeric($response->json('gross_amount')) ? (int) round((float) $response->json('gross_amount')) : null,
            'paid_at' => $status === 'paid' ? now()->toIso8601String() : null,
            'raw' => $response->json() ?? [],
        ];
    }

    private function mapStatus(string $transactionStatus, string $fraudStatus): string
    {
        return match ($transactionStatus) {
            'settlement' => 'paid',
            'capture' => $fraudStatus === 'challenge' ? 'pending' : 'paid',
            'pending' => 'pending',
            'deny', 'cancel', 'failure' => 'failed',
            'expire' => 'expired',
            'refund', 'partial_refund' => 'refunded',
            default => 'pending',
        };
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new SubscriptionException(
                'Midtrans is not configured. Set MIDTRANS_ENABLED, MIDTRANS_SERVER_KEY and MIDTRANS_CLIENT_KEY.',
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                503
            );
        }
    }
}

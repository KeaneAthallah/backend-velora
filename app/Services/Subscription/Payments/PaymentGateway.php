<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Start a provider-side checkout.
     *
     * @return array{reference: string, status: string, redirect_url?: ?string, instructions?: ?string, raw?: array}
     */
    public function createCheckout(SubscriptionInvoice $invoice, array $options = []): array;

    /**
     * Verify the provider signature and normalise the event.
     *
     * @return array{event_id: string, event_type: string, reference: string, status: string, amount?: ?int, paid_at?: ?string, raw: array}
     *
     * @throws SubscriptionException
     */
    public function verifyWebhook(Request $request): array;

    /**
     * Ask the provider for the authoritative status of a payment.
     *
     * @return array{status: string, amount?: ?int, paid_at?: ?string, raw?: array}
     */
    public function fetchStatus(SubscriptionPayment $payment): array;
}

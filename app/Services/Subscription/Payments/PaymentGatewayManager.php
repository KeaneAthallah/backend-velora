<?php

namespace App\Services\Subscription\Payments;

use App\Exceptions\SubscriptionException;

class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(
        ManualGateway $manual,
        MidtransGateway $midtrans,
        XenditGateway $xendit,
        SandboxGateway $sandbox,
    ) {
        $this->gateways = [
            'manual' => $manual,
            'midtrans' => $midtrans,
            'xendit' => $xendit,
            'sandbox' => $sandbox,
        ];
    }

    public function gateway(string $provider): PaymentGateway
    {
        $gateway = $this->gateways[$provider] ?? null;

        if (! $gateway) {
            throw new SubscriptionException('Unknown payment provider.', 'UNKNOWN_PAYMENT_PROVIDER', 422);
        }

        if (! $gateway->isConfigured()) {
            throw new SubscriptionException(
                $gateway->name().' is not configured on this server.',
                'PAYMENT_PROVIDER_NOT_CONFIGURED',
                503
            );
        }

        return $gateway;
    }

    /** Providers the UI may offer for this deployment. */
    public function available(): array
    {
        $labels = [
            'manual' => 'Bank transfer',
            'midtrans' => 'Midtrans',
            'xendit' => 'Xendit',
            'sandbox' => 'Sandbox (local only)',
        ];

        $result = [];
        foreach ($this->gateways as $name => $gateway) {
            if (! $gateway->isConfigured()) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'label' => $labels[$name] ?? ucfirst($name),
                'sandbox' => $name === 'sandbox',
            ];
        }

        return $result;
    }
}

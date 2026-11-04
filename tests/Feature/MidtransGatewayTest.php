<?php

use App\Models\SubscriptionInvoice;
use App\Services\Subscription\Payments\MidtransGateway;
use Illuminate\Support\Facades\Http;

function midtransGatewayWithConfig(array $overrides = []): MidtransGateway
{
    config(array_merge([
        'subscriptions.providers.midtrans.enabled' => true,
        'subscriptions.providers.midtrans.server_key' => 'server-key',
        'subscriptions.providers.midtrans.client_key' => 'client-key',
        'subscriptions.providers.midtrans.notification_url' => '',
    ], $overrides));

    return new MidtransGateway;
}

function midtransProbeInvoice(): SubscriptionInvoice
{
    return new SubscriptionInvoice([
        'number' => 'VELORA-PROBE-1',
        'total' => 1000,
        'billing_period' => 'monthly',
        'currency' => 'IDR',
    ]);
}

test('midtrans checkout overrides the notification url when one is configured', function () {
    Http::fake(['*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://snap.test/redirect'], 201)]);

    $gateway = midtransGatewayWithConfig([
        'subscriptions.providers.midtrans.notification_url' => 'https://tunnel.trycloudflare.com/api/v1/subscription/webhooks/midtrans',
    ]);

    $result = $gateway->createCheckout(midtransProbeInvoice());

    expect($result['redirect_url'])->toBe('https://snap.test/redirect');

    Http::assertSent(fn ($request) => $request->hasHeader(
        'X-Override-Notification',
        'https://tunnel.trycloudflare.com/api/v1/subscription/webhooks/midtrans'
    ));
});

test('midtrans checkout leaves notifications to the dashboard without an override', function () {
    Http::fake(['*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://snap.test/redirect'], 201)]);

    midtransGatewayWithConfig()->createCheckout(midtransProbeInvoice());

    Http::assertSent(fn ($request) => ! $request->hasHeader('X-Override-Notification'));
});

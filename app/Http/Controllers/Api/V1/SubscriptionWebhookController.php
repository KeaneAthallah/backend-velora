<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscription\Payments\BillingService;
use Illuminate\Http\Request;

class SubscriptionWebhookController extends Controller
{
    public function __construct(protected BillingService $billing) {}

    /**
     * Public provider callback. The signature is verified inside the gateway
     * before any record is touched, and the event id is unique per provider so
     * a replayed callback cannot charge or extend a subscription twice.
     */
    public function handle(Request $request, string $provider)
    {
        $result = $this->billing->handleWebhook($provider, $request);

        return $this->success($result, 'Webhook received');
    }
}

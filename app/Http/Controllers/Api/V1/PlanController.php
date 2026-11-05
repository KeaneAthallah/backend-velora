<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Subscription\Payments\PaymentGatewayManager;
use App\Services\Subscription\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct(
        protected PlanService $plans,
        protected PaymentGatewayManager $gateways,
    ) {}

    /** Public pricing payload: no auth, no tenant context. */
    public function index(Request $request)
    {
        $industry = $request->query('industry');
        $payload = $this->plans->pricingPayload($industry);
        $payload['providers'] = $this->gateways->available();

        return $this->success($payload);
    }

    public function show(Request $request, string $slug)
    {
        $plan = $this->plans->findBySlug($slug);
        if (! $plan) {
            return $this->error('Plan not found', 404, [], 'PLAN_NOT_FOUND');
        }

        return $this->success($plan->toApiArray());
    }
}

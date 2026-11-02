<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\Subscription\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscriptionFeature
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next, string $feature)
    {
        $user = $request->user();
        if ($user?->is_platform_admin) {
            return $next($request);
        }

        $business = $request->attributes->get('business');
        if (! $business instanceof Business) {
            return $next($request);
        }

        $this->subscriptions->assertFeature($business, $feature);

        return $next($request);
    }
}

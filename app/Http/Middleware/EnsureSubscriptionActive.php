<?php

namespace App\Http\Middleware;

use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Services\Subscription\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks writes for a business whose subscription has ended, while leaving
 * reads, billing, and platform administration available. This is the safety
 * net: individual controllers also enforce quotas, but a route added later
 * cannot accidentally let an expired tenant keep writing.
 */
class EnsureSubscriptionActive
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user?->is_platform_admin) {
            return $next($request);
        }

        $business = $request->attributes->get('business');
        if (! $business instanceof Business) {
            return $next($request);
        }

        // Billing must remain reachable so an expired tenant can pay.
        $path = trim($request->path(), '/');
        if (str_starts_with($path, 'api/v1/subscription') || str_starts_with($path, 'api/v1/admin')) {
            return $next($request);
        }

        $subscription = $this->subscriptions->forBusiness($business);

        if (! $subscription->grantsAccess()) {
            throw new SubscriptionException(
                'Your subscription has ended. Upgrade or renew to keep using this feature.',
                'SUBSCRIPTION_REQUIRED',
                402,
                [
                    'status' => $subscription->effectiveStatus(),
                    'current_plan' => $subscription->plan?->slug,
                    'current_plan_name' => $subscription->plan?->name,
                    'ended_at' => $subscription->ends_at?->toIso8601String(),
                ]
            );
        }

        return $next($request);
    }
}

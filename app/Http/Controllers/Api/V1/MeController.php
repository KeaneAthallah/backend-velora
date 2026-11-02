<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    public function show(Request $request)
    {
        $user = $request->user()->load('businessMemberships.business', 'businessMemberships.role.permissions', 'outletMemberships.outlet');

        $business = $this->activeBusiness($request, $user);
        if ($business) {
            $user->setAttribute('subscription', $this->subscriptions->summary($business));
        }

        return $this->success($user);
    }

    /**
     * Resolve the business the client is currently looking at. `/me` sits
     * outside the tenant middleware, so this mirrors its header rule without
     * requiring a membership row for platform administrators.
     */
    private function activeBusiness(Request $request, $user): ?Business
    {
        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');

        if ($businessId) {
            if ($user->is_platform_admin) {
                return Business::find($businessId);
            }

            $member = $user->businessMemberships->first(
                fn (BusinessMembership $membership) => (string) $membership->business_id === (string) $businessId
                    && $membership->status !== 'inactive'
            );

            return $member?->business;
        }

        $membership = $user->businessMemberships->first(
            fn (BusinessMembership $membership) => $membership->status !== 'inactive'
        );

        return $membership?->business;
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Outlet;
use App\Models\OutletMembership;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated', 'code' => 'UNAUTHENTICATED'], 401);
        }

        if ($user->is_platform_admin) {
            $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');
            if ($businessId) {
                $business = Business::find($businessId);
                if (! $business) {
                    return response()->json(['success' => false, 'message' => 'Business not found', 'code' => 'BUSINESS_NOT_FOUND'], 404);
                }
                $request->attributes->set('business', $business);
                $request->attributes->set('business_id', $business->id);
            }
            $outletId = $request->header('X-Outlet-Id') ?? $request->input('outlet_id');
            if ($outletId) {
                $request->attributes->set('outlet_id', (int) $outletId);
            }

            return $next($request);
        }

        $businessId = $request->header('X-Business-Id') ?? $request->input('business_id');
        if (! $businessId) {
            // try to infer from user's single membership
            $memberships = $user->businessMemberships()->where('status', 'active')->get();
            if ($memberships->count() === 1) {
                $businessId = $memberships->first()->business_id;
            }
        }

        if (! $businessId) {
            return response()->json(['success' => false, 'message' => 'Business context required', 'code' => 'BUSINESS_CONTEXT_REQUIRED'], 422);
        }

        $membership = BusinessMembership::where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['success' => false, 'message' => 'Access to business denied', 'code' => 'BUSINESS_ACCESS_DENIED'], 403);
        }

        $business = Business::find($businessId);
        if (! $business || $business->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Business not available', 'code' => 'BUSINESS_UNAVAILABLE'], 403);
        }

        $request->attributes->set('business', $business);
        $request->attributes->set('business_id', $business->id);
        $request->attributes->set('membership', $membership);

        $outletId = $request->header('X-Outlet-Id') ?? $request->input('outlet_id');
        if ($outletId) {
            // verify outlet belongs to business
            $outlet = Outlet::where('id', $outletId)->where('business_id', $business->id)->first();
            if (! $outlet) {
                return response()->json(['success' => false, 'message' => 'Outlet not found in business', 'code' => 'OUTLET_NOT_FOUND'], 404);
            }
            // verify user outlet membership if not admin
            $role = $membership->role;
            $isAdmin = $role && in_array($role->slug, ['owner', 'admin']);
            if (! $isAdmin) {
                $om = OutletMembership::where('user_id', $user->id)->where('outlet_id', $outlet->id)->first();
                if (! $om) {
                    return response()->json(['success' => false, 'message' => 'Outlet access denied', 'code' => 'OUTLET_ACCESS_DENIED'], 403);
                }
            }
            $request->attributes->set('outlet', $outlet);
            $request->attributes->set('outlet_id', $outlet->id);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Services\AuthorizationService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;

class OutletController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'outlets.view', $businessId);

        return $this->success(Outlet::where('business_id', $businessId)->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'outlets.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:64',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'timezone' => 'nullable|string|max:64',
            'settings' => 'nullable|array',
        ]);
        $this->subscriptions->enforceLimit($businessId, 'outlets');
        $outlet = Outlet::create(array_merge($data, ['business_id' => $businessId, 'status' => 'active']));

        return $this->success($outlet, 'Outlet created', 201);
    }

    public function show(Request $request, Outlet $outlet)
    {
        $businessId = $request->attributes->get('business_id');
        if ($outlet->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'outlets.view', $businessId);

        return $this->success($outlet);
    }

    public function update(Request $request, Outlet $outlet)
    {
        $businessId = $request->attributes->get('business_id');
        if ($outlet->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'outlets.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:64',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'timezone' => 'nullable|string|max:64',
            'status' => 'sometimes|in:active,inactive,archived',
            'settings' => 'nullable|array',
        ]);
        $outlet->update($data);

        return $this->success($outlet);
    }

    public function destroy(Request $request, Outlet $outlet)
    {
        $businessId = $request->attributes->get('business_id');
        if ($outlet->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'outlets.manage', $businessId);
        $outlet->delete();

        return $this->success(null, 'Deleted');
    }
}

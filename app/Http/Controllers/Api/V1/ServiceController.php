<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'services.view', $businessId);

        return $this->success(Service::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'services.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:64',
            'duration_minutes' => 'nullable|integer|min:1',
            'price' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $service = Service::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($service, 'Service created', 201);
    }

    public function update(Request $request, Service $service)
    {
        $businessId = $request->attributes->get('business_id');
        if ($service->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'services.manage', $businessId);
        $service->update($request->only(['name', 'category', 'duration_minutes', 'price', 'description', 'is_active']));

        return $this->success($service);
    }

    public function destroy(Request $request, Service $service)
    {
        $businessId = $request->attributes->get('business_id');
        if ($service->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'services.manage', $businessId);
        $service->delete();

        return $this->success(null, 'Deleted');
    }
}

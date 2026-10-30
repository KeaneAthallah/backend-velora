<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'devices.view', $businessId);

        return $this->success(Device::where('business_id', $businessId)->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'devices.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'device_uuid' => 'required|string|max:128|unique:devices,device_uuid',
            'type' => 'sometimes|in:pos,web,mobile,api',
            'outlet_id' => 'nullable|integer',
        ]);
        $device = Device::create(array_merge($data, ['business_id' => $businessId, 'status' => 'active']));

        return $this->success($device, 'Created', 201);
    }

    public function show(Request $request, Device $device)
    {
        $businessId = $request->attributes->get('business_id');
        if ($device->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }

        return $this->success($device);
    }

    public function update(Request $request, Device $device)
    {
        $businessId = $request->attributes->get('business_id');
        if ($device->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'devices.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,revoked,inactive',
            'outlet_id' => 'nullable|integer',
        ]);
        $device->update($data);

        return $this->success($device);
    }

    public function destroy(Request $request, Device $device)
    {
        $businessId = $request->attributes->get('business_id');
        if ($device->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'devices.manage', $businessId);
        $device->delete();

        return $this->success(null, 'Deleted');
    }
}

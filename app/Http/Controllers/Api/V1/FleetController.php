<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    /* Zones -------------------------------------------------------------- */

    public function zones(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.view', $businessId);

        return $this->success(
            DeliveryZone::where('business_id', $businessId)
                ->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->input('outlet_id')))
                ->orderBy('name')
                ->get()
        );
    }

    public function storeZone(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $businessId);
        $data = $this->validateZone($request, $businessId);

        return $this->success(DeliveryZone::create(array_merge($data, ['business_id' => $businessId])), 'Delivery zone created', 201);
    }

    public function updateZone(Request $request, DeliveryZone $deliveryZone)
    {
        $this->guard($request, $deliveryZone->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $deliveryZone->business_id);
        $deliveryZone->update($this->validateZone($request, $deliveryZone->business_id, true));

        return $this->success($deliveryZone->fresh());
    }

    public function destroyZone(Request $request, DeliveryZone $deliveryZone)
    {
        $this->guard($request, $deliveryZone->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $deliveryZone->business_id);
        $deliveryZone->delete();

        return $this->success(null, 'Delivery zone deleted');
    }

    /* Drivers ------------------------------------------------------------ */

    public function drivers(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.view', $businessId);

        return $this->success(
            Driver::where('business_id', $businessId)
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
                ->orderBy('name')
                ->get()
        );
    }

    public function storeDriver(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $businessId);

        return $this->success(Driver::create(array_merge($this->validateDriver($request), ['business_id' => $businessId])), 'Driver created', 201);
    }

    public function updateDriver(Request $request, Driver $driver)
    {
        $this->guard($request, $driver->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $driver->business_id);
        $driver->update($this->validateDriver($request, true));

        return $this->success($driver->fresh());
    }

    public function destroyDriver(Request $request, Driver $driver)
    {
        $this->guard($request, $driver->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $driver->business_id);
        $driver->update(['is_active' => false, 'status' => 'off_duty']);

        return $this->success($driver->fresh(), 'Driver deactivated');
    }

    /* Vehicles ----------------------------------------------------------- */

    public function vehicles(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.view', $businessId);

        return $this->success(Vehicle::where('business_id', $businessId)->orderBy('plate_number')->get());
    }

    public function storeVehicle(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $businessId);

        return $this->success(Vehicle::create(array_merge($this->validateVehicle($request, $businessId), ['business_id' => $businessId])), 'Vehicle created', 201);
    }

    public function updateVehicle(Request $request, Vehicle $vehicle)
    {
        $this->guard($request, $vehicle->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $vehicle->business_id);
        $vehicle->update($this->validateVehicle($request, $vehicle->business_id, $vehicle));

        return $this->success($vehicle->fresh());
    }

    public function destroyVehicle(Request $request, Vehicle $vehicle)
    {
        $this->guard($request, $vehicle->business_id);
        $this->authz->requirePermission($request->user(), 'fleet.manage', $vehicle->business_id);
        $vehicle->update(['is_active' => false, 'status' => 'maintenance']);

        return $this->success($vehicle->fresh(), 'Vehicle deactivated');
    }

    /* ------------------------------------------------------------------ */

    private function validateZone(Request $request, int $businessId, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:120',
            'outlet_id' => [
                'nullable', 'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'code' => 'nullable|string|max:32',
            'fee' => 'nullable|numeric|min:0',
            'min_order' => 'nullable|numeric|min:0',
            'max_distance_km' => 'nullable|numeric|min:0',
            'postcodes' => 'nullable|array',
            'postcodes.*' => 'string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function validateDriver(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => $required.'|string|max:120',
            'phone' => 'nullable|string|max:32',
            'user_id' => 'nullable|integer|exists:users,id',
            'license_number' => 'nullable|string|max:64',
            'status' => 'nullable|in:available,on_delivery,off_duty',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ]);
    }

    private function validateVehicle(Request $request, int $businessId, ?Vehicle $vehicle = null): array
    {
        $required = $vehicle ? 'sometimes' : 'required';

        return $request->validate([
            'plate_number' => [
                $required, 'string', 'max:32',
                Rule::unique('vehicles', 'plate_number')
                    ->where('business_id', $businessId)
                    ->ignore($vehicle?->id),
            ],
            'type' => 'nullable|in:motorcycle,car,van,truck',
            'model' => 'nullable|string|max:120',
            'capacity_kg' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:available,in_use,maintenance',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function guard(Request $request, int $businessId): void
    {
        if ($businessId !== $this->businessId($request)) {
            abort(404);
        }
    }

    private function businessId(Request $request): int
    {
        return (int) $request->attributes->get('business_id');
    }
}

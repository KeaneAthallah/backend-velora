<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseBin;
use App\Models\WarehouseZone;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class WarehouseZoneController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'warehouse_zones.view', $businessId);
        $zones = WarehouseZone::whereHas('warehouse', fn ($q) => $q->where('business_id', $businessId))->with('bins')->get();

        return $this->success($zones);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'warehouse_zones.manage', $businessId);
        $data = $request->validate(['warehouse_id' => 'required|integer', 'name' => 'required|string|max:255', 'code' => 'required|string|max:32']);
        if (! Warehouse::where('id', $data['warehouse_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $zone = WarehouseZone::create($data);

        return $this->success($zone, 'Zone created', 201);
    }

    public function storeBin(Request $request, WarehouseZone $zone)
    {
        $businessId = $request->attributes->get('business_id');
        if (! Warehouse::where('id', $zone->warehouse_id)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'warehouse_zones.manage', $businessId);
        $data = $request->validate(['code' => 'required|string|max:32', 'label' => 'nullable|string']);
        $bin = WarehouseBin::create(array_merge($data, ['warehouse_zone_id' => $zone->id]));

        return $this->success($bin, 'Bin created', 201);
    }
}

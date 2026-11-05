<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'warehouses.view', $businessId);

        return $this->success(Warehouse::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'warehouses.manage', $businessId);
        $data = $request->validate([
            'outlet_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:32',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);
        $w = Warehouse::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($w, 'Warehouse created', 201);
    }
}

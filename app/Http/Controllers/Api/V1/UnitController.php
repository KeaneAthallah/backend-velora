<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductUnitConversion;
use App\Models\UnitOfMeasure;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'units.view', $businessId);

        return $this->success(UnitOfMeasure::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'units.manage', $businessId);
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:255',
            'base_factor' => 'nullable|numeric|min:0',
        ]);
        $unit = UnitOfMeasure::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($unit, 'Unit created', 201);
    }

    public function convert(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'units.view', $businessId);
        $data = $request->validate([
            'product_id' => 'required|integer',
            'unit_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0',
        ]);
        $conversion = ProductUnitConversion::where('product_id', $data['product_id'])
            ->where('unit_id', $data['unit_id'])->first();
        $factor = $conversion ? (float) $conversion->factor : 1.0;
        $result = (float) $data['quantity'] * $factor;

        return $this->success(['quantity' => $data['quantity'], 'factor' => $factor, 'result' => $result]);
    }
}

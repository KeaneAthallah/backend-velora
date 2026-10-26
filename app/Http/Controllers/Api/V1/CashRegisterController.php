<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        return $this->success(CashRegister::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate(['name' => 'required|string', 'code' => 'required|string|max:64', 'outlet_id' => 'nullable|integer']);
        if (! $outletId && ! empty($data['outlet_id'])) $outletId = $data['outlet_id'];
        if (! $outletId) return $this->error('Outlet required', 422);
        $cr = CashRegister::create(['business_id' => $businessId, 'outlet_id' => $outletId, 'name' => $data['name'], 'code' => $data['code'], 'status' => 'active']);
        return $this->success($cr, 'Created', 201);
    }

    public function show(Request $request, CashRegister $cashRegister)
    {
        $businessId = $request->attributes->get('business_id');
        if ($cashRegister->business_id !== $businessId) return $this->error('Not found', 404);
        return $this->success($cashRegister);
    }

    public function update(Request $request, CashRegister $cashRegister)
    {
        $businessId = $request->attributes->get('business_id');
        if ($cashRegister->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate(['name' => 'sometimes|string', 'status' => 'sometimes|in:active,inactive']);
        $cashRegister->update($data);
        return $this->success($cashRegister);
    }

    public function destroy(Request $request, CashRegister $cashRegister)
    {
        $businessId = $request->attributes->get('business_id');
        if ($cashRegister->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $cashRegister->delete();
        return $this->success(null, 'Deleted');
    }
}

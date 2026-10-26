<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'suppliers.view', $businessId);
        return $this->success(Supplier::where('business_id', $businessId)->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'suppliers.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'tax_id' => 'nullable|string|max:64',
        ]);
        $s = Supplier::create(array_merge($data, ['business_id' => $businessId, 'is_active' => true]));
        return $this->success($s, 'Created', 201);
    }

    public function show(Request $request, Supplier $supplier)
    {
        $businessId = $request->attributes->get('business_id');
        if ($supplier->business_id !== $businessId) return $this->error('Not found', 404);
        return $this->success($supplier);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $businessId = $request->attributes->get('business_id');
        if ($supplier->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'suppliers.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'is_active' => 'boolean',
        ]);
        $supplier->update($data);
        return $this->success($supplier);
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        $businessId = $request->attributes->get('business_id');
        if ($supplier->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'suppliers.manage', $businessId);
        $supplier->delete();
        return $this->success(null, 'Deleted');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'customers.view', $businessId);
        $q = Customer::where('business_id', $businessId);
        if ($request->filled('search')) {
            $s = $request->input('search');
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('email', 'like', "%$s%");
            });
        }
        return $this->success($q->paginate(50));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'customers.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'loyalty_number' => 'nullable|string|max:64',
        ]);
        $c = Customer::create(array_merge($data, ['business_id' => $businessId]));
        return $this->success($c, 'Created', 201);
    }

    public function show(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) return $this->error('Not found', 404);
        return $this->success($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'customers.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
        ]);
        $customer->update($data);
        return $this->success($customer);
    }

    public function destroy(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'customers.manage', $businessId);
        $customer->delete();
        return $this->success(null, 'Deleted');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PromotionRedemption;
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
                $w->where('name', 'like', "%$s%")
                    ->orWhere('phone', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('loyalty_number', 'like', "%$s%");
            });
        }
        if ($request->boolean('loyalty_only')) {
            $q->where(function ($w) {
                $w->whereNotNull('loyalty_number')->orWhere('loyalty_points', '>', 0);
            });
        }

        $sort = $request->input('sort');
        if ($sort === 'spend') {
            $q->orderByDesc('total_spent');
        } elseif ($sort === 'recent') {
            $q->orderByDesc('last_visit_at');
        } else {
            $q->orderBy('name');
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
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'customers.view', $businessId);

        $customer->load([
            'sales' => fn ($q) => $q->where('status', '<>', 'draft')->orderByDesc('id')->limit(10),
            'loyaltyTransactions' => fn ($q) => $q->orderByDesc('id')->limit(20),
        ]);

        return $this->success(array_merge($customer->toArray(), [
            'promotions_used' => PromotionRedemption::where('business_id', $businessId)
                ->where('customer_id', $customer->id)
                ->count(),
        ]));
    }

    public function update(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'customers.manage', $businessId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'loyalty_number' => 'nullable|string|max:64',
        ]);

        $customer->update($data);

        return $this->success($customer->fresh());
    }

    public function destroy(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'customers.manage', $businessId);

        $customer->delete();

        return $this->success(null, 'Deleted');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');

        return $this->success(Discount::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'sometimes|in:percentage,fixed',
            'value' => 'required|numeric',
            'scope' => 'sometimes|in:product,transaction',
            'min_purchase' => 'nullable|numeric',
            'max_discount' => 'nullable|numeric',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'is_active' => 'boolean',
            'requires_approval' => 'boolean',
        ]);
        $d = Discount::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($d, 'Created', 201);
    }

    public function update(Request $request, Discount $discount)
    {
        $businessId = $request->attributes->get('business_id');
        if ($discount->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate(['name' => 'sometimes|string', 'value' => 'sometimes|numeric', 'is_active' => 'boolean']);
        $discount->update($data);

        return $this->success($discount);
    }

    public function destroy(Request $request, Discount $discount)
    {
        $businessId = $request->attributes->get('business_id');
        if ($discount->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $discount->delete();

        return $this->success(null, 'Deleted');
    }
}

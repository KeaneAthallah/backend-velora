<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Services\AuthorizationService;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected LoyaltyService $loyalty,
    ) {}

    public function show(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'loyalty.view', $businessId);

        // A business that never configured loyalty still needs the earning and
        // redemption rates the register uses, so the defaults are materialised.
        return $this->success($this->loyalty->program($businessId));
    }

    public function update(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'loyalty.manage', $businessId);

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'points_per_amount' => 'sometimes|numeric|min:0',
            'redeem_value' => 'sometimes|numeric|min:0',
            'min_redeem_points' => 'sometimes|numeric|min:0',
        ]);

        $program = $this->loyalty->updateProgram($businessId, $data);

        return $this->success($program, 'Loyalty programme updated');
    }

    public function transactions(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'loyalty.view', $businessId);

        $query = LoyaltyTransaction::where('business_id', $businessId)->with('customer:id,name,phone');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }
        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->input('sale_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->success($query->orderByDesc('id')->paginate(50));
    }

    public function adjust(Request $request, Customer $customer)
    {
        $businessId = $request->attributes->get('business_id');
        if ($customer->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'loyalty.manage', $businessId);

        $data = $request->validate([
            'points' => 'required|numeric|not_in:0',
            'reason' => 'required|string|max:255',
        ]);

        try {
            $customer = $this->loyalty->adjust(
                $customer,
                (string) $data['points'],
                $data['reason'],
                $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'LOYALTY_ERROR');
        }

        return $this->success($customer->fresh(), 'Points adjusted');
    }
}

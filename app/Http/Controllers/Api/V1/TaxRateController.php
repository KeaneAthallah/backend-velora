<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class TaxRateController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');

        return $this->success(TaxRate::where('business_id', $businessId)->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric',
            'type' => 'sometimes|in:inclusive,exclusive',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);
        $tr = TaxRate::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($tr, 'Created', 201);
    }

    public function update(Request $request, TaxRate $taxRate)
    {
        $businessId = $request->attributes->get('business_id');
        if ($taxRate->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $data = $request->validate(['name' => 'sometimes|string', 'rate' => 'sometimes|numeric', 'type' => 'sometimes|in:inclusive,exclusive', 'is_default' => 'boolean', 'is_active' => 'boolean']);
        $taxRate->update($data);

        return $this->success($taxRate);
    }

    public function destroy(Request $request, TaxRate $taxRate)
    {
        $businessId = $request->attributes->get('business_id');
        if ($taxRate->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'settings.manage', $businessId);
        $taxRate->delete();

        return $this->success(null, 'Deleted');
    }
}

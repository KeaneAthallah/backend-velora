<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'accounts.view', $businessId);

        return $this->success(Account::where('business_id', $businessId)->with('parent', 'children')->orderBy('code')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'accounts.manage', $businessId);
        $data = $request->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense,cogs',
            'parent_id' => 'nullable|integer',
            'opening_balance' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);
        if (! empty($data['parent_id']) && ! Account::where('id', $data['parent_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $account = Account::create(array_merge($data, ['business_id' => $businessId]));

        return $this->success($account, 'Account created', 201);
    }

    public function update(Request $request, Account $account)
    {
        $businessId = $request->attributes->get('business_id');
        if ($account->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'accounts.manage', $businessId);
        $data = $request->validate([
            'code' => 'sometimes|string|max:32',
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:asset,liability,equity,revenue,expense,cogs',
            'parent_id' => 'nullable|integer',
            'opening_balance' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);
        $account->update($data);

        return $this->success($account);
    }

    public function destroy(Request $request, Account $account)
    {
        $businessId = $request->attributes->get('business_id');
        if ($account->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'accounts.manage', $businessId);
        if ($account->lines()->exists() || $account->children()->exists()) {
            return $this->error('Account is in use', 422);
        }
        $account->delete();

        return $this->success(null, 'Deleted');
    }
}

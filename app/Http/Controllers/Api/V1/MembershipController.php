<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.view', $businessId);
        return $this->success(BusinessMembership::where('business_id', $businessId)->with('user', 'role')->paginate(50));
    }

    public function invite(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate([
            'email' => 'required|email',
            'role_id' => 'required|exists:roles,id',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => 'integer|exists:outlets,id',
        ]);
        $user = User::firstOrCreate(['email' => $data['email']], [
            'name' => explode('@', $data['email'])[0],
            'password' => bcrypt(\Illuminate\Support\Str::random(16)),
            'status' => 'active',
        ]);
        $role = Role::where('id', $data['role_id'])->where('business_id', $businessId)->firstOrFail();
        $membership = BusinessMembership::updateOrCreate(
            ['user_id' => $user->id, 'business_id' => $businessId],
            ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]
        );
        if (! empty($data['outlet_ids'])) {
            foreach ($data['outlet_ids'] as $oid) {
                \App\Models\OutletMembership::updateOrCreate(
                    ['user_id' => $user->id, 'outlet_id' => $oid],
                    ['role_id' => $role->id]
                );
            }
        }
        return $this->success($membership->load('user', 'role'), 'Invited', 201);
    }

    public function update(Request $request, BusinessMembership $membership)
    {
        $businessId = $request->attributes->get('business_id');
        if ($membership->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate(['role_id' => 'nullable|exists:roles,id', 'status' => 'sometimes|in:active,inactive,invited']);
        if (isset($data['role_id'])) {
            $role = Role::where('id', $data['role_id'])->where('business_id', $businessId)->firstOrFail();
            $data['role_id'] = $role->id;
        }
        $membership->update($data);
        return $this->success($membership->load('user', 'role'));
    }

    public function destroy(Request $request, BusinessMembership $membership)
    {
        $businessId = $request->attributes->get('business_id');
        if ($membership->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $membership->delete();
        return $this->success(null, 'Removed');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.view', $businessId);

        return $this->success(Role::where('business_id', $businessId)->with('permissions')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:128',
            'description' => 'nullable|string',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);
        $role = Role::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        if (! empty($data['permission_ids'])) {
            $role->permissions()->sync($data['permission_ids']);
        }

        return $this->success($role->load('permissions'), 'Created', 201);
    }

    public function update(Request $request, Role $role)
    {
        $businessId = $request->attributes->get('business_id');
        if ($role->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);
        $role->update($data);
        if (isset($data['permission_ids'])) {
            $role->permissions()->sync($data['permission_ids']);
        }

        return $this->success($role->load('permissions'));
    }

    public function destroy(Request $request, Role $role)
    {
        $businessId = $request->attributes->get('business_id');
        if ($role->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'employees.manage', $businessId);
        if ($role->is_system) {
            return $this->error('Cannot delete system role', 422);
        }
        $role->delete();

        return $this->success(null, 'Deleted');
    }
}

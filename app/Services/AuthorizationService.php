<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\Role;

class AuthorizationService
{
    public function hasPermission($user, string $permission, ?int $businessId = null): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->is_platform_admin) {
            return true;
        }
        if (! $businessId) {
            $businessId = request()->attributes->get('business_id');
        }
        if (! $businessId) {
            return false;
        }
        $membership = BusinessMembership::where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->with('role.permissions')
            ->first();
        if (! $membership || ! $membership->role) {
            return false;
        }
        return $membership->role->permissions->contains('name', $permission);
    }

    public function requirePermission($user, string $permission, ?int $businessId = null): void
    {
        if (! $this->hasPermission($user, $permission, $businessId)) {
            abort(403, 'Permission denied: ' . $permission);
        }
    }
}

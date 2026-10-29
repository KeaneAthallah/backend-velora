<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\User;

class AuthorizationService
{
    /**
     * Resolve the currently authenticated user for the active request.
     *
     * Services that enforce their own permissions need the acting user; the
     * request is the single source of truth while handling an HTTP call.
     */
    public function currentUser(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

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
            abort(403, 'Permission denied: '.$permission);
        }
    }
}

<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\Request;

trait RequiresPlatformAdmin
{
    protected function requirePlatformAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_platform_admin, 403, 'Platform administrator required');

        return $user;
    }
}

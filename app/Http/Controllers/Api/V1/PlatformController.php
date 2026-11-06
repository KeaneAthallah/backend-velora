<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\PlatformService;
use App\Traits\RequiresPlatformAdmin;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    use RequiresPlatformAdmin;

    public function __construct(protected PlatformService $platform) {}

    public function overview(Request $request)
    {
        $this->requirePlatformAdmin($request);

        return $this->success($this->platform->overview());
    }

    public function tenants(Request $request)
    {
        $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:32'],
            'industry' => ['nullable', 'string', 'max:64'],
            'plan' => ['nullable', 'string', 'max:64'],
            'subscription_status' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success($this->platform->tenants($data, (int) ($data['per_page'] ?? 20)));
    }

    public function tenant(Request $request, Business $business)
    {
        $this->requirePlatformAdmin($request);

        return $this->success($this->platform->tenant($business));
    }

    public function monitoring(Request $request)
    {
        $this->requirePlatformAdmin($request);

        return $this->success($this->platform->monitoring());
    }

    public function settings(Request $request)
    {
        $this->requirePlatformAdmin($request);

        return $this->success($this->platform->settings());
    }

    public function updateSettings(Request $request)
    {
        $user = $this->requirePlatformAdmin($request);

        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $saved = $this->platform->updateSettings($data['settings'], $user->id);

        return $this->success(['settings' => $saved], 'Platform settings updated');
    }
}

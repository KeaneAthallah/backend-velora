<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected ApiKeyService $keys,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'api_keys.view', $businessId);

        $keys = ApiKey::with('creator:id,name')
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $key) => $this->keys->payload($key));

        return $this->success(['api_keys' => $keys]);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'api_keys.manage', $businessId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['nullable', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiKey::SCOPES)],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        [$key, $plain] = $this->keys->create($request->attributes->get('business'), $request->user(), $data);

        return $this->success([
            'api_key' => $this->keys->payload($key->load('creator:id,name')),
            'key' => $plain,
        ], 'API key created. Copy it now; it will not be shown again.', 201);
    }

    public function update(Request $request, ApiKey $apiKey)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'api_keys.manage', $businessId);
        abort_unless($apiKey->business_id === $businessId, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiKey::SCOPES)],
            'rate_limit' => ['sometimes', 'integer', 'min:1', 'max:6000'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $apiKey->update($data);

        return $this->success(['api_key' => $this->keys->payload($apiKey->fresh()->load('creator:id,name'))], 'API key updated');
    }

    public function destroy(Request $request, ApiKey $apiKey)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'api_keys.manage', $businessId);
        abort_unless($apiKey->business_id === $businessId, 404);

        $apiKey->update(['revoked_at' => now()]);

        return $this->success(null, 'API key revoked');
    }

    public function rotate(Request $request, ApiKey $apiKey)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'api_keys.manage', $businessId);
        abort_unless($apiKey->business_id === $businessId, 404);

        $plain = $this->keys->rotate($apiKey);

        return $this->success([
            'api_key' => $this->keys->payload($apiKey->fresh()),
            'key' => $plain,
        ], 'API key rotated. The previous secret no longer works.');
    }
}

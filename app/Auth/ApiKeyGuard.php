<?php

namespace App\Auth;

use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Machine authentication for a business's integrations.
 *
 * A key authenticates as the user who created it, so the normal permission
 * checks and tenant rules apply unchanged. The guard also pins the business
 * header, enforces the key's read/write scopes and applies its own rate limit.
 */
class ApiKeyGuard implements Guard
{
    use GuardHelpers;

    protected ?ApiKey $apiKey = null;

    /** The request this result belongs to, so tests that reuse the container
     *  still resolve a fresh key for each HTTP call. */
    protected ?Request $resolvedRequest = null;

    public function __construct(protected ApiKeyService $keys) {}

    public function user()
    {
        $request = app('request');

        if ($this->user !== null && $this->resolvedRequest === $request) {
            return $this->user;
        }

        $this->resolvedRequest = $request;
        $this->user = null;
        $this->apiKey = null;

        $plain = $request->header('X-API-Key');
        if (! $plain) {
            $bearer = $request->bearerToken();
            if ($bearer && str_starts_with($bearer, 'vlr_')) {
                $plain = $bearer;
            }
        }

        $key = $this->keys->verify($plain);
        if (! $key) {
            return null;
        }

        $limiterKey = 'api-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, $key->rate_limit))) {
            throw new ThrottleRequestsException('API key rate limit exceeded');
        }
        RateLimiter::hit($limiterKey, 60);

        $scope = $request->isMethodSafe() ? 'read' : 'write';
        if (! $key->hasScope($scope)) {
            throw new AccessDeniedHttpException($scope === 'write'
                ? 'This API key is read-only'
                : 'This API key cannot read');
        }

        $user = $key->creator;
        if (! $user) {
            return null;
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('api_key', $key);
        $request->attributes->set('business_id', $key->business_id);
        // A key is bound to one business: never let the caller widen it by
        // sending a different X-Business-Id.
        $request->headers->set('X-Business-Id', (string) $key->business_id);

        $this->apiKey = $key;

        return $this->user = $user;
    }

    public function validate(array $credentials = []): bool
    {
        return $this->keys->verify($credentials['api_key'] ?? null) !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }
}

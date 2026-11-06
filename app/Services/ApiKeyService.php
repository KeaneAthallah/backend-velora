<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * Create a key and return the plaintext exactly once. Only the sha256 hash
     * is stored, so a leaked database row cannot be replayed against the API.
     *
     * @return array{0: ApiKey, 1: string}
     */
    public function create(Business $business, ?User $user, array $data): array
    {
        $prefix = 'vlr_'.Str::lower(Str::random(8));
        $plain = $prefix.'_'.Str::random(40);

        $key = ApiKey::create([
            'business_id' => $business->id,
            'created_by' => $user?->id,
            'name' => $data['name'],
            'prefix' => $prefix,
            'key_hash' => $this->hash($plain),
            'scopes' => $data['scopes'] ?? ['read', 'write'],
            'rate_limit' => (int) ($data['rate_limit'] ?? 60),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return [$key, $plain];
    }

    /** Issue a fresh secret for an existing key row. */
    public function rotate(ApiKey $key): string
    {
        $plain = $key->prefix.'_'.Str::random(40);
        $key->update(['key_hash' => $this->hash($plain), 'revoked_at' => null]);

        return $plain;
    }

    public function verify(?string $plain): ?ApiKey
    {
        if (! $plain || ! str_starts_with($plain, 'vlr_')) {
            return null;
        }

        $parts = explode('_', $plain);
        if (count($parts) !== 3) {
            return null;
        }

        $key = ApiKey::where('prefix', $parts[0].'_'.$parts[1])->first();

        if (! $key || ! hash_equals($key->key_hash, $this->hash($plain)) || ! $key->isActive()) {
            return null;
        }

        return $key;
    }

    public function payload(ApiKey $key): array
    {
        return [
            'id' => (string) $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'rate_limit' => $key->rate_limit,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_by' => $key->creator?->name,
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}

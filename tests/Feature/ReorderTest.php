<?php

declare(strict_types=1);

use App\Models\BusinessMembership;
use App\Models\Role;
use App\Models\User;

/**
 * Regression guard for the scaffolded reorder copilot: it must load without a
 * parse/runtime error and must fail honestly (501 after RBAC) instead of a 500
 * from a missing model.
 */
function reorderContext($test, string $suffix = 'R'): array
{
    $test->flushHeaders();
    $owner = User::factory()->create();
    $token = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $token"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Reorder Biz $suffix"])
        ->json('data');
    $headers = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    // A second user with no membership in the business.
    $outsider = User::factory()->create();
    $outsiderToken = $outsider->createToken('t')->plainTextToken;

    return compact('owner', 'biz', 'headers', 'outsider', 'outsiderToken');
}

it('returns not implemented instead of erroring for an authorized owner', function () {
    $ctx = reorderContext($this);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/reorder/forecast')
        ->assertStatus(501)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'NOT_IMPLEMENTED');
});

it('enforces RBAC before reporting the reorder copilot unavailable', function () {
    $ctx = reorderContext($this);

    // The previous request authenticated the owner; drop the cached guard.
    $this->app['auth']->forgetGuards();

    // No membership at all in this business.
    $this->withHeaders([
        'Authorization' => "Bearer {$ctx['outsiderToken']}",
        'X-Business-Id' => $ctx['biz']['id'],
    ])->getJson('/api/v1/reorder/forecast')->assertStatus(403);

    // Member of the business whose role lacks purchasing.manage.
    $role = Role::create([
        'business_id' => $ctx['biz']['id'],
        'slug' => 'viewer',
        'name' => 'Viewer',
        'is_system' => false,
    ]);
    $member = User::factory()->create();
    BusinessMembership::create([
        'user_id' => $member->id,
        'business_id' => $ctx['biz']['id'],
        'role_id' => $role->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);
    $token = $member->createToken('t')->plainTextToken;
    $this->app['auth']->forgetGuards();

    $this->withHeaders([
        'Authorization' => "Bearer $token",
        'X-Business-Id' => $ctx['biz']['id'],
    ])->getJson('/api/v1/reorder/forecast')->assertStatus(403);
});

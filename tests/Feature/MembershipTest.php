<?php

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Outlet;
use App\Models\OutletMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function createMembershipFixture($test): array
{
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);

    $business = $test->postJson('/api/v1/businesses', ['name' => 'Membership Test'])
        ->assertCreated()
        ->json('data');
    $headers = ['X-Business-Id' => (string) $business['id']];

    $main = $test->withHeaders($headers)
        ->postJson('/api/v1/outlets', ['name' => 'Main Outlet', 'code' => 'MAIN'])
        ->assertCreated()
        ->json('data');
    $second = $test->withHeaders($headers)
        ->postJson('/api/v1/outlets', ['name' => 'Second Outlet', 'code' => 'SECOND'])
        ->assertCreated()
        ->json('data');

    $rolePayload = [
        'name' => 'Cashier',
        'slug' => 'cashier-'.uniqid(),
        'permission_ids' => Permission::whereIn('name', ['sales.view', 'sales.create'])->pluck('id')->all(),
    ];
    $role = $test->withHeaders($headers)
        ->postJson('/api/v1/roles', $rolePayload)
        ->assertCreated()
        ->json('data');

    return compact('owner', 'business', 'headers', 'main', 'second', 'role');
}

test('invite assigns outlets from the current business and lists them', function () {
    $fixture = createMembershipFixture($this);

    $invite = $this->withHeaders($fixture['headers'])->postJson('/api/v1/memberships/invite', [
        'email' => 'cashier@example.com',
        'role_id' => $fixture['role']['id'],
        'outlet_ids' => [$fixture['main']['id']],
    ]);

    $invite->assertCreated()
        ->assertJsonPath('data.outlets.0.id', $fixture['main']['id'])
        ->assertJsonPath('data.outlets.0.name', 'Main Outlet');

    $member = User::where('email', 'cashier@example.com')->firstOrFail();
    expect(OutletMembership::where('user_id', $member->id)->pluck('outlet_id')->all())
        ->toBe([$fixture['main']['id']]);

    $list = $this->withHeaders($fixture['headers'])->getJson('/api/v1/memberships')->assertOk();
    $row = collect($list->json('data.data'))->firstWhere('user.email', 'cashier@example.com');

    expect($row)->not->toBeNull()
        ->and($row['outlets'])->toHaveCount(1)
        ->and($row['outlets'][0]['id'])->toBe($fixture['main']['id']);
});

test('invite rejects outlets that belong to another business', function () {
    $fixture = createMembershipFixture($this);

    $otherBusiness = Business::create([
        'name' => 'Other Business',
        'slug' => 'other-business-'.uniqid(),
        'status' => 'active',
    ]);
    $otherOutlet = Outlet::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Other Outlet',
        'code' => 'OTHER',
        'status' => 'active',
    ]);

    $response = $this->withHeaders($fixture['headers'])->postJson('/api/v1/memberships/invite', [
        'email' => 'outsider@example.com',
        'role_id' => $fixture['role']['id'],
        'outlet_ids' => [$fixture['main']['id'], $otherOutlet->id],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('outlet_ids.1');
    expect(User::where('email', 'outsider@example.com')->exists())->toBeFalse();
});

test('invite rejects roles that belong to another business', function () {
    $fixture = createMembershipFixture($this);

    $otherBusiness = Business::create([
        'name' => 'Other Role Business',
        'slug' => 'other-role-business-'.uniqid(),
        'status' => 'active',
    ]);
    $otherRole = Role::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Foreign Owner',
        'slug' => 'foreign-owner-'.uniqid(),
    ]);

    $response = $this->withHeaders($fixture['headers'])->postJson('/api/v1/memberships/invite', [
        'email' => 'foreign.role@example.com',
        'role_id' => $otherRole->id,
        'outlet_ids' => [$fixture['main']['id']],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('role_id');
    expect(User::where('email', 'foreign.role@example.com')->exists())->toBeFalse();
});

test('updating and removing a member keeps outlet assignments scoped to the business', function () {
    $fixture = createMembershipFixture($this);

    $this->withHeaders($fixture['headers'])->postJson('/api/v1/memberships/invite', [
        'email' => 'shiftlead@example.com',
        'role_id' => $fixture['role']['id'],
        'outlet_ids' => [$fixture['main']['id']],
    ])->assertCreated();

    $member = User::where('email', 'shiftlead@example.com')->firstOrFail();
    $membership = BusinessMembership::where('user_id', $member->id)
        ->where('business_id', $fixture['business']['id'])
        ->firstOrFail();

    $update = $this->withHeaders($fixture['headers'])->putJson("/api/v1/memberships/{$membership->id}", [
        'role_id' => $fixture['role']['id'],
        'status' => 'active',
        'outlet_ids' => [$fixture['second']['id']],
    ]);

    $update->assertOk()
        ->assertJsonPath('data.outlets.0.id', $fixture['second']['id']);
    expect(OutletMembership::where('user_id', $member->id)->pluck('outlet_id')->all())
        ->toBe([$fixture['second']['id']]);

    $this->withHeaders($fixture['headers'])
        ->deleteJson("/api/v1/memberships/{$membership->id}")
        ->assertOk();

    expect(BusinessMembership::find($membership->id))->toBeNull()
        ->and(OutletMembership::where('user_id', $member->id)->exists())->toBeFalse();
});

test('role and permission catalogs require employee view access', function () {
    $fixture = createMembershipFixture($this);

    $this->withHeaders($fixture['headers'])->postJson('/api/v1/memberships/invite', [
        'email' => 'restricted.roles@example.com',
        'role_id' => $fixture['role']['id'],
        'outlet_ids' => [$fixture['main']['id']],
    ])->assertCreated();

    $restricted = User::where('email', 'restricted.roles@example.com')->firstOrFail();
    Sanctum::actingAs($restricted);

    $this->withHeaders($fixture['headers'])->getJson('/api/v1/roles')->assertForbidden();
    $this->withHeaders($fixture['headers'])->getJson('/api/v1/permissions')->assertForbidden();
});

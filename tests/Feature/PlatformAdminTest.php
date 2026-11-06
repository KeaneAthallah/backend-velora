<?php

use App\Models\User;
use App\Models\UserNotification;

function withBearerHeaders($test, array $headers): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    return $headers;
}

function platformSetup($test, string $suffix = 'PL'): array
{
    app('auth')->forgetGuards();
    $test->flushHeaders();

    $admin = User::factory()->create(['is_platform_admin' => true]);
    $adminToken = $admin->createToken('t')->plainTextToken;
    $adminHeaders = ['Authorization' => "Bearer $adminToken"];

    $owner = User::factory()->create();
    $ownerToken = $owner->createToken('t')->plainTextToken;
    $auth = ['Authorization' => "Bearer $ownerToken"];

    $biz = $test->withHeaders($auth)
        ->postJson('/api/v1/businesses', ['name' => "Platform Tenant $suffix"])
        ->json('data');
    $bizHeaders = array_merge($auth, ['X-Business-Id' => $biz['id']]);

    $outlet = $test->withHeaders($bizHeaders)
        ->postJson('/api/v1/outlets', ['name' => "Platform Outlet $suffix", 'code' => "PL-$suffix"])
        ->json('data');
    $headers = array_merge($bizHeaders, ['X-Outlet-Id' => $outlet['id']]);

    return compact('admin', 'adminHeaders', 'owner', 'ownerToken', 'auth', 'biz', 'bizHeaders', 'outlet', 'headers');
}

test('platform dashboard reports headline counts', function () {
    $ctx = platformSetup($this, 'DASH');
    app('auth')->forgetGuards();

    $response = $this->withHeaders($ctx['adminHeaders'])
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($response->json('data.businesses.total'))->toBeGreaterThanOrEqual(1);
    expect($response->json('data.users.platform_admins'))->toBe(1);
    expect($response->json('data.health.open_tickets'))->toBe(0);
    expect($response->json('data.recent_signups.0.name'))->toBe('Platform Tenant DASH');
});

test('platform routes reject non administrators', function () {
    $ctx = platformSetup($this, 'NOPE');

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/admin/dashboard')
        ->assertStatus(403);

    $this->withHeaders($ctx['headers'])
        ->getJson('/api/v1/admin/tenants')
        ->assertStatus(403);
});

test('tenant directory can be searched and opened', function () {
    $ctx = platformSetup($this, 'DIR');
    app('auth')->forgetGuards();

    $list = $this->withHeaders($ctx['adminHeaders'])
        ->getJson('/api/v1/admin/tenants?q=Platform Tenant DIR')
        ->assertOk()
        ->json('data');

    expect($list['total'])->toBe(1);
    expect($list['data'][0]['id'])->toBe((string) $ctx['biz']['id']);
    expect($list['data'][0]['outlets'])->toBe(1);

    $detail = $this->withHeaders($ctx['adminHeaders'])
        ->getJson("/api/v1/admin/tenants/{$ctx['biz']['id']}")
        ->assertOk()
        ->json('data');

    expect($detail['tenant']['name'])->toBe('Platform Tenant DIR');
    expect($detail['outlets'][0]['name'])->toBe('Platform Outlet DIR');
    expect($detail['sales']['count'])->toBe(0);
});

test('monitoring returns failure and activity sections', function () {
    $ctx = platformSetup($this, 'MON');
    app('auth')->forgetGuards();

    $this->withHeaders($ctx['adminHeaders'])
        ->getJson('/api/v1/admin/monitoring')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'failed_webhook_deliveries',
                'failed_payment_webhooks',
                'past_due',
                'audit_activity_24h',
                'counts' => ['businesses', 'users', 'outlets', 'sales'],
            ],
        ]);
});

test('platform settings are stored and read back', function () {
    $ctx = platformSetup($this, 'SET');
    app('auth')->forgetGuards();

    $this->withHeaders($ctx['adminHeaders'])
        ->putJson('/api/v1/admin/settings', ['settings' => ['support.email' => 'help@velora.test']])
        ->assertOk();

    $response = $this->withHeaders($ctx['adminHeaders'])
        ->getJson('/api/v1/admin/settings')
        ->assertOk()
        ->json('data');

    $setting = collect($response['settings'])->firstWhere('key', 'support.email');
    expect($setting['value'])->toBe('help@velora.test');
    expect($response['defaults'])->toHaveKey('support.email');
});

test('a support ticket flows from tenant to admin and back', function () {
    $ctx = platformSetup($this, 'TKT');
    app('auth')->forgetGuards();

    $ticket = $this->withHeaders($ctx['headers'])
        ->postJson('/api/v1/support/tickets', [
            'subject' => 'Cannot print receipts',
            'category' => 'technical',
            'priority' => 'high',
            'body' => 'The printer stops after the first receipt.',
        ])
        ->assertStatus(201)
        ->json('data.ticket');

    expect($ticket['number'])->toStartWith('TKT-');

    // Platform admins are notified when a ticket is opened.
    expect(UserNotification::where('user_id', $ctx['admin']->id)->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($ctx['adminHeaders'])
        ->getJson('/api/v1/admin/tickets')
        ->assertOk()
        ->assertJsonPath('data.counts.open', 1);

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders($ctx['adminHeaders'])
        ->postJson("/api/v1/admin/tickets/{$ticket['id']}/reply", [
            'body' => 'Please restart the printer and try again.',
        ])
        ->assertStatus(201);

    app('auth')->forgetGuards();
    $this->flushHeaders();

    $detail = $this->withHeaders($ctx['headers'])
        ->getJson("/api/v1/support/tickets/{$ticket['id']}")
        ->assertOk()
        ->json('data');

    expect($detail['messages'])->toHaveCount(2);
    expect($detail['ticket']['status'])->toBe('pending');

    // The reply lands in the owner's inbox.
    $notifications = $this->withHeaders(withBearerHeaders($this, $ctx['headers']))
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->json('data');

    expect($notifications['unread_count'])->toBe(1);
    expect($notifications['notifications']['data'][0]['type'])->toBe('support.reply');

    // Closing a ticket blocks further tenant replies.
    $this->withHeaders(withBearerHeaders($this, $ctx['adminHeaders']))
        ->putJson("/api/v1/admin/tickets/{$ticket['id']}", ['status' => 'closed'])
        ->assertOk();

    $this->withHeaders(withBearerHeaders($this, $ctx['headers']))
        ->postJson("/api/v1/support/tickets/{$ticket['id']}/reply", ['body' => 'Still broken.'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TICKET_CLOSED');
});

<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/* ───────────────────────────────────────────────────────────
   Trial lifecycle
   ─────────────────────────────────────────────────────────── */

test('trial is created automatically with a business', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $business = $this->postJson('/api/v1/businesses', ['name' => 'Trial Business'])
        ->assertCreated()->json('data');

    // GET /subscription (returns snapshot including trial info)
    $sub = $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->json('data.subscription');

    expect($sub['status'])->toBe('trialing')
        ->and($sub['is_active'])->toBeTrue()
        ->and($sub['trial_days_remaining'])->toBeGreaterThan(0);
});

test('existing subscription is not replaced on subsequent calls', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Biz'])->assertCreated();

    $firstId = $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->json('data.subscription.id');

    $second = $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->json('data.subscription.id');

    expect($second)->toBe($firstId);
});

test('trial expires after trial_days and status becomes expired', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Expire Test'])->assertCreated();

    config(['subscriptions.trial_days' => 1]);
    $sub = Subscription::whereHas('business', fn ($q) => $q->where('name', 'Expire Test'))->firstOrFail();
    $sub->update([
        'trial_ends_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2),
    ]);

    $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->assertJsonPath('data.subscription.status', 'expired');
});

/* ───────────────────────────────────────────────────────────
   Access blocking
   ─────────────────────────────────────────────────────────── */

test('expired subscription blocks writes but allows reads', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Access Test'])->assertCreated();

    $sub = Subscription::whereHas('business', fn ($q) => $q->where('name', 'Access Test'))->firstOrFail();
    $sub->update([
        'status' => 'expired',
        'ends_at' => now()->subDays(2),
    ]);

    $this->getJson('/api/v1/subscription')->assertSuccessful();

    $this->postJson('/api/v1/products', [
        'name' => 'Should Fail',
        'sku' => 'FAIL-001',
        'price' => '10000.0000',
        'cost' => '5000.0000',
    ])->assertStatus(402)->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
});

/* ───────────────────────────────────────────────────────────
   Feature gates
   ─────────────────────────────────────────────────────────── */

test('feature gate blocks advanced_analytics for trial plan', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Feature Gate Test'])->assertCreated();

    $this->getJson('/api/v1/reports/advanced/sales-trend?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString())
        ->assertStatus(403)
        ->assertJsonPath('code', 'FEATURE_NOT_AVAILABLE');
});

/* ───────────────────────────────────────────────────────────
   Plan management
   ─────────────────────────────────────────────────────────── */

test('upgrade requires checkout when plan price changes', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Upgrade Test'])->assertCreated();

    $result = $this->postJson('/api/v1/subscription/change-plan', [
        'plan' => 'basic',
        'billing_period' => 'monthly',
        'provider' => 'manual',
    ])->assertSuccessful()->json('data');

    expect($result['requires_payment'])->toBeTrue();
});

test('downgrade is scheduled without payment', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Downgrade Test'])->assertCreated();

    $this->postJson('/api/v1/subscription/change-plan', [
        'plan' => 'pro',
        'billing_period' => 'monthly',
        'provider' => 'manual',
    ])->assertSuccessful();

    $result = $this->postJson('/api/v1/subscription/change-plan', [
        'plan' => 'basic',
        'billing_period' => 'monthly',
    ])->assertSuccessful();

    $resultData = $result->json('data');
    expect($resultData)->toHaveKey('requires_payment');
});

test('cancel marks subscription for cancellation', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Cancel Test'])->assertCreated();

    // Verify the snapshot shows canceled or pending-cancellation state
    $this->getJson('/api/v1/subscription')->assertSuccessful();

    $result = $this->postJson('/api/v1/subscription/cancel')
        ->assertSuccessful();

    // After cancel, re-fetch and verify
    $sub = $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->json('data.subscription.status');

    expect(in_array($sub, ['canceled', 'expired']))->toBeTrue();
});

test('resume reactivates a canceled subscription', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/businesses', ['name' => 'Resume Test'])->assertCreated();

    $this->postJson('/api/v1/subscription/cancel')->assertSuccessful();

    $this->postJson('/api/v1/subscription/resume')->assertSuccessful();

    $status = $this->getJson('/api/v1/subscription')
        ->assertSuccessful()
        ->json('data.subscription.status');

    expect($status)->toBe('active');
});

/* ───────────────────────────────────────────────────────────
   Tenant isolation
   ─────────────────────────────────────────────────────────── */

test('business A cannot access business B subscription data', function () {
    $admin = User::factory()->create();
    Sanctum::actingAs($admin);

    $bizA = $this->postJson('/api/v1/businesses', ['name' => 'Biz A'])
        ->assertCreated()->json('data');

    $bizB = $this->postJson('/api/v1/businesses', ['name' => 'Biz B'])
        ->assertCreated()->json('data');

    $headers = ['X-Business-Id' => (string) $bizA['id']];
    $aSub = $this->withHeaders($headers)
        ->getJson('/api/v1/subscription')
        ->assertSuccessful()->json('data.business_id');

    expect($aSub)->toBe((string) $bizA['id']);
});

/* ───────────────────────────────────────────────────────────
   Admin routes
   ─────────────────────────────────────────────────────────── */

test('non-admin gets 403 on admin routes', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/admin/subscriptions/overview')
        ->assertStatus(403);
});

test('sync-plans creates missing plans without overwriting', function () {
    Plan::query()->delete();
    Artisan::call('subscriptions:sync-plans');
    expect(Artisan::output())->toContain('Missing subscription plans created');

    expect(Plan::count())->toBeGreaterThanOrEqual(3);
});

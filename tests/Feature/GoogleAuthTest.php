<?php

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'http://localhost:8000/api/v1/auth/google/callback',
        'app.frontend_url' => 'http://localhost:3000',
    ]);
});

/** Ask the redirect endpoint for a state value the callback will accept. */
function googleOAuthState(?string $next = null): string
{
    $path = '/api/v1/auth/google/redirect'.($next ? '?next='.urlencode($next) : '');
    $url = test()->getJson($path)->assertOk()->json('data.url');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['state'];
}

/** Pull the query values out of the frontend redirect the callback returned. */
function googleCallbackParams(string $location): array
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

test('the redirect endpoint returns a google authorize url with encrypted state', function () {
    Socialite::fake('google', SocialiteUser::fake());

    $res = $this->getJson('/api/v1/auth/google/redirect?next=/reports');

    $res->assertOk()->assertJsonPath('success', true);
    $url = $res->json('data.url');
    expect($url)->toStartWith('https://socialite.fake/google/authorize');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $state = json_decode(Crypt::decryptString($query['state']), true);

    expect($state['next'])->toBe('/reports')
        ->and($state['expires_at'])->toBeGreaterThan(now()->getTimestamp());
});

test('the redirect endpoint reports missing google credentials', function () {
    config([
        'services.google.client_id' => null,
        'services.google.client_secret' => null,
    ]);

    $this->getJson('/api/v1/auth/google/redirect')
        ->assertStatus(503)
        ->assertJsonPath('code', 'GOOGLE_NOT_CONFIGURED');
});

test('the redirect endpoint refuses absolute return paths', function () {
    Socialite::fake('google', SocialiteUser::fake());

    $state = googleOAuthState('//evil.example.com/steal');

    expect(json_decode(Crypt::decryptString($state), true)['next'])->toBeNull();
});

test('a google callback creates an account and exchanges the code for a token', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-123',
        'name' => 'Gina Google',
        'email' => 'Gina@Example.com',
        'avatar' => 'https://example.com/gina.png',
        'email_verified' => true,
    ]));

    $state = googleOAuthState('/reports');

    $callback = $this->get('/api/v1/auth/google/callback?code=abc&state='.urlencode($state));

    $callback->assertRedirect();
    $location = $callback->headers->get('Location');
    expect($location)->toStartWith('http://localhost:3000/auth/google/callback?');

    $params = googleCallbackParams($location);
    expect($params['next'])->toBe('/reports');

    $user = User::where('email', 'gina@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('google-123')
        ->and($user->avatar_url)->toBe('https://example.com/gina.png')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();

    $exchange = $this->postJson('/api/v1/auth/google/exchange', ['code' => $params['code']]);

    $exchange->assertOk()->assertJsonPath('success', true);
    $token = $exchange->json('data.token');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'gina@example.com');
});

test('an exchange code can only be used once', function () {
    Socialite::fake('google', SocialiteUser::fake(['email_verified' => true]));
    $state = googleOAuthState();

    $location = $this->get('/api/v1/auth/google/callback?code=abc&state='.urlencode($state))
        ->headers->get('Location');
    $code = googleCallbackParams($location)['code'];

    $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])->assertOk();
    $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('code', 'GOOGLE_CODE_INVALID');
});

test('an unknown exchange code is rejected', function () {
    $this->postJson('/api/v1/auth/google/exchange', ['code' => str_repeat('a', 40)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'GOOGLE_CODE_INVALID');
});

test('a verified google email links an existing password account', function () {
    $existing = User::factory()->create(['email' => 'owner@example.com']);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-owner',
        'name' => 'Owner Name',
        'email' => 'owner@example.com',
        'email_verified' => true,
    ]));

    $state = googleOAuthState();
    $location = $this->get('/api/v1/auth/google/callback?code=abc&state='.urlencode($state))
        ->headers->get('Location');

    expect(googleCallbackParams($location))->toHaveKey('code');

    $existing->refresh();
    expect($existing->google_id)->toBe('google-owner')
        ->and($existing->password)->not->toBeNull()
        ->and(User::where('email', 'owner@example.com')->count())->toBe(1);
});

test('an unverified google email is not linked to an existing account', function () {
    $existing = User::factory()->create(['email' => 'victim@example.com']);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-attacker',
        'email' => 'victim@example.com',
        'email_verified' => false,
    ]));

    $state = googleOAuthState();
    $location = $this->get('/api/v1/auth/google/callback?code=abc&state='.urlencode($state))
        ->headers->get('Location');

    expect(googleCallbackParams($location)['error'])->toBe('google_email_unverified')
        ->and($existing->refresh()->google_id)->toBeNull();
});

test('a google login for an inactive account is refused', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-suspended',
        'email' => 'suspended@example.com',
        'email_verified' => true,
    ]));

    User::factory()->create([
        'email' => 'suspended@example.com',
        'status' => 'suspended',
    ]);

    $state = googleOAuthState();
    $location = $this->get('/api/v1/auth/google/callback?code=abc&state='.urlencode($state))
        ->headers->get('Location');

    expect(googleCallbackParams($location)['error'])->toBe('account_inactive');
});

test('a forged or expired state is sent back as invalid_state', function () {
    Socialite::fake('google', SocialiteUser::fake(['email_verified' => true]));

    $location = $this->get('/api/v1/auth/google/callback?code=abc&state=not-a-real-state')
        ->headers->get('Location');

    expect(googleCallbackParams($location)['error'])->toBe('invalid_state');
});

test('a cancelled google consent comes back as access_denied', function () {
    $location = $this->get('/api/v1/auth/google/callback?error=access_denied')
        ->headers->get('Location');

    expect(googleCallbackParams($location)['error'])->toBe('access_denied');
});

test('a google-only account cannot sign in with a password', function () {
    User::create([
        'name' => 'No Password',
        'email' => 'nopassword@example.com',
        'password' => null,
        'google_id' => 'google-nopass',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nopassword@example.com',
        'password' => 'whatever123',
    ])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

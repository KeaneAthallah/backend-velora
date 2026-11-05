<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Throwable;

class GoogleAuthController extends Controller
{
    /** How long the signed OAuth state (carrying the return path) stays valid. */
    private const STATE_TTL_SECONDS = 600;

    /** How long the one-time code has to be exchanged for a Sanctum token. */
    private const CODE_TTL_MINUTES = 5;

    /**
     * Hand the browser an authorize URL for Google.
     *
     * The API has no session, so the usual Socialite state cookie is replaced
     * by an encrypted, expiring state value that also carries `next`.
     */
    public function redirect(Request $request): JsonResponse
    {
        if (! $this->configured()) {
            return $this->error(
                'Google sign-in is not configured on this server.',
                503,
                [],
                'GOOGLE_NOT_CONFIGURED'
            );
        }

        $data = $request->validate(['next' => 'nullable|string|max:255']);

        $state = Crypt::encryptString((string) json_encode([
            'next' => $this->safeNext($data['next'] ?? null),
            'expires_at' => now()->getTimestamp() + self::STATE_TTL_SECONDS,
            'nonce' => Str::random(32),
        ]));

        $url = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        $url .= (str_contains($url, '?') ? '&' : '?').'state='.urlencode($state);

        return $this->success(['url' => $url], 'Google authorization URL');
    }

    /**
     * Google sends the browser back here. This endpoint never returns JSON:
     * it always sends the browser to the frontend callback page, which then
     * trades the one-time code for a token.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = $this->frontendUrl();

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail($frontend, 'access_denied');
        }

        try {
            $state = $this->decodeState($request->input('state'));
        } catch (Throwable $e) {
            report($e);

            return $this->fail($frontend, 'invalid_state');
        }

        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            report($e);

            return $this->fail($frontend, 'google_failed');
        }

        $email = strtolower(trim((string) $google->getEmail()));
        if ($email === '') {
            return $this->fail($frontend, 'google_email_missing');
        }

        // Linking a Google identity onto an existing password account is only
        // safe when Google says it has verified ownership of that address.
        if (! $this->googleEmailVerified($google)) {
            return $this->fail($frontend, 'google_email_unverified');
        }

        try {
            $user = $this->resolveUser(
                (string) $google->getId(),
                $email,
                $google->getName(),
                $google->getAvatar()
            );
        } catch (Throwable $e) {
            report($e);

            return $this->fail($frontend, 'google_failed');
        }

        if ($user->status !== 'active') {
            return $this->fail($frontend, 'account_inactive');
        }

        // A token must never travel through a URL, so the redirect only carries
        // a short-lived, single-use code that the frontend exchanges over POST.
        $code = Str::random(40);
        Cache::put($this->codeKey($code), $user->id, now()->addMinutes(self::CODE_TTL_MINUTES));

        return redirect()->away($this->callbackUrl($frontend, [
            'code' => $code,
            'next' => $state['next'] ?? null,
        ]));
    }

    /**
     * Trade the one-time code from the callback redirect for a Sanctum token.
     */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:100']);

        // pull() makes the code single use: a replay finds nothing.
        $userId = Cache::pull($this->codeKey($data['code']));
        if (! is_numeric($userId)) {
            return $this->error(
                'That sign-in link has expired. Start again.',
                422,
                [],
                'GOOGLE_CODE_INVALID'
            );
        }

        $user = User::find((int) $userId);
        if (! $user || $user->status !== 'active') {
            return $this->error('Account inactive', 403, [], 'ACCOUNT_INACTIVE');
        }

        $token = $user->createToken('auth')->plainTextToken;

        return $this->success(['user' => $user, 'token' => $token]);
    }

    /**
     * Verify a Google ID token obtained on a native client and trade it for a
     * Sanctum token. Used by the mobile app, which cannot run the web redirect
     * flow.
     */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate(['id_token' => 'required|string']);

        if (! $this->configured()) {
            return $this->error('Google sign-in is not configured on this server.', 503, [], 'GOOGLE_NOT_CONFIGURED');
        }

        try {
            $info = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $data['id_token'],
            ])->throw()->json();
        } catch (Throwable $e) {
            report($e);

            return $this->error('Invalid Google token.', 422, [], 'GOOGLE_TOKEN_INVALID');
        }

        $audience = config('services.google.client_id');
        if (! is_array($info) || ($info['aud'] ?? null) !== $audience) {
            return $this->error('Invalid Google token audience.', 422, [], 'GOOGLE_TOKEN_INVALID');
        }

        $email = strtolower(trim((string) ($info['email'] ?? '')));
        if ($email === '') {
            return $this->error('Google account has no email.', 422, [], 'GOOGLE_EMAIL_MISSING');
        }

        if (! filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->error('Google email is not verified.', 422, [], 'GOOGLE_EMAIL_UNVERIFIED');
        }

        try {
            $user = $this->resolveUser(
                (string) ($info['sub'] ?? ''),
                $email,
                $info['name'] ?? null,
                $info['picture'] ?? null
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('Google sign-in failed.', 422, [], 'GOOGLE_FAILED');
        }

        if ($user->status !== 'active') {
            return $this->error('Account inactive', 403, [], 'ACCOUNT_INACTIVE');
        }

        $token = $user->createToken('auth')->plainTextToken;

        return $this->success(['user' => $user, 'token' => $token]);
    }

    private function resolveUser(string $googleId, string $email, ?string $name, ?string $avatar): User
    {
        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            $user = User::whereRaw('lower(email) = ?', [$email])->first();
        }

        if (! $user) {
            $user = User::create([
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'google_id' => $googleId,
                'avatar_url' => $avatar,
                'password' => null,
                // Set explicitly: a fresh model does not carry column defaults.
                'status' => 'active',
            ]);

            // Google already proved the address, and it is not mass assignable.
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        }

        $changes = ['google_id' => $googleId];
        if ($avatar) {
            $changes['avatar_url'] = $avatar;
        }
        $user->forceFill($changes)->save();

        return $user;
    }

    private function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    private function googleEmailVerified(SocialiteUser $google): bool
    {
        $raw = (array) $google->getRaw();

        return filter_var(
            $raw['email_verified'] ?? $raw['verified_email'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Decrypt and validate the state issued by redirect().
     *
     * @return array{next?: ?string}
     */
    private function decodeState(?string $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Missing OAuth state.');
        }

        $payload = json_decode(Crypt::decryptString($raw), true);

        if (! is_array($payload) || ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw new RuntimeException('Expired OAuth state.');
        }

        return $payload;
    }

    /** Only same-origin relative paths may be carried through the OAuth round trip. */
    private function safeNext(?string $next): ?string
    {
        if (! is_string($next) || $next === '') {
            return null;
        }

        if (! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return null;
        }

        if (preg_match('#[\\\\\x00-\x1F]#', $next)) {
            return null;
        }

        if (str_starts_with($next, '/login') || str_starts_with($next, '/auth/google')) {
            return null;
        }

        return $next;
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function fail(string $frontend, string $error): RedirectResponse
    {
        return redirect()->away($this->callbackUrl($frontend, ['error' => $error]));
    }

    /**
     * @param  array<string, string|null>  $params
     */
    private function callbackUrl(string $frontend, array $params): string
    {
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');

        return $frontend.'/auth/google/callback?'.http_build_query($params);
    }

    private function codeKey(string $code): string
    {
        return 'google-auth:'.$code;
    }
}

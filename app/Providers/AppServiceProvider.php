<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Partner webhooks are the only unauthenticated way to create records
        // outside auth, and marketplaces retry aggressively. Cap the ingress per
        // channel token (not per IP alone) so one noisy partner cannot exhaust
        // another partner's budget when both arrive through the same proxy.
        RateLimiter::for('channel-webhook', function (Request $request) {
            $token = (string) $request->route('token');

            return Limit::perMinute(60)->by($token.'|'.$request->ip());
        });
    }
}

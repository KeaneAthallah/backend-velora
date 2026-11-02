<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Entitlement is also computed lazily on each request, so this scheduler is
// for bulk reporting and clean status transitions, not correctness.
Schedule::command('subscriptions:run-lifecycle')->hourly()->withoutOverlapping();

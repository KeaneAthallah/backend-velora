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

// Saved reports run at their configured time. The command records the run and
// marks delivery; the mailer transport is deployment-specific.
Schedule::command('reports:deliver-due')->hourly()->withoutOverlapping();

// Reorder-level alerts land in the in-app inbox. The command de-duplicates per
// member, ingredient and outlet within a day, so hourly is safe.
Schedule::command('inventory:scan-low-stock')->hourly()->withoutOverlapping();

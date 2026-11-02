<?php

namespace Database\Seeders;

use App\Services\Subscription\PlanService;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Seed the plan catalogue from config/subscriptions.php. Re-running this
     * resets prices/limits to the config defaults; the runtime service only
     * creates missing rows so an administrator's edits survive normal traffic.
     */
    public function run(): void
    {
        app(PlanService::class)->syncFromConfig();
    }
}

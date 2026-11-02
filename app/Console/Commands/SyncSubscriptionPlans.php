<?php

namespace App\Console\Commands;

use App\Services\Subscription\PlanService;
use Illuminate\Console\Command;

class SyncSubscriptionPlans extends Command
{
    protected $signature = 'subscriptions:sync-plans {--force : Reset existing plans to the config defaults}';

    protected $description = 'Create missing subscription plans, or reset them from config with --force';

    public function handle(PlanService $plans): int
    {
        if ($this->option('force')) {
            $plans->syncFromConfig();
            $this->info('Subscription plans reset from config/subscriptions.php.');
        } else {
            $plans->ensureSeeded();
            $this->info('Missing subscription plans created (existing rows left untouched).');
        }

        return self::SUCCESS;
    }
}

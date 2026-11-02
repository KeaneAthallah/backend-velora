<?php

namespace App\Console\Commands;

use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;

class RunSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:run-lifecycle';

    protected $description = 'Expire ended subscriptions and grace periods that have run out';

    public function handle(SubscriptionService $subscriptions): int
    {
        $result = $subscriptions->runLifecycle();

        $this->info("Subscription lifecycle complete: {$result['expired']} expired.");

        return self::SUCCESS;
    }
}

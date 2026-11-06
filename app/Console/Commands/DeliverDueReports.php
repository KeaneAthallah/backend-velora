<?php

namespace App\Console\Commands;

use App\Models\SavedReport;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;

class DeliverDueReports extends Command
{
    protected $signature = 'reports:deliver-due';

    protected $description = 'Generate and record delivery of scheduled saved reports that are due';

    public function handle(AnalyticsService $analytics): int
    {
        $due = SavedReport::where('schedule', '<>', 'none')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        $delivered = 0;
        $failed = 0;

        foreach ($due as $report) {
            try {
                $result = $analytics->runSavedReport($report);
                $result['run']->update(['delivered_at' => now(), 'delivery_status' => 'delivered']);
                $delivered++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Report {$report->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Scheduled reports: {$delivered} delivered, {$failed} failed.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BusinessMembership;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PayrollRun;
use App\Models\PayrollStructure;
use App\Models\Payslip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payroll generation and lifecycle.
 *
 * A run is a month (`YYYY-MM`). Generation snapshots every active member who
 * has a payroll structure into a payslip: a fixed structure pays `base_salary`,
 * an hourly one pays `hourly_rate` times the hours worked that month (from
 * attendance, breaks deducted). Allowances and deductions are label => amount
 * maps on the structure and are copied into `details` so a later edit to the
 * structure does not rewrite history.
 *
 * Amounts are whole currency, matching the rest of the finance module.
 *
 * Lifecycle: draft (generated, payslips editable) → approved (locked) → paid.
 */
class PayrollService
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first day, exclusive next-month start */
    public function range(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();

        return [$start, $start->addMonth()];
    }

    public function generate(int $businessId, string $period): PayrollRun
    {
        [$start, $end] = $this->range($period);

        return DB::transaction(function () use ($businessId, $period, $start, $end) {
            $run = PayrollRun::firstOrCreate(
                ['business_id' => $businessId, 'period' => $period],
                ['status' => 'draft']
            );
            if ($run->status !== 'draft') {
                throw new \RuntimeException('This period has already been approved and can no longer be regenerated.');
            }

            // Only people who are still on the team are paid. A structure left
            // behind by a removed member stays in the table but earns nothing.
            $memberIds = BusinessMembership::where('business_id', $businessId)
                ->where('status', 'active')
                ->pluck('user_id');

            $structures = PayrollStructure::where('business_id', $businessId)
                ->whereIn('user_id', $memberIds)
                ->with('user:id,name,email')
                ->get();

            $existing = $run->payslips()->get()->keyBy('user_id');
            $kept = [];

            foreach ($structures as $structure) {
                $payslip = $existing->get($structure->user_id) ?? new Payslip([
                    'payroll_run_id' => $run->id,
                    'user_id' => $structure->user_id,
                    'bonus_amount' => 0,
                    'overtime_amount' => 0,
                    'commission_amount' => 0,
                ]);

                $hours = $structure->type === 'hourly'
                    ? $this->hoursWorked($businessId, $structure->user_id, $start, $end)
                    : ['hours' => 0.0, 'days' => 0];

                $base = $structure->type === 'hourly'
                    ? (int) round($structure->hourly_rate * $hours['hours'])
                    : (int) $structure->base_salary;

                $allowances = $this->items($structure->allowances);
                $deductions = $this->items($structure->deductions);

                $payslip->fill([
                    'base_amount' => $base,
                    'allowances_total' => array_sum($allowances),
                    'deductions_total' => array_sum($deductions),
                    'details' => [
                        'period' => $period,
                        'structure_id' => $structure->id,
                        'type' => $structure->type,
                        'hourly_rate' => (int) $structure->hourly_rate,
                        'base_salary' => (int) $structure->base_salary,
                        'hours_worked' => $hours['hours'],
                        'days_worked' => $hours['days'],
                        'allowances' => $allowances,
                        'deductions' => $deductions,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]);
                $payslip->net_amount = $this->net($payslip);
                $payslip->save();

                $kept[] = $structure->user_id;
            }

            // Regenerating a draft drops payslips whose structure disappeared.
            $run->payslips()->whereNotIn('user_id', $kept)->delete();

            return $run->load('payslips.user:id,name,email');
        });
    }

    public function approve(PayrollRun $run, int $approverId): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw new \RuntimeException("Only a draft run can be approved; this one is {$run->status}.");
        }
        if (! $run->payslips()->exists()) {
            throw new \RuntimeException('This run has no payslips. Generate it first.');
        }

        $run->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);

        return $run->fresh(['payslips.user:id,name,email']);
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new \RuntimeException('Approve the run before marking it paid.');
        }

        $run->update(['status' => 'paid']);

        return $run->fresh(['payslips.user:id,name,email']);
    }

    /**
     * Post an approved (or already paid) run to the general ledger.
     *
     * One balanced entry per run: total net wages are debited to the expense
     * account the business picked and credited to where the money leaves from
     * (cash, bank, or wages payable). The entry is posted straight away so the
     * cost is in the books the moment payroll is final; finance can reverse it
     * from the journal screen if the wrong account was chosen.
     */
    public function postToLedger(PayrollRun $run, int $expenseAccountId, int $paymentAccountId, int $userId): JournalEntry
    {
        if (! in_array($run->status, ['approved', 'paid'], true)) {
            throw new \RuntimeException('Approve the run before posting it to the ledger.');
        }
        if ($run->journal_entry_id) {
            throw new \RuntimeException('This run is already posted to the ledger.');
        }

        $total = (int) $run->payslips()->sum('net_amount');
        if ($total <= 0) {
            throw new \RuntimeException('Every payslip on this run is zero, so there is nothing to post.');
        }

        // Salaries belong to the month they were earned in, not the day the
        // books are closed, so the entry is dated to the period end.
        $entryDate = CarbonImmutable::createFromFormat('!Y-m', $run->period)->endOfMonth()->toDateString();
        $count = $run->payslips()->count();

        return DB::transaction(function () use ($run, $expenseAccountId, $paymentAccountId, $userId, $total, $entryDate, $count) {
            $entry = JournalEntry::create([
                'business_id' => $run->business_id,
                'number' => 'PAY-'.$run->period,
                'entry_date' => $entryDate,
                'description' => "Payroll {$run->period} · {$count} payslips",
                'reference_type' => 'payroll_run',
                'reference_id' => $run->id,
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expenseAccountId,
                'debit' => $total,
                'credit' => 0,
                'description' => "Net wages for {$run->period}",
            ]);
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $paymentAccountId,
                'debit' => 0,
                'credit' => $total,
                'description' => "Payroll settled for {$run->period}",
            ]);

            $run->update(['journal_entry_id' => $entry->id]);

            return $entry->load('lines.account');
        });
    }

    /** Recompute `net_amount` from the components. Deductions cannot push it below zero. */
    public function net(Payslip $payslip): int
    {
        return max(0, (int) $payslip->base_amount
            + (int) $payslip->allowances_total
            + (int) $payslip->overtime_amount
            + (int) $payslip->bonus_amount
            + (int) $payslip->commission_amount
            - (int) $payslip->deductions_total);
    }

    /**
     * Closed attendance rows inside the period, minus unpaid breaks.
     *
     * An open check-in (no checkout yet) is ignored rather than counted to the
     * end of the period: paying an estimated shift would overstate the run, and
     * the period can be regenerated after the row is closed.
     *
     * @return array{hours: float, days: int}
     */
    public function hoursWorked(int $businessId, int $userId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $records = Attendance::where('business_id', $businessId)
            ->where('user_id', $userId)
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->where('check_in_at', '>=', $start)
            ->where('check_in_at', '<', $end)
            ->get(['check_in_at', 'check_out_at', 'break_start_at', 'break_end_at']);

        $seconds = 0;
        foreach ($records as $record) {
            $seconds += max(0, $record->check_in_at->diffInSeconds($record->check_out_at));
            if ($record->break_start_at && $record->break_end_at) {
                $seconds -= max(0, $record->break_start_at->diffInSeconds($record->break_end_at));
            }
        }

        return [
            'hours' => round(max(0, $seconds) / 3600, 2),
            'days' => $records->count(),
        ];
    }

    /**
     * Accepts `{"Transport": 500000}` or `[{"label": "Transport", "amount": 500000}]`
     * and returns a label => integer amount map.
     *
     * @return array<string, int>
     */
    public function items(?array $items): array
    {
        $result = [];
        foreach ($items ?? [] as $key => $value) {
            if (is_string($key)) {
                if (is_numeric($value)) {
                    $result[$key] = (int) $value;
                }

                continue;
            }
            if (is_array($value) && isset($value['label']) && is_numeric($value['amount'] ?? null)) {
                $result[(string) $value['label']] = (int) $value['amount'];
            }
        }

        return $result;
    }
}

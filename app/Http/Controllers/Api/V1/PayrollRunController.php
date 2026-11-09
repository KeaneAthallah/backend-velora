<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\AuthorizationService;
use App\Services\PayrollService;
use App\Services\ReportExportService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Monthly payroll runs: generate a draft from the structures and attendance,
 * adjust individual payslips, then approve and mark paid.
 */
class PayrollRunController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected PayrollService $payroll,
        protected ReportExportService $exports,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payroll.view', $businessId);

        $query = PayrollRun::where('business_id', $businessId)
            ->with('approver:id,name', 'journalEntry:id,number,status')
            ->withCount('payslips')
            ->withSum('payslips', 'net_amount');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('period')) {
            $query->where('period', $request->input('period'));
        }

        return $this->success($query->orderByDesc('period')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'period.regex' => 'Use a month in the form 2026-11.',
        ]);

        $existing = PayrollRun::where('business_id', $businessId)
            ->where('period', $data['period'])
            ->first();
        if ($existing && $existing->status !== 'draft') {
            return $this->error("The {$data['period']} run is already {$existing->status}.", 422);
        }

        try {
            $run = $this->payroll->generate($businessId, $data['period']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $run->loadCount('payslips')->loadSum('payslips', 'net_amount');

        return $this->success($run, sprintf('Drafted %s payroll', $data['period']), 201);
    }

    public function show(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.view', $businessId);

        return $this->success(
            $payrollRun->load('approver:id,name', 'journalEntry:id,number,status', 'payslips.user:id,name,email')
                ->loadSum('payslips', 'net_amount')
        );
    }

    public function approve(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        try {
            $run = $this->payroll->approve($payrollRun, $request->user()->id);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($run->loadCount('payslips'), 'Payroll approved');
    }

    public function pay(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        try {
            $run = $this->payroll->markPaid($payrollRun);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($run->loadCount('payslips'), 'Payroll marked paid');
    }

    /**
     * Post the run's net wages to the general ledger as one balanced entry:
     * debit the chosen wage-expense account, credit the account it is paid from.
     */
    public function postJournal(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);

        $data = $request->validate([
            'expense_account_id' => 'required|integer',
            'payment_account_id' => 'required|integer',
        ]);

        $expenseId = (int) $data['expense_account_id'];
        $paymentId = (int) $data['payment_account_id'];
        if ($expenseId === $paymentId) {
            return $this->error('Choose two different accounts: one for the expense, one for the money leaving.', 422);
        }

        // Both accounts must belong to this business; never trust ids from the body.
        $owned = Account::where('business_id', $businessId)
            ->whereIn('id', [$expenseId, $paymentId])
            ->count();
        if ($owned !== 2) {
            return $this->error('Not found', 404);
        }

        try {
            $entry = $this->payroll->postToLedger($payrollRun, $expenseId, $paymentId, $request->user()->id);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success(
            $payrollRun->fresh(['approver:id,name', 'journalEntry.lines.account'])->loadSum('payslips', 'net_amount'),
            "Posted {$entry->number} to the ledger",
            201
        );
    }

    /** Payslip sheet for the run: CSV, SpreadsheetML or PDF. */
    public function export(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.view', $businessId);

        $format = in_array($request->input('format'), ReportExportService::FORMATS, true)
            ? (string) $request->input('format')
            : 'csv';

        $payslips = $payrollRun->payslips()->with('user:id,name')->orderBy('id')->get();
        $rows = $payslips->map(fn (Payslip $slip) => [
            $slip->user?->name ?? 'Removed member',
            ($slip->details['type'] ?? 'fixed') === 'hourly' ? 'Hourly' : 'Fixed',
            $slip->details['hours_worked'] ?? 0,
            $slip->base_amount,
            $slip->allowances_total,
            $slip->overtime_amount,
            $slip->bonus_amount,
            $slip->commission_amount,
            $slip->deductions_total,
            $slip->net_amount,
        ])->all();

        return $this->exports->download(
            'payslips-'.$payrollRun->period,
            ['Employee', 'Type', 'Hours', 'Base', 'Allowances', 'Overtime', 'Bonus', 'Commission', 'Deductions', 'Net'],
            $rows,
            $format
        );
    }

    public function destroy(Request $request, PayrollRun $payrollRun)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);
        if ($payrollRun->status !== 'draft') {
            return $this->error('Only a draft run can be deleted.', 422);
        }

        $payrollRun->delete();

        return $this->success(null, 'Deleted');
    }

    /** Adjust one payslip while the run is still a draft. */
    public function updatePayslip(Request $request, PayrollRun $payrollRun, Payslip $payslip)
    {
        $businessId = $request->attributes->get('business_id');
        if ((int) $payrollRun->business_id !== (int) $businessId) {
            return $this->error('Not found', 404);
        }
        if ((int) $payslip->payroll_run_id !== (int) $payrollRun->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'payroll.manage', $businessId);
        if ($payrollRun->status !== 'draft') {
            return $this->error('Payslips are locked once the run is approved.', 422);
        }

        $data = $request->validate([
            'bonus_amount' => 'nullable|integer|min:0',
            'overtime_amount' => 'nullable|integer|min:0',
            'commission_amount' => 'nullable|integer|min:0',
        ]);

        $payslip->fill([
            'bonus_amount' => $data['bonus_amount'] ?? $payslip->bonus_amount,
            'overtime_amount' => $data['overtime_amount'] ?? $payslip->overtime_amount,
            'commission_amount' => $data['commission_amount'] ?? $payslip->commission_amount,
        ]);
        $payslip->net_amount = $this->payroll->net($payslip);
        $payslip->save();

        return $this->success($payslip->load('user:id,name,email'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'journal.view', $businessId);
        $query = JournalEntry::where('business_id', $businessId)->with('lines.account');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $this->success($query->orderByDesc('entry_date')->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'journal.manage', $businessId);
        $data = $request->validate([
            'entry_date' => 'required|date',
            'outlet_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'reference_type' => 'nullable|string|max:64',
            'reference_id' => 'nullable|integer',
            'status' => 'nullable|in:draft,posted',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer',
            'lines.*.debit' => 'nullable|integer|min:0',
            'lines.*.credit' => 'nullable|integer|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        // validate accounts belong to business
        foreach ($data['lines'] as $line) {
            if (! Account::where('id', $line['account_id'])->where('business_id', $businessId)->exists()) {
                return $this->error('Not found', 404);
            }
        }

        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($data['lines'] as $line) {
            $totalDebit += (int) ($line['debit'] ?? 0);
            $totalCredit += (int) ($line['credit'] ?? 0);
        }
        if ($totalDebit !== $totalCredit) {
            return $this->error('Journal entry is not balanced', 422, ['debit' => $totalDebit, 'credit' => $totalCredit], 'UNBALANCED_ENTRY');
        }
        if ($totalDebit === 0) {
            return $this->error('Journal entry must have non-zero amounts', 422, [], 'EMPTY_ENTRY');
        }

        $status = $data['status'] ?? 'draft';

        $entry = DB::transaction(function () use ($businessId, $data, $status, $request) {
            $entry = JournalEntry::create([
                'business_id' => $businessId,
                'outlet_id' => $data['outlet_id'] ?? null,
                'entry_date' => $data['entry_date'],
                'description' => $data['description'] ?? null,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'status' => $status,
                'posted_by' => $status === 'posted' ? $request->user()->id : null,
                'posted_at' => $status === 'posted' ? now() : null,
            ]);
            foreach ($data['lines'] as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => (int) ($line['debit'] ?? 0),
                    'credit' => (int) ($line['credit'] ?? 0),
                    'description' => $line['description'] ?? null,
                ]);
            }

            return $entry;
        });

        return $this->success($entry->load('lines.account'), 'Journal entry created', 201);
    }

    public function show(Request $request, JournalEntry $journalEntry)
    {
        $businessId = $request->attributes->get('business_id');
        if ($journalEntry->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'journal.view', $businessId);

        return $this->success($journalEntry->load('lines.account'));
    }

    public function post(Request $request, JournalEntry $journalEntry)
    {
        $businessId = $request->attributes->get('business_id');
        if ($journalEntry->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'journal.manage', $businessId);
        if ($journalEntry->status !== 'draft') {
            return $this->error('Only draft entries can be posted', 422);
        }
        $journalEntry->update(['status' => 'posted', 'posted_by' => $request->user()->id, 'posted_at' => now()]);

        return $this->success($journalEntry->fresh()->load('lines.account'));
    }

    public function reverse(Request $request, JournalEntry $journalEntry)
    {
        $businessId = $request->attributes->get('business_id');
        if ($journalEntry->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'journal.manage', $businessId);
        if ($journalEntry->status !== 'posted') {
            return $this->error('Only posted entries can be reversed', 422);
        }
        $reversal = DB::transaction(function () use ($journalEntry, $request, $businessId) {
            $new = JournalEntry::create([
                'business_id' => $businessId,
                'outlet_id' => $journalEntry->outlet_id,
                'entry_date' => now()->toDateString(),
                'description' => 'Reversal of '.($journalEntry->number ?? $journalEntry->id),
                'reference_type' => 'reversal',
                'reference_id' => $journalEntry->id,
                'status' => 'posted',
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);
            foreach ($journalEntry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $new->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                ]);
            }
            $journalEntry->update(['status' => 'reversed', 'reversed_by_entry_id' => $new->id]);

            return $new;
        });

        return $this->success($reversal->load('lines.account'), 'Entry reversed', 201);
    }

    public function trialBalance(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'financial_reports.view', $businessId);
        $accounts = Account::where('business_id', $businessId)->where('is_active', true)->get();
        $rows = [];
        foreach ($accounts as $account) {
            $debit = (int) $account->lines()->sum('debit');
            $credit = (int) $account->lines()->sum('credit');
            // opening balance: assets/expenses are debit-nature; liabilities/equity/revenue are credit-nature
            $debitNature = in_array($account->type, ['asset', 'expense', 'cogs'], true);
            if ($debitNature) {
                $debit += (int) $account->opening_balance;
            } else {
                $credit += (int) $account->opening_balance;
            }
            $rows[] = [
                'account_id' => (string) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $this->success(['accounts' => $rows, 'total_debit' => array_sum(array_column($rows, 'debit')), 'total_credit' => array_sum(array_column($rows, 'credit'))]);
    }

    public function generalLedger(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'financial_reports.view', $businessId);
        $accountId = $request->input('account_id');
        $query = JournalEntryLine::whereHas('entry', fn ($q) => $q->where('business_id', $businessId)->where('status', 'posted'))
            ->with('entry', 'account');
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        $lines = $query->orderBy('id')->get();

        return $this->success($lines);
    }
}

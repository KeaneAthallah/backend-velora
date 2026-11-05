<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class FinancialTransactionController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'financial_transactions.view', $businessId);
        $q = FinancialTransaction::where('business_id', $businessId);
        if ($request->filled('type')) {
            $q->where('type', $request->input('type'));
        }
        if ($request->filled('from')) {
            $q->where('transaction_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('transaction_date', '<=', $request->input('to'));
        }

        return $this->success($q->orderByDesc('transaction_date')->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'financial_transactions.manage', $businessId);
        $data = $request->validate([
            'type' => 'required|in:income,expense,transfer',
            'amount' => 'required|integer|min:1',
            'category' => 'nullable|string|max:64',
            'transaction_date' => 'required|date',
            'description' => 'nullable|string',
            'reference' => 'nullable|string|max:128',
            'payment_method' => 'nullable|string|max:32',
            'account_id' => 'nullable|integer',
            'outlet_id' => 'nullable|integer',
            'status' => 'nullable|in:draft,posted',
        ]);
        if (! empty($data['account_id']) && ! Account::where('id', $data['account_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $tx = FinancialTransaction::create(array_merge($data, [
            'business_id' => $businessId,
            'status' => $data['status'] ?? 'posted',
            'created_by' => $request->user()->id,
        ]));

        return $this->success($tx, 'Transaction recorded', 201);
    }
}

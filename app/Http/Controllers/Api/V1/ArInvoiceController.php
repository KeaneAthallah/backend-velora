<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArInvoice;
use App\Models\ArInvoicePayment;
use App\Models\Customer;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ArInvoiceController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ar.view', $businessId);
        $q = ArInvoice::where('business_id', $businessId)->with('customer', 'payments');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('customer_id')) {
            $q->where('customer_id', $request->input('customer_id'));
        }

        return $this->success($q->orderByDesc('issue_date')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ar.manage', $businessId);
        $data = $request->validate([
            'customer_id' => 'nullable|integer',
            'number' => 'required|string|max:64',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'subtotal' => 'required|integer|min:0',
            'tax' => 'nullable|integer|min:0',
            'total' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);
        if (! empty($data['customer_id']) && ! Customer::where('id', $data['customer_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $invoice = ArInvoice::create(array_merge($data, ['business_id' => $businessId, 'tax' => $data['tax'] ?? 0, 'status' => 'open']));

        return $this->success($invoice, 'Invoice created', 201);
    }

    public function show(Request $request, ArInvoice $arInvoice)
    {
        $businessId = $request->attributes->get('business_id');
        if ($arInvoice->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ar.view', $businessId);

        return $this->success($arInvoice->load('customer', 'payments'));
    }

    public function pay(Request $request, ArInvoice $arInvoice)
    {
        $businessId = $request->attributes->get('business_id');
        if ($arInvoice->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ar.manage', $businessId);
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required|date',
            'method' => 'nullable|string|max:32',
            'reference' => 'nullable|string|max:128',
        ]);
        $payment = ArInvoicePayment::create(array_merge($data, ['ar_invoice_id' => $arInvoice->id]));
        $arInvoice->amount_paid = (int) $arInvoice->amount_paid + (int) $data['amount'];
        if ($arInvoice->amount_paid >= $arInvoice->total) {
            $arInvoice->status = 'paid';
        } elseif ($arInvoice->amount_paid > 0) {
            $arInvoice->status = 'partial';
        }
        $arInvoice->save();
        // update customer balance
        if ($arInvoice->customer_id) {
            $customer = Customer::find($arInvoice->customer_id);
            if ($customer) {
                $customer->balance = max(0, (int) $customer->balance - (int) $data['amount']);
                $customer->save();
            }
        }

        return $this->success($arInvoice->fresh()->load('payments'), 'Payment recorded');
    }

    public function aging(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ar.view', $businessId);
        $today = now()->toDateString();
        $invoices = ArInvoice::where('business_id', $businessId)->where('status', '<>', 'paid')->where('status', '<>', 'voided')->get();
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];
        foreach ($invoices as $inv) {
            $due = $inv->due_date ? $inv->due_date->toDateString() : $today;
            $days = (int) (strtotime($today) - strtotime($due)) / 86400;
            $remaining = (int) $inv->total - (int) $inv->amount_paid;
            if ($days <= 0) {
                $buckets['current'] += $remaining;
            } elseif ($days <= 30) {
                $buckets['1_30'] += $remaining;
            } elseif ($days <= 60) {
                $buckets['31_60'] += $remaining;
            } elseif ($days <= 90) {
                $buckets['61_90'] += $remaining;
            } else {
                $buckets['over_90'] += $remaining;
            }
        }

        return $this->success(['buckets' => $buckets, 'total' => array_sum($buckets)]);
    }
}

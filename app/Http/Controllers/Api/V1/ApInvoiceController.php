<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApInvoice;
use App\Models\ApInvoicePayment;
use App\Models\Supplier;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;

class ApInvoiceController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ap.view', $businessId);
        $q = ApInvoice::where('business_id', $businessId)->with('supplier', 'payments');
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('supplier_id')) {
            $q->where('supplier_id', $request->input('supplier_id'));
        }

        return $this->success($q->orderByDesc('issue_date')->get());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ap.manage', $businessId);
        $data = $request->validate([
            'supplier_id' => 'nullable|integer',
            'number' => 'required|string|max:64',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'subtotal' => 'required|integer|min:0',
            'tax' => 'nullable|integer|min:0',
            'total' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);
        if (! empty($data['supplier_id']) && ! Supplier::where('id', $data['supplier_id'])->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }
        $invoice = ApInvoice::create(array_merge($data, ['business_id' => $businessId, 'tax' => $data['tax'] ?? 0, 'status' => 'open']));

        return $this->success($invoice, 'Invoice created', 201);
    }

    public function show(Request $request, ApInvoice $invoice)
    {
        $businessId = $request->attributes->get('business_id');
        if ($invoice->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ap.view', $businessId);

        return $this->success($invoice->load('supplier', 'payments'));
    }

    public function pay(Request $request, ApInvoice $invoice)
    {
        $businessId = $request->attributes->get('business_id');
        if ($invoice->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ap.manage', $businessId);
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required|date',
            'method' => 'nullable|string|max:32',
            'reference' => 'nullable|string|max:128',
        ]);
        $payment = ApInvoicePayment::create(array_merge($data, ['ap_invoice_id' => $invoice->id]));
        $invoice->amount_paid = (int) $invoice->amount_paid + (int) $data['amount'];
        if ($invoice->amount_paid >= $invoice->total) {
            $invoice->status = 'paid';
        } elseif ($invoice->amount_paid > 0) {
            $invoice->status = 'partial';
        }
        $invoice->save();
        if ($invoice->supplier_id) {
            $supplier = Supplier::find($invoice->supplier_id);
            // supplier balance tracking not in schema; skip
        }

        return $this->success($invoice->fresh()->load('payments'), 'Payment recorded');
    }

    public function aging(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ap.view', $businessId);
        $today = now()->toDateString();
        $invoices = ApInvoice::where('business_id', $businessId)->where('status', '<>', 'paid')->where('status', '<>', 'voided')->get();
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

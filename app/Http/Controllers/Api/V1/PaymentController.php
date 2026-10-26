<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);
        $data = $request->validate([
            'sale_id' => 'required|integer|exists:sales,id',
            'method' => 'required|string|max:64',
            'amount' => 'required|numeric|min:0.0001',
            'reference' => 'nullable|string|max:128',
            'provider' => 'nullable|string|max:64',
            'external_id' => 'nullable|string|max:128',
            'idempotency_key' => 'nullable|string|max:128',
        ]);
        $sale = Sale::where('id', $data['sale_id'])->where('business_id', $businessId)->firstOrFail();

        if (! empty($data['idempotency_key'])) {
            $existing = Payment::where('business_id', $businessId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->success($existing, 'Payment already exists (idempotent)');
            }
        }

        $payment = DB::transaction(function () use ($businessId, $sale, $data) {
            $p = Payment::create([
                'business_id' => $businessId,
                'sale_id' => $sale->id,
                'method' => $data['method'],
                'amount' => $data['amount'],
                'status' => 'completed',
                'reference' => $data['reference'] ?? null,
                'provider' => $data['provider'] ?? null,
                'external_id' => $data['external_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'paid_at' => now(),
            ]);
            // update sale paid_amount
            $paid = Payment::where('sale_id', $sale->id)->where('status', 'completed')->sum('amount');
            $sale->paid_amount = $paid;
            $sale->change_amount = bcsub((string) $paid, (string) $sale->total, 4);
            if (bccomp((string) $sale->change_amount, '0', 4) < 0) {
                $sale->change_amount = '0';
            }
            $sale->save();
            return $p;
        });

        return $this->success($payment, 'Payment recorded', 201);
    }

    public function refund(Request $request, Payment $payment)
    {
        $businessId = $request->attributes->get('business_id');
        if ($payment->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);
        $data = $request->validate(['amount' => 'required|numeric|min:0.0001', 'reason' => 'nullable|string']);
        $amount = (string) $data['amount'];
        // check refundable
        $alreadyRefunded = \App\Models\Refund::where('payment_id', $payment->id)->where('status', 'completed')->sum('amount');
        $refundable = bcsub((string) $payment->amount, (string) $alreadyRefunded, 4);
        if (bccomp($amount, $refundable, 4) > 0) {
            return $this->error('Refund exceeds refundable amount', 422);
        }
        $refund = \App\Models\Refund::create([
            'business_id' => $businessId,
            'sale_id' => $payment->sale_id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);
        return $this->success($refund, 'Refund requested', 201);
    }
}

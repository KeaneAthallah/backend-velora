<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\AuthorizationService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected PaymentService $payments,
    ) {}

    /**
     * Record a batch of payments against a sale (split payments supported).
     */
    public function storeForSale(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);

        $data = $request->validate([
            'payments' => 'required|array|min:1|max:10',
            'payments.*.method' => 'required|string|max:64',
            'payments.*.amount' => 'required|numeric|min:0.0001',
            'payments.*.reference' => 'nullable|string|max:128',
            'payments.*.provider' => 'nullable|string|max:64',
            'payments.*.external_id' => 'nullable|string|max:128',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        try {
            $this->payments->recordPayments($sale, $data['payments'], $data['idempotency_key'] ?? null);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'PAYMENT_ERROR');
        }

        return $this->success(
            $sale->fresh()->load('items', 'payments', 'customer', 'table'),
            'Payments recorded',
            201
        );
    }

    /**
     * Legacy single-payment endpoint kept for older clients. New code should
     * post to /sales/{sale}/payments so split payments stay in one batch.
     */
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

        try {
            $result = $this->payments->recordPayments(
                $sale,
                [[
                    'method' => $data['method'],
                    'amount' => $data['amount'],
                    'reference' => $data['reference'] ?? null,
                    'provider' => $data['provider'] ?? null,
                    'external_id' => $data['external_id'] ?? null,
                ]],
                $data['idempotency_key'] ?? null
            );
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'PAYMENT_ERROR');
        }

        return $this->success($result['payments']->last(), 'Payment recorded', 201);
    }

    public function refund(Request $request, Payment $payment)
    {
        $businessId = $request->attributes->get('business_id');
        if ($payment->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.0001',
            'reason' => 'nullable|string|max:500',
        ]);

        if (in_array($payment->status, ['refunded', 'failed'], true)) {
            return $this->error('This payment cannot be refunded', 422, [], 'REFUND_ERROR');
        }

        $amount = bcadd((string) $data['amount'], '0', 4);
        $reserved = Refund::where('payment_id', $payment->id)
            ->whereIn('status', ['pending', 'approved', 'completed'])
            ->pluck('amount')
            ->reduce(fn (string $carry, mixed $value) => bcadd($carry, bcadd((string) $value, '0', 4), 4), '0.0000');
        $refundable = bcsub(bcadd((string) $payment->amount, '0', 4), $reserved, 4);

        if (bccomp($amount, $refundable, 4) > 0) {
            return $this->error('Refund exceeds the refundable amount', 422, [], 'REFUND_ERROR');
        }

        $refund = Refund::create([
            'business_id' => $businessId,
            'sale_id' => $payment->sale_id,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        return $this->success($refund->load('sale', 'payment'), 'Refund requested', 201);
    }
}

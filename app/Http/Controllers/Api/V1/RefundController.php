<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);

        $query = Refund::where('business_id', $businessId)
            ->with(['sale:id,receipt_number,order_number,total,status,outlet_id', 'payment:id,method,amount,status']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->input('sale_id'));
        }
        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->input('payment_id'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        return $this->success($query->orderByDesc('id')->paginate(50));
    }

    public function approve(Request $request, Refund $refund)
    {
        $businessId = $request->attributes->get('business_id');
        if ($refund->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);
        if ($refund->status !== 'pending') {
            return $this->error('This refund is not pending', 422, [], 'REFUND_ERROR');
        }

        DB::transaction(function () use ($refund, $request) {
            $refund->status = 'completed';
            $refund->approved_by = $request->user()->id;
            $refund->approved_at = now();
            $refund->completed_at = now();
            $refund->save();

            if ($refund->payment) {
                $payment = $refund->payment;
                $totalRefunded = $this->refundedTotal('payment_id', $payment->id);
                $payment->status = bccomp($totalRefunded, bcadd((string) $payment->amount, '0', 4), 4) >= 0
                    ? 'refunded'
                    : 'partially_refunded';
                $payment->save();
            }

            $sale = $refund->sale;
            if ($sale) {
                $totalRefunded = $this->refundedTotal('sale_id', $sale->id);
                $sale->status = bccomp($totalRefunded, bcadd((string) $sale->total, '0', 4), 4) >= 0
                    ? 'refunded'
                    : 'partially_refunded';
                $sale->save();
            }
        });

        return $this->success($refund->fresh()->load('sale', 'payment'), 'Refund approved');
    }

    public function reject(Request $request, Refund $refund)
    {
        $businessId = $request->attributes->get('business_id');
        if ($refund->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);
        if ($refund->status !== 'pending') {
            return $this->error('This refund is not pending', 422, [], 'REFUND_ERROR');
        }

        $refund->status = 'rejected';
        $refund->approved_by = $request->user()->id;
        $refund->save();

        return $this->success($refund->fresh()->load('sale', 'payment'), 'Refund rejected');
    }

    private function refundedTotal(string $column, int $id): string
    {
        return Refund::where($column, $id)
            ->whereIn('status', ['approved', 'completed'])
            ->pluck('amount')
            ->reduce(
                fn (string $carry, mixed $value) => bcadd($carry, bcadd((string) $value, '0', 4), 4),
                '0.0000'
            );
    }
}

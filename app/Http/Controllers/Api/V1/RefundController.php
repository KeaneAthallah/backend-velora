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
        return $this->success(Refund::where('business_id', $businessId)->with('sale', 'payment')->paginate(50));
    }

    public function approve(Request $request, Refund $refund)
    {
        $businessId = $request->attributes->get('business_id');
        if ($refund->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);
        if ($refund->status !== 'pending') return $this->error('Not pending', 422);
        DB::transaction(function () use ($refund, $request) {
            $refund->status = 'approved';
            $refund->approved_by = $request->user()->id;
            $refund->approved_at = now();
            $refund->save();
            // mark payment refunded if full
            if ($refund->payment) {
                $payment = $refund->payment;
                $totalRefunded = \App\Models\Refund::where('payment_id', $payment->id)->where('status', 'approved')->sum('amount');
                if (bccomp((string) $totalRefunded, (string) $payment->amount, 4) >= 0) {
                    $payment->status = 'refunded';
                } else {
                    $payment->status = 'partially_refunded';
                }
                $payment->save();
            }
            // sale status
            $sale = $refund->sale;
            $totalRefundedSale = \App\Models\Refund::where('sale_id', $sale->id)->where('status', 'approved')->sum('amount');
            if (bccomp((string) $totalRefundedSale, (string) $sale->total, 4) >= 0) {
                $sale->status = 'refunded';
            } else {
                $sale->status = 'partially_refunded';
            }
            $sale->save();
            $refund->status = 'completed';
            $refund->completed_at = now();
            $refund->save();
        });
        return $this->success($refund);
    }

    public function reject(Request $request, Refund $refund)
    {
        $businessId = $request->attributes->get('business_id');
        if ($refund->business_id !== $businessId) return $this->error('Not found', 404);
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);
        if ($refund->status !== 'pending') return $this->error('Not pending', 422);
        $refund->status = 'rejected';
        $refund->save();
        return $this->success($refund);
    }
}

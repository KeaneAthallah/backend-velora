<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\AuthorizationService;
use App\Services\RecipeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected RecipeService $recipes,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);

        $query = Refund::where('business_id', $businessId)
            ->with([
                'sale:id,receipt_number,order_number,total,status,outlet_id',
                'payment:id,method,amount,status',
                'items:id,refund_id,sale_item_id,quantity,reason',
                'items.saleItem:id,product_name,sku,quantity,unit_price,line_total',
            ]);

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

        try {
            DB::transaction(function () use ($refund, $request, $businessId) {
                // Lock the refund so a double-click cannot approve it twice and
                // recompute payment/sale status from a half-applied ledger.
                $locked = Refund::whereKey($refund->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'pending') {
                    throw new \RuntimeException('This refund is not pending');
                }

                $locked->status = 'completed';
                $locked->approved_by = $request->user()->id;
                $locked->approved_at = now();
                $locked->completed_at = now();
                $locked->save();

                if ($locked->payment_id) {
                    $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->first();
                    if ($payment) {
                        $totalRefunded = $this->refundedTotal('payment_id', $payment->id);
                        $payment->status = bccomp($totalRefunded, bcadd((string) $payment->amount, '0', 4), 4) >= 0
                            ? 'refunded'
                            : 'partially_refunded';
                        $payment->save();
                    }
                }

                if ($locked->sale_id) {
                    $sale = Sale::whereKey($locked->sale_id)->lockForUpdate()->first();
                    if ($sale) {
                        $totalRefunded = $this->refundedTotal('sale_id', $sale->id);
                        $fullyRefunded = bccomp($totalRefunded, bcadd((string) $sale->total, '0', 4), 4) >= 0;
                        $sale->status = $fullyRefunded ? 'refunded' : 'partially_refunded';
                        $sale->save();

                        // Returned lines go back to stock per line, which
                        // covers partial refunds. An amount-only refund has no
                        // quantities to map, so it only reverses when it covers
                        // the whole sale.
                        $returned = $locked->items()->get();

                        if ($returned->isNotEmpty()) {
                            $quantities = [];
                            foreach ($returned as $line) {
                                $quantities[$line->sale_item_id] = bcadd(
                                    $quantities[$line->sale_item_id] ?? '0',
                                    (string) $line->quantity,
                                    4,
                                );
                            }

                            $this->recipes->reverseSaleItems(
                                $sale,
                                $quantities,
                                'Refund #'.$locked->id,
                                $request->user()->id,
                            );
                        } elseif ($fullyRefunded) {
                            $this->recipes->reverseForSale($sale, 'Refund #'.$locked->id, $request->user()->id);
                        }
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'REFUND_ERROR');
        }

        return $this->success($refund->fresh()->load('sale', 'payment', 'items.saleItem'), 'Refund approved');
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

        return $this->success($refund->fresh()->load('sale', 'payment', 'items.saleItem'), 'Refund rejected');
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

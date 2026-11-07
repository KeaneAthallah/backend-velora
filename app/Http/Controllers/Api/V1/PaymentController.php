<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\AuthorizationService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Request a refund.
     *
     * `items` lists the sale lines coming back; the amount is optional then and
     * defaults to those lines' share of the sale total. Returning lines is what
     * lets approval put the recipe ingredients back on a *partial* refund —
     * an amount alone cannot be mapped to quantities.
     */
    public function refund(Request $request, Payment $payment)
    {
        $businessId = $request->attributes->get('business_id');
        if ($payment->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.refund', $businessId);

        $data = $request->validate([
            'amount' => 'nullable|required_without:items|numeric|min:0.0001',
            'reason' => 'nullable|string|max:500',
            'items' => 'nullable|array|min:1',
            'items.*.sale_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.reason' => 'nullable|string|max:500',
        ]);

        try {
            $refund = DB::transaction(function () use ($payment, $businessId, $data, $request) {
                // Lock the payment so two refund requests cannot both count the
                // same remaining refundable balance and over-refund it.
                $locked = Payment::whereKey($payment->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($locked->status, ['refunded', 'failed'], true)) {
                    throw new RuntimeException('This payment cannot be refunded');
                }

                $sale = Sale::whereKey($locked->sale_id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lines = $this->refundLines($sale, $data['items'] ?? []);

                $amount = isset($data['amount'])
                    ? bcadd((string) $data['amount'], '0', 4)
                    : $lines['total'];

                if (bccomp($amount, '0', 4) <= 0) {
                    throw new RuntimeException('Enter a refund amount greater than zero');
                }

                $reserved = Refund::where('payment_id', $locked->id)
                    ->whereIn('status', ['pending', 'approved', 'completed'])
                    ->pluck('amount')
                    ->reduce(fn (string $carry, mixed $value) => bcadd($carry, bcadd((string) $value, '0', 4), 4), '0.0000');
                $refundable = bcsub(bcadd((string) $locked->amount, '0', 4), $reserved, 4);

                if (bccomp($amount, $refundable, 4) > 0) {
                    throw new RuntimeException('Refund exceeds the refundable amount');
                }

                $refund = Refund::create([
                    'business_id' => $businessId,
                    'sale_id' => $locked->sale_id,
                    'payment_id' => $locked->id,
                    'amount' => $amount,
                    'reason' => $data['reason'] ?? null,
                    'status' => 'pending',
                    'requested_by' => $request->user()->id,
                ]);

                foreach ($lines['items'] as $line) {
                    $refund->items()->create($line);
                }

                return $refund;
            });
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'REFUND_ERROR');
        }

        return $this->success($refund->load('sale', 'payment', 'items.saleItem'), 'Refund requested', 201);
    }

    /**
     * Validate returned lines against the sale and price them.
     *
     * Pending refunds already reserve their quantities, so a second request
     * cannot return the same unit twice.
     *
     * @param  array<int, array{sale_item_id: int|string, quantity: int|string, reason?: string|null}>  $items
     * @return array{total: string, items: array<int, array{sale_item_id: int, quantity: string, reason: string|null}>}
     */
    private function refundLines(Sale $sale, array $items): array
    {
        if ($items === []) {
            return ['total' => '0', 'items' => []];
        }

        $saleItems = SaleItem::where('sale_id', $sale->id)->get()->keyBy('id');

        $alreadyReturned = RefundItem::query()
            ->whereIn('sale_item_id', $saleItems->keys()->all())
            ->whereHas('refund', fn ($query) => $query->whereIn('status', ['pending', 'approved', 'completed']))
            ->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(quantity) as returned')
            ->pluck('returned', 'sale_item_id');

        $total = '0';
        $claimed = [];
        $lines = [];

        foreach ($items as $line) {
            $saleItemId = (int) $line['sale_item_id'];
            $item = $saleItems->get($saleItemId);
            if (! $item) {
                throw new RuntimeException('That item is not on this sale');
            }

            $quantity = bcadd((string) $line['quantity'], '0', 4);
            $claimed[$saleItemId] = bcadd($claimed[$saleItemId] ?? '0', $quantity, 4);

            $sold = bcadd((string) $item->quantity, '0', 4);
            $taken = bcadd(bcadd((string) ($alreadyReturned[$saleItemId] ?? '0'), '0', 4), $claimed[$saleItemId], 4);

            if (bccomp($sold, '0', 4) <= 0 || bccomp($taken, $sold, 4) > 0) {
                throw new RuntimeException("Refunding more of {$item->product_name} than was sold");
            }

            // Price the line by its share of the stored line total, which
            // already carries its discount and tax. Multiply before dividing
            // and round half-up, or 90,000 / 3 comes out as 29,999.9997.
            $raw = bcdiv(bcmul(bcadd((string) $item->line_total, '0', 4), $quantity, 10), $sold, 10);
            $share = bcadd($raw, '0.00005', 4);
            $total = bcadd($total, $share, 4);

            $lines[] = [
                'sale_item_id' => $saleItemId,
                'quantity' => $quantity,
                'reason' => $line['reason'] ?? null,
            ];
        }

        return ['total' => $total, 'items' => $lines];
    }
}

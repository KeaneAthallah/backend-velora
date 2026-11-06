<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\AuthorizationService;
use App\Services\ChannelService;
use App\Services\LoyaltyService;
use App\Services\PaymentService;
use App\Services\PromotionService;
use App\Services\RecipeService;
use App\Services\SaleService;
use App\Services\Subscription\SubscriptionService;
use App\Services\WebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected SaleService $saleService,
        protected RecipeService $recipeService,
        protected PaymentService $payments,
        protected PromotionService $promotions,
        protected LoyaltyService $loyalty,
        protected ChannelService $channels,
        protected SubscriptionService $subscriptions,
        protected WebhookService $webhooks,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);
        $q = Sale::where('business_id', $businessId)->with('items', 'payments', 'table', 'salesChannel');
        // Open tabs live on the order board; the receipt ledger only shows
        // finalised or voided sales unless a status is requested explicitly.
        if (! $request->filled('status') && ! $request->filled('order_status')) {
            $q->where('status', '<>', 'draft');
        }
        if ($request->filled('outlet_id')) {
            $q->where('outlet_id', $request->input('outlet_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('order_status')) {
            $q->where('order_status', $request->input('order_status'));
        }
        if ($request->filled('order_type')) {
            $q->where('order_type', $request->input('order_type'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $q->where(function ($sub) use ($term) {
                $sub->where('receipt_number', 'like', $term)->orWhere('order_number', 'like', $term);
            });
        }

        return $this->success($q->orderByDesc('id')->paginate(50));
    }

    public function show(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);

        return $this->success($sale->load('items', 'payments', 'refunds', 'customer', 'table', 'salesChannel'));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'sales.create', $businessId);
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'device_id' => [
                'nullable',
                'integer',
                Rule::exists('devices', 'id')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId),
            ],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('register_shifts', 'id')
                    ->where('business_id', $businessId)
                    ->where('outlet_id', $outletId),
            ],
            'table_id' => 'nullable|integer',
            'tax_rate_id' => [
                'nullable',
                'integer',
                Rule::exists('tax_rates', 'id')->where('business_id', $businessId),
            ],
            'promotion_code' => 'nullable|string|max:64',
            'redeem_points' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:128',
            'notes' => 'nullable|string',
            'order_type' => 'nullable|in:dine_in,takeaway,delivery,pickup',
            'fulfilment' => 'nullable|array',
            'fulfilment.contact_name' => 'nullable|string|max:255',
            'fulfilment.contact_phone' => 'nullable|string|max:32',
            'fulfilment.address' => 'nullable|string|max:500',
            'fulfilment.scheduled_at' => 'nullable|date',
            'fulfilment.instructions' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.option_ids' => 'nullable|array',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:255',
            'items.*.unit_price_override' => 'nullable|numeric|min:0',
            'payments' => 'nullable|array|max:10',
            'payments.*.method' => 'required|string|max:64',
            'payments.*.amount' => 'required|numeric|min:0.0001',
            'payments.*.reference' => 'nullable|string|max:128',
            'payments.*.provider' => 'nullable|string|max:64',
            'payments.*.external_id' => 'nullable|string|max:128',
        ]);

        $orderType = $data['order_type'] ?? 'takeaway';

        $customerId = $data['customer_id'] ?? null;
        if ($customerId && ! Customer::where('id', $customerId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        if ($orderType === 'delivery' && (empty($data['fulfilment']['address']) || empty($data['fulfilment']['contact_phone']))) {
            return $this->error('Delivery orders need a contact phone and address', 422, [], 'ORDER_ERROR');
        }

        $tableId = null;
        if (! empty($data['table_id'])) {
            if ($orderType !== 'dine_in') {
                return $this->error('Tables are only for dine-in orders', 422, [], 'TABLE_ERROR');
            }
            $table = DiningTable::where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->find($data['table_id']);
            if (! $table) {
                return $this->error('Not found', 404);
            }
            if (! $table->is_active) {
                return $this->error('This table is inactive', 422, [], 'TABLE_ERROR');
            }
            if ($table->activeOrder()->exists()) {
                return $this->error('This table already has an open order', 422, [], 'TABLE_ERROR');
            }
            $tableId = $table->id;
        }

        // idempotency check
        if (! empty($data['idempotency_key'])) {
            $existing = Sale::where('business_id', $businessId)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->success($existing->load('items', 'payments'), 'Sale already exists (idempotent)');
            }
        }

        // Completed sales count against the monthly transaction quota. The
        // check runs after the idempotent replay short-circuit so a retry of an
        // already-recorded sale is never blocked by a full quota.
        $this->subscriptions->enforceLimit($businessId, 'transactions_per_month');

        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->firstOrFail();

        try {
            $sale = DB::transaction(function () use ($businessId, $outletId, $outlet, $data, $orderType, $tableId, $customerId, $request) {
                $priced = $this->saleService->priceItems(
                    $data['items'],
                    $businessId,
                    $outletId,
                    $data['tax_rate_id'] ?? null,
                    [
                        'promotion_code' => $data['promotion_code'] ?? null,
                        'customer_id' => $customerId,
                        'redeem_points' => (string) ($data['redeem_points'] ?? '0'),
                    ],
                );

                $sale = Sale::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'device_id' => $data['device_id'] ?? null,
                    'cashier_id' => $request->user()->id,
                    'customer_id' => $customerId,
                    'table_id' => $tableId,
                    'shift_id' => $data['shift_id'] ?? null,
                    'receipt_number' => 'PENDING',
                    'order_number' => $this->saleService->nextOrderNumber($outlet, $orderType),
                    'order_type' => $orderType,
                    'order_status' => 'completed',
                    'status' => 'completed',
                    'paid_amount' => 0,
                    'change_amount' => 0,
                    'notes' => $data['notes'] ?? null,
                    'fulfilment' => $data['fulfilment'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                    'completed_at' => now(),
                ]);

                $this->saleService->attachItems($sale, $priced['items']);
                $this->saleService->applyPricing($sale, $priced);

                $sale->receipt_number = $this->saleService->receiptNumber($sale, $outlet);
                $sale->save();

                // deduct product stock and recipe ingredients
                $this->saleService->deductStock($sale, $outletId, $request->user()->id);

                // The promotion usage and loyalty ledger are only written once
                // the sale is final, never while a cart is still open.
                $this->promotions->recordRedemption($sale);
                $this->loyalty->fulfilRedemption($sale);
                $this->loyalty->earn($sale);

                // Payments are optional here; the POS can also post to
                // /sales/{sale}/payments. Recording them in this transaction
                // keeps a paid sale from existing without its payment rows.
                if (! empty($data['payments'])) {
                    $this->payments->recordPayments(
                        $sale,
                        $data['payments'],
                        $data['idempotency_key'] ?? null
                    );
                }

                return $sale;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'SALE_ERROR');
        } catch (QueryException $e) {
            // Two submissions with the same idempotency key can pass the
            // pre-check together. The unique (business_id, idempotency_key)
            // index lets only one insert win; return the winner instead of a
            // 500 so a retried checkout is safe.
            if (! empty($data['idempotency_key'])) {
                $existing = Sale::where('business_id', $businessId)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    return $this->success($existing->load('items', 'payments'), 'Sale already exists (idempotent)');
                }
            }

            throw $e;
        }

        // Outbound webhooks are best-effort: a broken endpoint must never fail
        // a sale that is already committed.
        try {
            $this->webhooks->dispatch($businessId, 'sale.completed', [
                'sale_id' => (string) $sale->id,
                'receipt_number' => $sale->receipt_number,
                'order_number' => $sale->order_number,
                'outlet_id' => (string) $sale->outlet_id,
                'total' => (string) $sale->total,
                'status' => $sale->status,
            ]);
        } catch (\Throwable) {
            // delivery failures are recorded against the endpoint
        }

        return $this->success($sale->load('items', 'payments', 'table'), 'Sale completed', 201);
    }

    public function void(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.void', $businessId);
        if ($sale->status !== 'completed') {
            return $this->error('Only completed sales can be voided', 422);
        }
        $data = $request->validate(['reason' => 'required|string']);

        try {
            DB::transaction(function () use ($sale, $data, $request, $businessId) {
                // Lock the sale before restoring stock: two concurrent voids
                // must not both pass the status check and restore twice.
                $locked = Sale::whereKey($sale->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->status !== 'completed') {
                    throw new \RuntimeException('Only completed sales can be voided');
                }

                $locked->load('items');

                // restore inventory
                foreach ($locked->items as $item) {
                    $product = Product::find($item->product_id);
                    if ($product && $product->track_inventory) {
                        $bal = InventoryBalance::lockForUpdate()->firstOrCreate(
                            ['outlet_id' => $locked->outlet_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id],
                            ['business_id' => $businessId, 'quantity' => 0, 'reserved_quantity' => 0]
                        );
                        $bal->quantity = bcadd((string) $bal->quantity, (string) $item->quantity, 4);
                        $bal->save();
                        InventoryMovement::create([
                            'business_id' => $businessId,
                            'outlet_id' => $locked->outlet_id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'type' => 'void',
                            'quantity' => $item->quantity,
                            'balance_after' => $bal->quantity,
                            'reference_type' => 'sale',
                            'reference_id' => $locked->id,
                            'reason' => $data['reason'],
                            'actor_id' => $request->user()->id,
                        ]);
                    }
                }
                $locked->status = 'voided';
                $locked->order_status = 'cancelled';
                $locked->voided_at = now();
                $locked->voided_by = $request->user()->id;
                $locked->void_reason = $data['reason'];
                $locked->save();

                // Voiding hands the money back, so completed payments leave the
                // paid ledger rather than lingering as collected revenue.
                Payment::where('sale_id', $locked->id)
                    ->where('status', 'completed')
                    ->update(['status' => 'refunded']);

                $this->recipeService->reverseForSale($locked, $data['reason'], $request->user()->id);

                // Give the promotion usage back and put loyalty points where they were.
                $this->promotions->releaseRedemption($locked);
                $this->loyalty->reverseForSale($locked, $data['reason']);
                $this->channels->syncOrderStatus($locked, 'cancelled');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'SALE_ERROR');
        }

        try {
            $this->webhooks->dispatch($businessId, 'sale.voided', [
                'sale_id' => (string) $sale->id,
                'receipt_number' => $sale->receipt_number,
                'reason' => $data['reason'],
            ]);
        } catch (\Throwable) {
            // delivery failures are recorded against the endpoint
        }

        return $this->success($sale->fresh()->load('items', 'payments'), 'Voided');
    }

    public function receipt(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'sales.view', $businessId);

        return $this->success($sale->load('items', 'payments', 'customer', 'outlet', 'cashier'));
    }
}

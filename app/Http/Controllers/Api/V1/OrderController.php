<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesChannel;
use App\Services\AuthorizationService;
use App\Services\ChannelService;
use App\Services\KitchenService;
use App\Services\LoyaltyService;
use App\Services\PaymentService;
use App\Services\PromotionService;
use App\Services\SaleService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /** Forward-only kitchen progression used by the order board and KDS. */
    private const STATUS_RANK = [
        'open' => 0,
        'in_kitchen' => 1,
        'ready' => 2,
        'served' => 3,
        'completed' => 4,
        'cancelled' => -1,
    ];

    public function __construct(
        protected AuthorizationService $authz,
        protected SaleService $saleService,
        protected KitchenService $kitchen,
        protected PaymentService $payments,
        protected PromotionService $promotions,
        protected LoyaltyService $loyalty,
        protected ChannelService $channels,
        protected SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'orders.view', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if ($outletId && ! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $query = Sale::where('business_id', $businessId)
            ->whereNotNull('order_number')
            ->with('items', 'customer', 'outlet', 'table', 'salesChannel');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $status = $request->input('status', 'active');
        if ($status === 'active') {
            $query->activeOrders();
        } elseif ($status !== 'all') {
            $query->where('order_status', $status);
        }

        if ($request->filled('order_type') && in_array($request->input('order_type'), Sale::ORDER_TYPES, true)) {
            $query->where('order_type', $request->input('order_type'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('order_number', 'like', $term)
                    ->orWhere('receipt_number', 'like', $term);
            });
        }

        return $this->success($query->orderBy('created_at')->orderBy('id')->paginate(100));
    }

    public function show(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.view', $businessId);

        return $this->success($sale->load('items', 'customer', 'outlet', 'payments', 'table', 'salesChannel'));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'orders.create', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $this->validateOrder($request);

        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->first();
        if (! $outlet) {
            return $this->error('Not found', 404);
        }

        if (! empty($data['customer_id']) && ! $this->customerBelongsTo($businessId, (int) $data['customer_id'])) {
            return $this->error('Not found', 404);
        }

        $channel = null;
        if (! empty($data['sales_channel_id'])) {
            $channel = SalesChannel::where('business_id', $businessId)->find((int) $data['sales_channel_id']);
            if (! $channel) {
                return $this->error('Not found', 404);
            }
            if (! $channel->is_active) {
                return $this->error('This sales channel is inactive', 422, [], 'CHANNEL_ERROR');
            }
        }

        if (! empty($data['idempotency_key'])) {
            $existing = Sale::where('business_id', $businessId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return $this->success($existing->load('items', 'customer', 'table'), 'Order already exists (idempotent)');
            }
        }

        $orderType = $data['order_type'];

        if ($orderType === 'delivery' && (empty($data['fulfilment']['address']) || empty($data['fulfilment']['contact_phone']))) {
            return $this->error('Delivery orders need a contact phone and address', 422, [], 'ORDER_ERROR');
        }

        $tableId = null;
        if (! empty($data['table_id'])) {
            $table = $this->findTable($businessId, $outletId, (int) $data['table_id']);
            if (! $table) {
                return $this->error('Not found', 404);
            }
            $conflict = $this->tableConflict($table, $orderType);
            if ($conflict) {
                return $this->error($conflict, 422, [], 'TABLE_ERROR');
            }
            $tableId = $table->id;
        }

        try {
            $sale = DB::transaction(function () use ($businessId, $outletId, $outlet, $data, $orderType, $tableId, $request, $channel) {
                $priced = $this->saleService->priceItems(
                    $data['items'],
                    $businessId,
                    $outletId,
                    $data['tax_rate_id'] ?? null,
                    [
                        'promotion_code' => $data['promotion_code'] ?? null,
                        'customer_id' => $data['customer_id'] ?? null,
                        'redeem_points' => (string) ($data['redeem_points'] ?? '0'),
                    ],
                );

                $orderNumber = $this->saleService->nextOrderNumber($outlet, $orderType);

                $sale = Sale::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'cashier_id' => $request->user()->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'table_id' => $tableId,
                    'receipt_number' => 'OPEN-'.$orderNumber,
                    'order_number' => $orderNumber,
                    'order_type' => $orderType,
                    'order_status' => 'open',
                    'status' => 'draft',
                    'sales_channel_id' => $channel?->id,
                    'channel_order_ref' => $data['channel_order_ref'] ?? null,
                    'channel_status' => $channel ? 'accepted' : null,
                    'channel_commission_percent' => $channel?->commission_percent ?? 0,
                    'paid_amount' => 0,
                    'change_amount' => 0,
                    'notes' => $data['notes'] ?? null,
                    'fulfilment' => $data['fulfilment'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);

                $this->saleService->attachItems($sale, $priced['items']);
                $this->saleService->applyPricing($sale, $priced);

                if ($channel) {
                    $this->channels->applyCommission($sale);
                }

                return $sale;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'ORDER_ERROR');
        }

        return $this->success($sale->load('items', 'customer', 'table', 'salesChannel'), 'Order opened', 201);
    }

    public function update(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.manage', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Only open orders can be edited', 422, [], 'ORDER_ERROR');
        }

        $data = $request->validate([
            'order_type' => ['sometimes', Rule::in(Sale::ORDER_TYPES)],
            'customer_id' => 'sometimes|nullable|integer|exists:customers,id',
            'table_id' => 'sometimes|nullable|integer',
            'tax_rate_id' => 'nullable|integer',
            'promotion_code' => 'sometimes|nullable|string|max:64',
            'redeem_points' => 'sometimes|nullable|numeric|min:0',
            'apply_promotions' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string',
            'fulfilment' => 'sometimes|nullable|array',
            'fulfilment.contact_name' => 'nullable|string|max:255',
            'fulfilment.contact_phone' => 'nullable|string|max:32',
            'fulfilment.address' => 'nullable|string|max:500',
            'fulfilment.scheduled_at' => 'nullable|date',
            'fulfilment.instructions' => 'nullable|string|max:500',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.option_ids' => 'nullable|array',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id']) && ! $this->customerBelongsTo($businessId, (int) $data['customer_id'])) {
            return $this->error('Not found', 404);
        }

        $orderType = $data['order_type'] ?? $sale->order_type;
        $fulfilment = array_key_exists('fulfilment', $data) ? $data['fulfilment'] : $sale->fulfilment;

        if ($orderType === 'delivery' && (empty($fulfilment['address']) || empty($fulfilment['contact_phone']))) {
            return $this->error('Delivery orders need a contact phone and address', 422, [], 'ORDER_ERROR');
        }

        $tableId = $sale->table_id;
        if (array_key_exists('table_id', $data)) {
            $tableId = $data['table_id'] ? (int) $data['table_id'] : null;
            if ($tableId) {
                $table = $this->findTable($businessId, $sale->outlet_id, $tableId);
                if (! $table) {
                    return $this->error('Not found', 404);
                }
                $conflict = $this->tableConflict($table, $orderType, $sale->id);
                if ($conflict) {
                    return $this->error($conflict, 422, [], 'TABLE_ERROR');
                }
            }
        } elseif ($orderType !== 'dine_in') {
            // Switching away from dine-in releases the table for other guests.
            $tableId = null;
        }

        try {
            DB::transaction(function () use ($sale, $data, $businessId, $orderType, $fulfilment, $tableId) {
                $sale->order_type = $orderType;
                $sale->fulfilment = $fulfilment;
                $sale->table_id = $tableId;
                if (array_key_exists('customer_id', $data)) {
                    $sale->customer_id = $data['customer_id'];
                }
                if (array_key_exists('notes', $data)) {
                    $sale->notes = $data['notes'];
                }
                $sale->save();

                $reprice = ! empty($data['items'])
                    || array_key_exists('promotion_code', $data)
                    || array_key_exists('redeem_points', $data)
                    || array_key_exists('customer_id', $data);

                if ($reprice) {
                    $items = ! empty($data['items'])
                        ? $data['items']
                        : $this->saleItemsToInput($sale->items);

                    $priced = $this->saleService->priceItems(
                        $items,
                        $businessId,
                        $sale->outlet_id,
                        $data['tax_rate_id'] ?? null,
                        [
                            'promotion_code' => array_key_exists('promotion_code', $data)
                                ? $data['promotion_code']
                                : $sale->promotion_code,
                            'customer_id' => $sale->customer_id,
                            'redeem_points' => array_key_exists('redeem_points', $data)
                                ? (string) ($data['redeem_points'] ?? '0')
                                : (string) $sale->loyalty_points_redeemed,
                            'apply_promotions' => $data['apply_promotions'] ?? true,
                        ],
                    );
                    $this->saleService->replaceItems($sale, $priced);
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'ORDER_ERROR');
        }

        return $this->success($sale->fresh()->load('items', 'customer', 'table'), 'Order updated');
    }

    public function status(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.manage', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Order is no longer active', 422, [], 'ORDER_ERROR');
        }

        $data = $request->validate([
            'status' => 'required|in:in_kitchen,ready,served',
        ]);

        $next = $data['status'];
        $current = $sale->order_status;

        if ($next === $current) {
            return $this->success($sale->load('items', 'customer', 'table'));
        }

        if (self::STATUS_RANK[$next] <= self::STATUS_RANK[$current]) {
            return $this->error('Orders can only move forward through the kitchen', 422, [], 'ORDER_ERROR');
        }

        $sale->order_status = $next;
        $sale->fired_at = $sale->fired_at ?? ($next === 'in_kitchen' ? now() : $sale->fired_at);
        $sale->ready_at = $sale->ready_at ?? ($next === 'ready' ? now() : null);
        $sale->served_at = $next === 'served' ? now() : null;
        $sale->save();

        if ($next === 'in_kitchen') {
            // Firing sends the order to every station that owns one of its items.
            $this->kitchen->fireOrder($sale);
        } else {
            $this->kitchen->syncFromOrderStatus($sale, $next);
        }

        // Channel partners need ready and dispatched updates pushed back out.
        $this->channels->syncOrderStatus($sale, $next);

        return $this->success($sale->load('items', 'customer', 'table', 'salesChannel'), 'Order updated');
    }

    public function checkout(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.manage', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Only open orders can be checked out', 422, [], 'ORDER_ERROR');
        }

        $sale->unsetRelation('items');
        $sale->load('items');
        if ($sale->items->isEmpty()) {
            return $this->error('Add at least one item before checkout', 422, [], 'ORDER_ERROR');
        }

        $data = $request->validate([
            'payments' => 'nullable|array|max:10',
            'payments.*.method' => 'required|string|max:64',
            'payments.*.amount' => 'required|numeric|min:0.0001',
            'payments.*.reference' => 'nullable|string|max:128',
            'payments.*.provider' => 'nullable|string|max:64',
            'payments.*.external_id' => 'nullable|string|max:128',
            'idempotency_key' => 'nullable|string|max:128',
            'promotion_code' => 'sometimes|nullable|string|max:64',
            'redeem_points' => 'sometimes|nullable|numeric|min:0',
            'customer_id' => 'sometimes|nullable|integer|exists:customers,id',
        ]);

        if (array_key_exists('customer_id', $data) && ! empty($data['customer_id']) && ! $this->customerBelongsTo($businessId, (int) $data['customer_id'])) {
            return $this->error('Not found', 404);
        }

        // Finalising an open tab creates a completed sale, so it consumes the
        // same monthly transaction quota as a direct sale.
        $this->subscriptions->enforceLimit($businessId, 'transactions_per_month');

        try {
            DB::transaction(function () use ($sale, $data, $request, $businessId) {
                // Lock the order so concurrent checkouts cannot deduct stock
                // and record payments twice.
                $locked = Sale::whereKey($sale->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! $locked->isOpenOrder()) {
                    throw new \RuntimeException('Only open orders can be checked out');
                }
                $locked->unsetRelation('items');
                $locked->load('items');
                if ($locked->items->isEmpty()) {
                    throw new \RuntimeException('Add at least one item before checkout');
                }
                $sale = $locked;

                if (array_key_exists('customer_id', $data)) {
                    $sale->customer_id = $data['customer_id'];
                    $sale->save();
                }

                // A promotion or redemption can be chosen at the till, so the
                // lines are priced again before the sale is finalised.
                $reprice = array_key_exists('promotion_code', $data) || array_key_exists('redeem_points', $data);

                if ($reprice) {
                    $priced = $this->saleService->priceItems(
                        $this->saleItemsToInput($sale->items),
                        $sale->business_id,
                        $sale->outlet_id,
                        null,
                        [
                            'promotion_code' => array_key_exists('promotion_code', $data)
                                ? $data['promotion_code']
                                : $sale->promotion_code,
                            'customer_id' => $sale->customer_id,
                            'redeem_points' => array_key_exists('redeem_points', $data)
                                ? (string) ($data['redeem_points'] ?? '0')
                                : (string) $sale->loyalty_points_redeemed,
                        ],
                    );
                    $this->saleService->replaceItems($sale, $priced);
                }

                $this->saleService->deductStock($sale, $sale->outlet_id, $request->user()->id);

                $outlet = Outlet::findOrFail($sale->outlet_id);
                $sale->receipt_number = $this->saleService->receiptNumber($sale, $outlet);
                $sale->status = 'completed';
                $sale->order_status = 'completed';
                $sale->served_at = $sale->served_at ?? now();
                $sale->completed_at = now();
                $sale->save();

                $this->kitchen->syncFromOrderStatus($sale, 'completed');

                $this->promotions->recordRedemption($sale);
                $this->loyalty->fulfilRedemption($sale);
                $this->loyalty->earn($sale);

                if (! empty($data['payments'])) {
                    $this->payments->recordPayments(
                        $sale,
                        $data['payments'],
                        $data['idempotency_key'] ?? null
                    );
                } elseif ($sale->sales_channel_id) {
                    // Marketplace orders are settled by the channel itself.
                    $this->channels->settlePayment($sale, $this->payments);
                }

                $this->channels->syncOrderStatus($sale, 'completed');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'SALE_ERROR');
        }

        return $this->success($sale->fresh()->load('items', 'payments', 'customer', 'table', 'salesChannel'), 'Order checked out');
    }

    public function cancel(Request $request, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($sale->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.cancel', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Only open orders can be cancelled', 422, [], 'ORDER_ERROR');
        }

        $data = $request->validate(['reason' => 'required|string|max:500']);

        try {
            DB::transaction(function () use ($sale, $data, $request, $businessId) {
                $locked = Sale::whereKey($sale->id)
                    ->where('business_id', $businessId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->isOpenOrder()) {
                    throw new \RuntimeException('Only open orders can be cancelled');
                }

                $locked->status = 'voided';
                $locked->order_status = 'cancelled';
                $locked->voided_at = now();
                $locked->voided_by = $request->user()->id;
                $locked->void_reason = $data['reason'];
                $locked->save();

                $this->kitchen->voidForSale($locked);
                $this->channels->syncOrderStatus($locked, 'cancelled');
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'ORDER_ERROR');
        }

        return $this->success($sale->fresh()->load('items', 'customer', 'table', 'salesChannel'), 'Order cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request): array
    {
        $data = $request->validate([
            'order_type' => ['required', Rule::in(Sale::ORDER_TYPES)],
            'customer_id' => 'nullable|integer|exists:customers,id',
            'table_id' => 'nullable|integer',
            'tax_rate_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:128',
            'sales_channel_id' => 'nullable|integer',
            'channel_order_ref' => 'nullable|string|max:128',
            'fulfilment' => 'nullable|array',
            'fulfilment.contact_name' => 'nullable|string|max:255',
            'fulfilment.contact_phone' => 'nullable|string|max:32',
            'fulfilment.address' => 'nullable|string|max:500',
            'fulfilment.scheduled_at' => 'nullable|date',
            'fulfilment.instructions' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required_with:items|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.option_ids' => 'nullable|array',
            'items.*.option_ids.*' => 'integer',
            'items.*.notes' => 'nullable|string|max:255',
            'promotion_code' => 'nullable|string|max:64',
            'redeem_points' => 'nullable|numeric|min:0',
        ]);

        return $data;
    }

    /**
     * Turn persisted lines back into the shape the pricing routine expects.
     *
     * @param  iterable<int, SaleItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function saleItemsToInput(iterable $items): array
    {
        $input = [];

        foreach ($items as $item) {
            $input[] = [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => (string) $item->quantity,
                'discount_amount' => (string) ($item->line_discount_amount ?? $item->discount_amount),
                'option_ids' => $item->option_ids ?? [],
                'notes' => $item->notes,
            ];
        }

        return $input;
    }

    private function customerBelongsTo(int $businessId, int $customerId): bool
    {
        return Customer::where('id', $customerId)->where('business_id', $businessId)->exists();
    }

    private function findTable(int $businessId, int $outletId, int $tableId): ?DiningTable
    {
        return DiningTable::where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->find($tableId);
    }

    /**
     * Returns a human readable reason when the table cannot take this order.
     */
    private function tableConflict(DiningTable $table, string $orderType, ?int $ignoreSaleId = null): ?string
    {
        if ($orderType !== 'dine_in') {
            return 'Tables are only for dine-in orders';
        }
        if (! $table->is_active) {
            return 'This table is inactive';
        }

        $query = $table->activeOrder();
        if ($ignoreSaleId) {
            $query->whereKeyNot($ignoreSaleId);
        }

        return $query->exists() ? 'This table already has an open order' : null;
    }
}

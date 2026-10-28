<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Sale;
use App\Services\AuthorizationService;
use App\Services\SaleService;
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
            ->with('items', 'customer', 'outlet');

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

        return $this->success($sale->load('items', 'customer', 'outlet', 'payments'));
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

        if (! empty($data['idempotency_key'])) {
            $existing = Sale::where('business_id', $businessId)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing) {
                return $this->success($existing->load('items', 'customer'), 'Order already exists (idempotent)');
            }
        }

        $orderType = $data['order_type'];

        if ($orderType === 'delivery' && (empty($data['fulfilment']['address']) || empty($data['fulfilment']['contact_phone']))) {
            return $this->error('Delivery orders need a contact phone and address', 422, [], 'ORDER_ERROR');
        }

        try {
            $sale = DB::transaction(function () use ($businessId, $outletId, $outlet, $data, $orderType, $request) {
                $priced = $this->saleService->priceItems(
                    $data['items'],
                    $businessId,
                    $outletId,
                    $data['tax_rate_id'] ?? null,
                );

                $orderNumber = $this->saleService->nextOrderNumber($outlet, $orderType);

                $sale = Sale::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'cashier_id' => $request->user()->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'receipt_number' => 'OPEN-'.$orderNumber,
                    'order_number' => $orderNumber,
                    'order_type' => $orderType,
                    'order_status' => 'open',
                    'status' => 'draft',
                    'subtotal' => $priced['subtotal'],
                    'discount_amount' => $priced['discount_amount'],
                    'tax_amount' => $priced['tax_amount'],
                    'total' => $priced['total'],
                    'paid_amount' => 0,
                    'change_amount' => 0,
                    'notes' => $data['notes'] ?? null,
                    'fulfilment' => $data['fulfilment'] ?? null,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);

                $this->saleService->attachItems($sale, $priced['items']);

                return $sale;
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'ORDER_ERROR');
        }

        return $this->success($sale->load('items', 'customer'), 'Order opened', 201);
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
            'tax_rate_id' => 'nullable|integer',
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

        $orderType = $data['order_type'] ?? $sale->order_type;
        $fulfilment = array_key_exists('fulfilment', $data) ? $data['fulfilment'] : $sale->fulfilment;

        if ($orderType === 'delivery' && (empty($fulfilment['address']) || empty($fulfilment['contact_phone']))) {
            return $this->error('Delivery orders need a contact phone and address', 422, [], 'ORDER_ERROR');
        }

        try {
            DB::transaction(function () use ($sale, $data, $businessId, $orderType, $fulfilment) {
                $sale->order_type = $orderType;
                $sale->fulfilment = $fulfilment;
                if (array_key_exists('customer_id', $data)) {
                    $sale->customer_id = $data['customer_id'];
                }
                if (array_key_exists('notes', $data)) {
                    $sale->notes = $data['notes'];
                }
                $sale->save();

                if (! empty($data['items'])) {
                    $priced = $this->saleService->priceItems(
                        $data['items'],
                        $businessId,
                        $sale->outlet_id,
                        $data['tax_rate_id'] ?? null,
                    );
                    $this->saleService->replaceItems($sale, $priced);
                }
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'ORDER_ERROR');
        }

        return $this->success($sale->fresh()->load('items', 'customer'), 'Order updated');
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
            return $this->success($sale->load('items', 'customer'));
        }

        if (self::STATUS_RANK[$next] <= self::STATUS_RANK[$current]) {
            return $this->error('Orders can only move forward through the kitchen', 422, [], 'ORDER_ERROR');
        }

        $sale->order_status = $next;
        $sale->fired_at = $sale->fired_at ?? ($next === 'in_kitchen' ? now() : $sale->fired_at);
        $sale->ready_at = $sale->ready_at ?? ($next === 'ready' ? now() : null);
        $sale->served_at = $next === 'served' ? now() : null;
        $sale->save();

        return $this->success($sale->load('items', 'customer'), 'Order updated');
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

        try {
            DB::transaction(function () use ($sale, $request) {
                $this->saleService->deductStock($sale, $sale->outlet_id, $request->user()->id);

                $outlet = Outlet::findOrFail($sale->outlet_id);
                $sale->receipt_number = $this->saleService->receiptNumber($sale, $outlet);
                $sale->status = 'completed';
                $sale->order_status = 'completed';
                $sale->served_at = $sale->served_at ?? now();
                $sale->completed_at = now();
                $sale->save();
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'SALE_ERROR');
        }

        return $this->success($sale->fresh()->load('items', 'payments', 'customer'), 'Order checked out');
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

        $sale->status = 'voided';
        $sale->order_status = 'cancelled';
        $sale->voided_at = now();
        $sale->voided_by = $request->user()->id;
        $sale->void_reason = $data['reason'];
        $sale->save();

        return $this->success($sale->load('items', 'customer'), 'Order cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOrder(Request $request): array
    {
        $data = $request->validate([
            'order_type' => ['required', Rule::in(Sale::ORDER_TYPES)],
            'customer_id' => 'nullable|integer|exists:customers,id',
            'tax_rate_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'idempotency_key' => 'nullable|string|max:128',
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
        ]);

        return $data;
    }
}

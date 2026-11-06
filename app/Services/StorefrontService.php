<?php

namespace App\Services;

use App\Exceptions\StorefrontException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\InventoryBalance;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Storefront;
use App\Models\StorefrontCustomerAccount;
use App\Models\StorefrontItem;
use App\Models\StorefrontOrder;
use App\Models\StorefrontOrderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StorefrontService
{
    public function __construct(protected SaleService $sales) {}

    /**
     * Public catalogue for a published storefront.
     */
    public function catalog(Storefront $storefront): array
    {
        $items = StorefrontItem::where('storefront_id', $storefront->id)
            ->where('is_visible', true)
            ->with(['product.category'])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (StorefrontItem $item) => $item->product && $item->product->is_active)
            ->values();

        return [
            'storefront' => [
                'name' => $storefront->name,
                'slug' => $storefront->slug,
                'description' => $storefront->description,
                'currency' => $storefront->currency,
                'theme' => $storefront->theme,
                'settings' => $this->publicSettings($storefront),
            ],
            'items' => $items->map(fn (StorefrontItem $item) => [
                'id' => (string) $item->product_id,
                'name' => $item->product->name,
                'description' => $item->product->description,
                'sku' => $item->product->sku,
                'category' => $item->product->category?->name,
                'price' => $this->priceFor($storefront, $item->product, $item),
                'available' => $this->availableQuantity($storefront, $item->product),
            ])->all(),
        ];
    }

    public function priceFor(Storefront $storefront, Product $product, ?StorefrontItem $item = null): string
    {
        if ($item?->price_override !== null) {
            return (string) $item->price_override;
        }

        if ($storefront->outlet_id) {
            $outletPrice = $product->outletPrices()
                ->where('outlet_id', $storefront->outlet_id)
                ->whereNull('product_variant_id')
                ->where('is_active', true)
                ->value('selling_price');

            if ($outletPrice !== null) {
                return (string) $outletPrice;
            }
        }

        return (string) $product->selling_price;
    }

    public function createCart(Storefront $storefront, ?StorefrontCustomerAccount $account = null): Cart
    {
        return Cart::create([
            'business_id' => $storefront->business_id,
            'storefront_id' => $storefront->id,
            'customer_account_id' => $account?->id,
            'token' => Str::random(48),
            'status' => 'active',
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function resolveCart(Storefront $storefront, string $token): Cart
    {
        $cart = Cart::where('storefront_id', $storefront->id)
            ->where('token', $token)
            ->where('status', 'active')
            ->first();

        if (! $cart) {
            throw new StorefrontException('Cart not found.', 'CART_NOT_FOUND');
        }
        if ($cart->expires_at && $cart->expires_at->isPast()) {
            throw new StorefrontException('Cart has expired.', 'CART_EXPIRED');
        }

        return $cart;
    }

    public function addItem(Cart $cart, int $productId, $quantity, ?string $notes = null): Cart
    {
        $quantity = (string) $quantity;
        if (bccomp($quantity, '0', 4) !== 1) {
            throw new StorefrontException('Quantity must be positive.', 'INVALID_QUANTITY');
        }

        $item = StorefrontItem::where('storefront_id', $cart->storefront_id)
            ->where('product_id', $productId)
            ->where('is_visible', true)
            ->with('product')
            ->first();

        if (! $item || ! $item->product || ! $item->product->is_active) {
            throw new StorefrontException('Product is not available in this storefront.', 'PRODUCT_UNAVAILABLE');
        }

        $existing = CartItem::where('cart_id', $cart->id)->where('product_id', $productId)->first();
        $newQuantity = bcadd($existing ? (string) $existing->quantity : '0', $quantity, 4);
        $available = $this->availableQuantity($cart->storefront, $item->product);
        if ($available !== null && bccomp($newQuantity, (string) $available, 4) === 1) {
            throw new StorefrontException('Not enough stock for '.$item->product->name.'.', 'INSUFFICIENT_STOCK');
        }

        CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'product_id' => $productId],
            [
                'quantity' => $newQuantity,
                'unit_price' => $this->priceFor($cart->storefront, $item->product, $item),
                'notes' => $notes ?? $existing?->notes,
            ]
        );

        return $cart->fresh();
    }

    public function updateItem(Cart $cart, int $productId, $quantity, ?string $notes = null): Cart
    {
        $item = CartItem::where('cart_id', $cart->id)->where('product_id', $productId)->first();
        if (! $item) {
            throw new StorefrontException('Cart line not found.', 'CART_LINE_NOT_FOUND');
        }

        if (bccomp((string) $quantity, '0', 4) !== 1) {
            $item->delete();

            return $cart->fresh();
        }

        $product = Product::find($productId);
        if ($product) {
            $available = $this->availableQuantity($cart->storefront, $product);
            if ($available !== null && bccomp((string) $quantity, (string) $available, 4) === 1) {
                throw new StorefrontException('Not enough stock for '.$product->name.'.', 'INSUFFICIENT_STOCK');
            }
        }

        $item->update(['quantity' => $quantity, 'notes' => $notes ?? $item->notes]);

        return $cart->fresh();
    }

    public function removeItem(Cart $cart, int $productId): Cart
    {
        CartItem::where('cart_id', $cart->id)->where('product_id', $productId)->delete();

        return $cart->fresh();
    }

    public function summary(Cart $cart, string $fulfilment = 'delivery'): array
    {
        $cart->load('items.product');
        $lines = [];
        $subtotal = '0';

        foreach ($cart->items as $item) {
            $lineTotal = bcmul((string) $item->unit_price, (string) $item->quantity, 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);
            $lines[] = [
                'product_id' => (string) $item->product_id,
                'name' => $item->product?->name,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => $lineTotal,
                'notes' => $item->notes,
            ];
        }

        $storefront = $cart->storefront;
        $settings = $storefront->settings ?? [];
        $fee = $fulfilment === 'delivery' ? (string) ($settings['delivery_fee'] ?? '0') : '0';
        $taxRate = (string) ($settings['tax_rate'] ?? '0');
        $tax = bccomp($taxRate, '0', 4) === 1
            ? bcmul($subtotal, bcdiv($taxRate, '100', 6), 4)
            : '0';

        return [
            'token' => $cart->token,
            'currency' => $storefront->currency,
            'fulfilment_type' => $fulfilment,
            'lines' => $lines,
            'item_count' => $cart->items->count(),
            'subtotal' => $subtotal,
            'delivery_fee' => $fee,
            'tax' => $tax,
            'total' => bcadd(bcadd($subtotal, $fee, 4), $tax, 4),
        ];
    }

    /**
     * Turn a cart into a storefront order, a draft sale (so it appears in the
     * order board) and a stock reservation. The sale is completed when the
     * order is delivered or collected.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkout(Storefront $storefront, Cart $cart, array $data, ?StorefrontCustomerAccount $account = null): StorefrontOrder
    {
        $fulfilment = $data['fulfilment_type'] ?? 'delivery';
        $outlet = Outlet::where('business_id', $storefront->business_id)
            ->when($storefront->outlet_id, fn ($query) => $query->where('id', $storefront->outlet_id))
            ->first();

        if (! $outlet) {
            throw new StorefrontException('This storefront has no outlet to fulfil orders.', 'NO_OUTLET');
        }

        $zone = null;
        if (! empty($data['delivery_zone_id'])) {
            $zone = DeliveryZone::where('id', $data['delivery_zone_id'])
                ->where('business_id', $storefront->business_id)
                ->where('is_active', true)
                ->first();
            if (! $zone) {
                throw new StorefrontException('Delivery zone not found.', 'ZONE_NOT_FOUND');
            }
        }

        return DB::transaction(function () use ($storefront, $cart, $data, $account, $outlet, $fulfilment, $zone) {
            $locked = Cart::whereKey($cart->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'active') {
                throw new StorefrontException('This cart has already been checked out.', 'CART_CONSUMED');
            }

            $locked->load('items.product');
            if ($locked->items->isEmpty()) {
                throw new StorefrontException('Your cart is empty.', 'CART_EMPTY');
            }

            $summary = $this->summary($locked, $fulfilment);
            $subtotal = $summary['subtotal'];
            $fee = $fulfilment === 'delivery'
                ? ($zone ? (string) $zone->fee : (string) (($storefront->settings ?? [])['delivery_fee'] ?? '0'))
                : '0';

            if ($zone && bccomp($subtotal, (string) $zone->min_order, 4) === -1) {
                throw new StorefrontException('Order is below the minimum for this delivery zone.', 'MIN_ORDER_NOT_MET', [
                    'minimum' => (string) $zone->min_order,
                ]);
            }

            $taxRate = (string) (($storefront->settings ?? [])['tax_rate'] ?? '0');
            $tax = bccomp($taxRate, '0', 4) === 1
                ? bcmul($subtotal, bcdiv($taxRate, '100', 6), 4)
                : '0';
            $total = bcadd(bcadd($subtotal, $fee, 4), $tax, 4);

            $orderType = $fulfilment === 'delivery' ? 'delivery' : 'pickup';
            $sale = Sale::create([
                'business_id' => $storefront->business_id,
                'outlet_id' => $outlet->id,
                'order_type' => $orderType,
                'order_status' => 'open',
                'status' => 'draft',
                'receipt_number' => 'TMP-'.Str::upper(Str::random(12)),
                'subtotal' => $subtotal,
                'discount_amount' => '0',
                'tax_amount' => $tax,
                'total' => $total,
                'paid_amount' => '0',
                'change_amount' => '0',
                'notes' => $data['notes'] ?? null,
                'fulfilment' => [
                    'contact_name' => $data['customer_name'],
                    'contact_phone' => $data['customer_phone'] ?? null,
                    'address' => $data['delivery_address']['line1'] ?? null,
                    'scheduled_at' => $data['scheduled_at'] ?? null,
                    'instructions' => $data['notes'] ?? null,
                    'source' => 'storefront',
                ],
            ]);

            $sale->receipt_number = $this->sales->receiptNumber($sale, $outlet);
            $sale->order_number = $this->sales->nextOrderNumber($outlet, $orderType);
            $sale->save();

            foreach ($locked->items as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name ?? 'Product',
                    'sku' => $item->product?->sku,
                    'base_unit_price' => (string) $item->unit_price,
                    'unit_price' => (string) $item->unit_price,
                    'cost_price' => (string) ($item->product?->cost_price ?? '0'),
                    'quantity' => (string) $item->quantity,
                    'discount_amount' => '0',
                    'line_discount_amount' => '0',
                    'tax_rate' => $taxRate,
                    'tax_amount' => '0',
                    'line_total' => bcmul((string) $item->unit_price, (string) $item->quantity, 4),
                    'notes' => $item->notes,
                ]);
            }

            $paymentMethod = $data['payment_method'] ?? 'cod';
            $order = StorefrontOrder::create([
                'business_id' => $storefront->business_id,
                'storefront_id' => $storefront->id,
                'outlet_id' => $outlet->id,
                'sale_id' => $sale->id,
                'customer_account_id' => $account?->id,
                'customer_id' => $account?->customer_id,
                'number' => 'TMP-'.Str::upper(Str::random(12)),
                'status' => 'pending',
                'fulfilment_type' => $fulfilment,
                'payment_status' => $paymentMethod === 'cod' ? 'unpaid' : 'pending',
                'payment_method' => $paymentMethod,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? $account?->email,
                'delivery_address' => $data['delivery_address'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'discount' => '0',
                'tax' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'placed_at' => now(),
                'tracking_token' => Str::random(40),
            ]);
            $order->update(['number' => 'SO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);

            StorefrontOrderEvent::create([
                'storefront_order_id' => $order->id,
                'status' => 'pending',
                'message' => 'Order placed.',
                'actor_type' => 'customer',
            ]);

            foreach ($locked->items as $item) {
                if ($item->product?->track_inventory) {
                    $balance = InventoryBalance::lockForUpdate()->firstOrCreate(
                        ['outlet_id' => $outlet->id, 'product_id' => $item->product_id, 'product_variant_id' => null],
                        ['business_id' => $storefront->business_id, 'quantity' => 0, 'reserved_quantity' => 0]
                    );
                    $available = bcsub((string) $balance->quantity, (string) $balance->reserved_quantity, 4);
                    if (bccomp($available, (string) $item->quantity, 4) === -1) {
                        throw new StorefrontException('Not enough stock for '.$item->product->name.'.', 'INSUFFICIENT_STOCK');
                    }
                    $balance->reserved_quantity = bcadd((string) $balance->reserved_quantity, (string) $item->quantity, 4);
                    $balance->save();
                }
            }

            $locked->update(['status' => 'converted']);

            return $order->fresh()->load('events', 'sale.items');
        });
    }

    /**
     * Advance an order through its fulfilment flow. Delivered/completed orders
     * finalise the linked sale, deduct stock and record payment.
     */
    public function transition(StorefrontOrder $order, string $status, ?int $actorId = null, ?string $message = null, string $actorType = 'staff'): StorefrontOrder
    {
        $allowed = [
            'pending' => ['confirmed', 'preparing', 'cancelled'],
            'confirmed' => ['preparing', 'ready', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['out_for_delivery', 'completed', 'cancelled'],
            'out_for_delivery' => ['delivered', 'failed'],
            'delivered' => [],
            'completed' => [],
            'failed' => [],
            'cancelled' => [],
        ];

        if (! in_array($status, StorefrontOrder::STATUSES, true) && $status !== 'failed') {
            throw new StorefrontException('Unknown order status.', 'INVALID_STATUS');
        }
        if (! in_array($status, $allowed[$order->status] ?? [], true)) {
            throw new StorefrontException("Cannot move an order from {$order->status} to {$status}.", 'INVALID_TRANSITION');
        }

        return DB::transaction(function () use ($order, $status, $actorId, $message, $actorType) {
            $locked = StorefrontOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $locked->status = $status;
            $locked->confirmed_at = $locked->confirmed_at ?? ($status !== 'pending' ? now() : null);
            if ($status === 'cancelled') {
                $locked->cancelled_at = now();
                $locked->cancellation_reason = $message;
            }
            $locked->save();

            if (in_array($status, ['delivered', 'completed'], true)) {
                $this->finalise($locked, $actorId);
            }
            if (in_array($status, ['cancelled', 'failed'], true)) {
                $this->releaseReservation($locked);
                $this->cancelSale($locked, $message);
            }

            StorefrontOrderEvent::create([
                'storefront_order_id' => $locked->id,
                'status' => $status,
                'message' => $message,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
            ]);

            return $locked->fresh()->load('events', 'sale.items');
        });
    }

    public function markPaid(StorefrontOrder $order, string $method = 'manual', ?string $reference = null, ?int $actorId = null): StorefrontOrder
    {
        if ($order->payment_status === 'paid') {
            return $order;
        }

        return DB::transaction(function () use ($order, $method, $reference, $actorId) {
            $order->update(['payment_status' => 'paid', 'payment_method' => $order->payment_method ?: $method]);

            if ($order->sale_id) {
                $sale = Sale::find($order->sale_id);
                if ($sale) {
                    $sale->paid_amount = $sale->total;
                    $sale->save();
                    Payment::create([
                        'business_id' => $order->business_id,
                        'sale_id' => $sale->id,
                        'method' => $method,
                        'amount' => $sale->total,
                        'status' => 'completed',
                        'reference' => $reference,
                        'paid_at' => now(),
                    ]);
                }
            }

            StorefrontOrderEvent::create([
                'storefront_order_id' => $order->id,
                'status' => $order->status,
                'message' => 'Payment received via '.$method.'.',
                'actor_type' => 'staff',
                'actor_id' => $actorId,
            ]);

            return $order->fresh()->load('events');
        });
    }

    public function tracking(StorefrontOrder $order): array
    {
        $order->load('events', 'sale.items', 'deliveries');

        return [
            'number' => $order->number,
            'status' => $order->status,
            'fulfilment_type' => $order->fulfilment_type,
            'payment_status' => $order->payment_status,
            'subtotal' => (string) $order->subtotal,
            'delivery_fee' => (string) $order->delivery_fee,
            'tax' => (string) $order->tax,
            'total' => (string) $order->total,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'scheduled_at' => $order->scheduled_at?->toIso8601String(),
            'customer_name' => $order->customer_name,
            'items' => $order->sale?->items->map(fn (SaleItem $item) => [
                'name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'line_total' => (string) $item->line_total,
            ]) ?? [],
            'timeline' => $order->events->map(fn (StorefrontOrderEvent $event) => [
                'status' => $event->status,
                'message' => $event->message,
                'at' => $event->created_at?->toIso8601String(),
            ]),
            'delivery' => $order->deliveries->first() ? [
                'status' => $order->deliveries->first()->status,
                'tracking' => $order->deliveries->first()->events()->latest()->first()?->status,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{account: StorefrontCustomerAccount, token: string}
     */
    public function registerAccount(Storefront $storefront, array $data): array
    {
        $exists = StorefrontCustomerAccount::where('storefront_id', $storefront->id)
            ->where('email', $data['email'])
            ->exists();
        if ($exists) {
            throw new StorefrontException('An account with this email already exists.', 'ACCOUNT_EXISTS');
        }

        return DB::transaction(function () use ($storefront, $data) {
            $customer = Customer::create([
                'business_id' => $storefront->business_id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
            ]);

            $account = StorefrontCustomerAccount::create([
                'business_id' => $storefront->business_id,
                'storefront_id' => $storefront->id,
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => Hash::make($data['password']),
                'status' => 'active',
                'last_login_at' => now(),
            ]);

            return ['account' => $account, 'token' => $account->createToken('storefront')->plainTextToken];
        });
    }

    /**
     * @return array{account: StorefrontCustomerAccount, token: string}
     */
    public function login(Storefront $storefront, string $email, string $password): array
    {
        $account = StorefrontCustomerAccount::where('storefront_id', $storefront->id)
            ->where('email', $email)
            ->where('status', 'active')
            ->first();

        if (! $account || ! Hash::check($password, $account->password_hash)) {
            throw new StorefrontException('Invalid email or password.', 'INVALID_CREDENTIALS');
        }

        $account->update(['last_login_at' => now()]);

        return ['account' => $account, 'token' => $account->createToken('storefront')->plainTextToken];
    }

    /* ------------------------------------------------------------------ */

    private function finalise(StorefrontOrder $order, ?int $actorId): void
    {
        $this->releaseReservation($order);

        if (! $order->sale_id) {
            return;
        }

        $sale = Sale::find($order->sale_id);
        if (! $sale) {
            return;
        }

        if ($sale->status === 'draft') {
            $this->sales->deductStock($sale, $order->outlet_id, $actorId);
            $sale->status = 'completed';
            $sale->order_status = 'completed';
            $sale->completed_at = now();
            $sale->served_at = $sale->served_at ?? now();
        }
        if ($order->payment_status === 'unpaid') {
            $sale->paid_amount = $sale->total;
            $order->payment_status = 'paid';
            $order->save();
            Payment::create([
                'business_id' => $order->business_id,
                'sale_id' => $sale->id,
                'method' => $order->payment_method ?: 'cod',
                'amount' => $sale->total,
                'status' => 'completed',
                'reference' => 'storefront-'.$order->number,
                'paid_at' => now(),
            ]);
        }
        $sale->save();

        $order->completed_at = now();
        $order->save();
    }

    private function releaseReservation(StorefrontOrder $order): void
    {
        if (! $order->sale_id) {
            return;
        }

        $sale = Sale::with('items')->find($order->sale_id);
        if (! $sale) {
            return;
        }

        foreach ($sale->items as $item) {
            $product = Product::find($item->product_id);
            if (! $product?->track_inventory) {
                continue;
            }
            $balance = InventoryBalance::lockForUpdate()
                ->where('outlet_id', $order->outlet_id)
                ->where('product_id', $item->product_id)
                ->whereNull('product_variant_id')
                ->first();
            if (! $balance) {
                continue;
            }
            $balance->reserved_quantity = max(0, (float) $balance->reserved_quantity - (float) $item->quantity);
            $balance->save();
        }
    }

    private function cancelSale(StorefrontOrder $order, ?string $reason): void
    {
        $sale = $order->sale_id ? Sale::find($order->sale_id) : null;
        if (! $sale || $sale->status !== 'draft') {
            return;
        }

        $sale->status = 'voided';
        $sale->order_status = 'cancelled';
        $sale->voided_at = now();
        $sale->void_reason = $reason ?: 'Storefront order cancelled';
        $sale->save();
    }

    private function availableQuantity(Storefront $storefront, Product $product): ?string
    {
        if (! $product->track_inventory) {
            return null;
        }

        $outletId = $storefront->outlet_id;
        $query = InventoryBalance::where('business_id', $storefront->business_id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->when($outletId, fn ($inner) => $inner->where('outlet_id', $outletId));

        $balances = $query->get();
        if ($balances->isEmpty()) {
            return '0';
        }

        $available = '0';
        foreach ($balances as $balance) {
            $available = bcadd($available, bcsub((string) $balance->quantity, (string) $balance->reserved_quantity, 4), 4);
        }

        return bccomp($available, '0', 4) === -1 ? '0' : $available;
    }

    private function publicSettings(Storefront $storefront): array
    {
        $settings = $storefront->settings ?? [];

        return [
            'delivery_fee' => (string) ($settings['delivery_fee'] ?? '0'),
            'tax_rate' => (string) ($settings['tax_rate'] ?? '0'),
            'min_order' => (string) ($settings['min_order'] ?? '0'),
            'delivery_enabled' => (bool) ($settings['delivery_enabled'] ?? true),
            'pickup_enabled' => (bool) ($settings['pickup_enabled'] ?? true),
            'payment_methods' => $settings['payment_methods'] ?? ['cod'],
        ];
    }
}

<?php

namespace App\Services;

use App\Models\ChannelEvent;
use App\Models\ChannelMenuMapping;
use App\Models\Customer;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SalesChannel;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChannelService
{
    public function __construct(
        protected SaleService $sales,
        protected MenuService $menu,
        protected KitchenService $kitchen,
    ) {}

    /**
     * Turn a channel partner's order payload into a Velora order.
     *
     * Prices and product ids always come from the channel's menu mapping and
     * the server menu, never from the payload, so a compromised connector
     * cannot invent a discount. The external order reference is the idempotency
     * key: replaying the same push returns the existing order untouched.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sale: Sale, created: bool}
     */
    public function createOrder(SalesChannel $channel, array $payload): array
    {
        $externalRef = trim((string) ($payload['external_order_id'] ?? ''));
        if ($externalRef === '') {
            throw new RuntimeException('An external order id is required');
        }

        $existing = Sale::where('business_id', $channel->business_id)
            ->where('sales_channel_id', $channel->id)
            ->where('channel_order_ref', $externalRef)
            ->first();

        if ($existing) {
            return ['sale' => $existing, 'created' => false];
        }

        $outlet = $this->resolveOutlet($channel, $payload['outlet_id'] ?? null);
        $orderType = ($payload['order_type'] ?? 'pickup') === 'delivery' ? 'delivery' : 'pickup';
        $customer = $this->resolveCustomer($channel->business_id, $payload['customer'] ?? []);
        $fulfilment = $this->fulfilment($payload, $orderType, $customer);
        $items = $this->mapItems($channel, $payload['items'] ?? []);

        $sale = DB::transaction(function () use ($channel, $outlet, $orderType, $customer, $fulfilment, $items, $payload, $externalRef) {
            $priced = $this->sales->priceItems(
                $items,
                $channel->business_id,
                $outlet->id,
                null,
                [
                    'promotion_code' => $payload['promotion_code'] ?? null,
                    'customer_id' => $customer?->id,
                    'redeem_points' => '0',
                ],
            );

            $orderNumber = $this->sales->nextOrderNumber($outlet, $orderType);

            $sale = Sale::create([
                'business_id' => $channel->business_id,
                'outlet_id' => $outlet->id,
                'cashier_id' => null,
                'customer_id' => $customer?->id,
                'receipt_number' => 'OPEN-'.$orderNumber,
                'order_number' => $orderNumber,
                'order_type' => $orderType,
                'order_status' => 'open',
                'status' => 'draft',
                'sales_channel_id' => $channel->id,
                'channel_order_ref' => $externalRef,
                'channel_status' => 'received',
                'channel_commission_percent' => $channel->commission_percent,
                'channel_commission' => 0,
                'paid_amount' => 0,
                'change_amount' => 0,
                'notes' => $payload['notes'] ?? null,
                'fulfilment' => $fulfilment,
            ]);

            $this->sales->attachItems($sale, $priced['items']);
            $this->sales->applyPricing($sale, $priced);
            $this->applyCommission($sale);

            return $sale;
        });

        $channel->forceFill(['last_order_at' => now()])->save();

        if ($channel->auto_accept) {
            $this->accept($sale, true);
        }

        return ['sale' => $sale->fresh()->load('items', 'customer', 'salesChannel'), 'created' => true];
    }

    /**
     * Accept a channel order and send it to the kitchen.
     */
    public function accept(Sale $sale, bool $fromChannel = false): Sale
    {
        if ($sale->channel_status === 'accepted') {
            return $sale;
        }

        $sale->channel_status = 'accepted';
        if ($sale->order_status === 'open') {
            $sale->order_status = 'in_kitchen';
            $sale->fired_at = now();
        }
        $sale->save();

        $sale->loadMissing('salesChannel');
        $this->kitchen->fireOrder($sale);

        $this->recordEvent($sale, 'order.accepted', [
            'prep_buffer_minutes' => (int) ($sale->salesChannel?->prep_buffer_minutes ?? 0),
            'auto_accepted' => $fromChannel,
        ]);

        return $sale;
    }

    /**
     * Reject an incoming channel order, releasing it before the kitchen starts.
     */
    public function reject(Sale $sale, string $reason): Sale
    {
        $sale->status = 'voided';
        $sale->order_status = 'cancelled';
        $sale->channel_status = 'rejected';
        $sale->voided_at = now();
        $sale->void_reason = $reason;
        $sale->save();

        $this->kitchen->voidForSale($sale);
        $this->recordEvent($sale, 'order.rejected', ['reason' => $reason]);

        return $sale;
    }

    /**
     * Queue an outbound event for a channel-owned order. Sales taken at the
     * register are ignored, so callers can fire this unconditionally.
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordEvent(Sale $sale, string $type, array $extra = []): ?ChannelEvent
    {
        if (! $sale->sales_channel_id) {
            return null;
        }

        return ChannelEvent::create([
            'business_id' => $sale->business_id,
            'sales_channel_id' => $sale->sales_channel_id,
            'sale_id' => $sale->id,
            'type' => $type,
            'payload' => array_merge($this->orderPayload($sale), $extra),
            'status' => 'pending',
        ]);
    }

    /**
     * Mirror an order status change to the owning channel, when there is one.
     */
    public function syncOrderStatus(Sale $sale, string $orderStatus): void
    {
        if (! $sale->sales_channel_id) {
            return;
        }

        $map = [
            'ready' => 'order.ready',
            'served' => 'order.dispatched',
            'completed' => 'order.completed',
            'cancelled' => 'order.cancelled',
        ];

        if (! isset($map[$orderStatus])) {
            return;
        }

        $channelStatus = match ($orderStatus) {
            'ready' => 'ready',
            'served' => 'dispatched',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => null,
        };

        // Repeat transitions must not spam the connector queue.
        if ($channelStatus === null || $sale->channel_status === $channelStatus) {
            return;
        }

        $sale->channel_status = $channelStatus;
        $sale->save();

        $this->recordEvent($sale, $map[$orderStatus]);
    }

    /**
     * Record the commission snapshot for a channel order from its current total.
     */
    public function applyCommission(Sale $sale): void
    {
        $percent = (string) ($sale->channel_commission_percent ?? '0');
        $commission = bccomp($percent, '0', 4) === 1
            ? bcdiv(bcmul((string) $sale->total, $percent, 6), '100', 4)
            : '0';

        $sale->channel_commission = $commission;
        $sale->save();
    }

    /**
     * Settle a prepaid channel order with the channel's payment rail, so the
     * cashier is never asked to charge the guest twice.
     *
     * @return bool whether a payment was recorded
     */
    public function settlePayment(Sale $sale, PaymentService $payments): bool
    {
        if (! $sale->sales_channel_id) {
            return false;
        }

        $channel = $sale->salesChannel ?? SalesChannel::find($sale->sales_channel_id);
        $code = $channel?->payment_method_code;
        if (! $code) {
            return false;
        }

        $remaining = bcsub((string) $sale->total, (string) $payments->paidTotal($sale), 4);
        if (bccomp($remaining, '0', 4) !== 1) {
            return false;
        }

        $payments->recordPayments($sale, [[
            'method' => $code,
            'amount' => $remaining,
            'reference' => $sale->channel_order_ref,
            'provider' => $channel->code,
        ]], 'channel:'.$sale->id);

        return true;
    }

    /**
     * The menu a channel partner sells, in that partner's item ids.
     *
     * @return array<string, mixed>
     */
    public function menuSnapshot(SalesChannel $channel, ?int $outletId = null, ?CarbonInterface $at = null): array
    {
        $outlet = $this->resolveOutlet($channel, $outletId);

        $mappings = ChannelMenuMapping::where('sales_channel_id', $channel->id)
            ->where('is_active', true)
            ->with('product', 'variant')
            ->orderBy('id')
            ->get();

        $optionIds = $mappings
            ->flatMap(fn (ChannelMenuMapping $mapping) => array_values($mapping->option_map ?? []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $options = ModifierOption::where('business_id', $channel->business_id)
            ->whereIn('id', $optionIds)
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($mappings as $mapping) {
            $product = $mapping->product;
            if (! $product) {
                continue;
            }

            $availability = $this->menu->productAvailability($product, $outlet->id, $mapping->variant, $at);

            $itemOptions = [];
            foreach ($mapping->option_map ?? [] as $externalId => $optionId) {
                $option = $options->get((int) $optionId);
                if (! $option) {
                    continue;
                }
                $itemOptions[] = [
                    'external_option_id' => (string) $externalId,
                    'modifier_option_id' => $option->id,
                    'name' => $option->name,
                    'price_delta' => (string) $option->price_delta,
                    'available' => (bool) $option->is_active,
                ];
            }

            $items[] = [
                'external_item_id' => $mapping->external_item_id,
                'external_variant_id' => $mapping->external_variant_id ?: null,
                'product_id' => $product->id,
                'product_variant_id' => $mapping->product_variant_id,
                'category' => $product->category?->name,
                'name' => $product->name.($mapping->variant ? ' - '.$mapping->variant->name : ''),
                'price' => $this->menu->basePrice($product, $mapping->variant, $outlet->id),
                'available' => (bool) $availability['available'],
                'availability_reason' => $availability['reason'] ?? null,
                'options' => $itemOptions,
            ];
        }

        return [
            'channel' => [
                'id' => $channel->id,
                'code' => $channel->code,
                'name' => $channel->name,
                'kind' => $channel->kind,
            ],
            'outlet' => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'timezone' => $outlet->timezone,
            ],
            'generated_at' => now()->toIso8601String(),
            'items' => $items,
        ];
    }

    /**
     * Per-channel order, revenue and commission totals for a date range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summary(int $businessId, ?string $from = null, ?string $to = null): array
    {
        $channels = SalesChannel::where('business_id', $businessId)->orderBy('name')->get();

        $rows = Sale::where('business_id', $businessId)
            ->whereNotNull('sales_channel_id')
            ->whereIn('status', ['completed', 'partially_refunded'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('sales_channel_id, count(*) as orders, sum(total) as gross, sum(channel_commission) as commission')
            ->groupBy('sales_channel_id')
            ->get()
            ->keyBy('sales_channel_id');

        $open = Sale::where('business_id', $businessId)
            ->whereNotNull('sales_channel_id')
            ->activeOrders()
            ->selectRaw('sales_channel_id, count(*) as open_orders')
            ->groupBy('sales_channel_id')
            ->get()
            ->keyBy('sales_channel_id');

        return $channels->map(function (SalesChannel $channel) use ($rows, $open) {
            $row = $rows->get($channel->id);
            $gross = (string) ($row->gross ?? '0');
            $commission = (string) ($row->commission ?? '0');

            return [
                'channel_id' => $channel->id,
                'code' => $channel->code,
                'name' => $channel->name,
                'kind' => $channel->kind,
                'is_active' => $channel->is_active,
                'commission_percent' => (string) $channel->commission_percent,
                'orders' => (int) ($row->orders ?? 0),
                'open_orders' => (int) ($open->get($channel->id)->open_orders ?? 0),
                'gross' => bcadd($gross, '0', 4),
                'commission' => bcadd($commission, '0', 4),
                'net' => bcsub($gross, $commission, 4),
            ];
        })->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function mapItems(SalesChannel $channel, array $lines): array
    {
        $mappings = ChannelMenuMapping::where('sales_channel_id', $channel->id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (ChannelMenuMapping $mapping) => $mapping->external_item_id.':'.$mapping->external_variant_id);

        $items = [];
        $missing = [];

        foreach ($lines as $line) {
            $externalId = trim((string) ($line['external_item_id'] ?? ''));
            $externalVariant = trim((string) ($line['external_variant_id'] ?? ''));
            $mapping = $mappings->get($externalId.':'.$externalVariant);

            if (! $mapping) {
                $missing[] = $externalVariant !== '' ? $externalId.'/'.$externalVariant : $externalId;

                continue;
            }

            $options = [];
            foreach ($line['options'] ?? [] as $externalOption) {
                $optionId = ($mapping->option_map ?? [])[(string) $externalOption] ?? null;
                if (! $optionId) {
                    throw new RuntimeException('Unmapped channel option: '.$externalOption);
                }
                $options[] = (int) $optionId;
            }

            $quantity = (string) ($line['quantity'] ?? '0');
            if (bccomp($quantity, '0', 4) !== 1) {
                throw new RuntimeException('Channel order lines need a positive quantity');
            }

            $items[] = [
                'product_id' => $mapping->product_id,
                'product_variant_id' => $mapping->product_variant_id,
                'quantity' => $quantity,
                'discount_amount' => 0,
                'option_ids' => $options,
                'notes' => $line['notes'] ?? null,
            ];
        }

        if ($missing !== []) {
            throw new RuntimeException('Unmapped channel items: '.implode(', ', array_unique($missing)));
        }

        if ($items === []) {
            throw new RuntimeException('A channel order needs at least one item');
        }

        return $items;
    }

    private function resolveOutlet(SalesChannel $channel, mixed $requestedOutletId): Outlet
    {
        if ($channel->outlet_id) {
            $outlet = Outlet::where('business_id', $channel->business_id)->find($channel->outlet_id);
            if (! $outlet) {
                throw new RuntimeException('The channel outlet no longer exists');
            }

            return $outlet;
        }

        if ($requestedOutletId) {
            $outlet = Outlet::where('business_id', $channel->business_id)->find((int) $requestedOutletId);
            if (! $outlet) {
                throw new RuntimeException('Unknown outlet for this channel');
            }

            return $outlet;
        }

        $outlet = Outlet::where('business_id', $channel->business_id)->orderBy('id')->first();
        if (! $outlet) {
            throw new RuntimeException('This business has no outlet to sell from');
        }

        return $outlet;
    }

    /**
     * Match guests by phone so repeat channel customers keep one profile.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveCustomer(int $businessId, array $data): ?Customer
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($phone === '') {
            return null;
        }

        $customer = Customer::where('business_id', $businessId)->where('phone', $phone)->first();
        if ($customer) {
            return $customer;
        }

        return Customer::create([
            'business_id' => $businessId,
            'name' => $name !== '' ? $name : $phone,
            'phone' => $phone,
            'email' => $data['email'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fulfilment(array $payload, string $orderType, ?Customer $customer): array
    {
        $fulfilment = $payload['fulfilment'] ?? [];

        $contactPhone = trim((string) ($fulfilment['contact_phone'] ?? $payload['customer']['phone'] ?? $customer?->phone ?? ''));
        $address = trim((string) ($fulfilment['address'] ?? $payload['customer']['address'] ?? $customer?->address ?? ''));

        if ($orderType === 'delivery' && ($contactPhone === '' || $address === '')) {
            throw new RuntimeException('Delivery orders need a contact phone and address');
        }

        $fulfilment['contact_phone'] = $contactPhone ?: null;
        $fulfilment['contact_name'] = $fulfilment['contact_name'] ?? ($payload['customer']['name'] ?? $customer?->name);
        $fulfilment['address'] = $address ?: null;
        $fulfilment['placed_at'] = $payload['placed_at'] ?? null;

        return array_filter($fulfilment, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(Sale $sale): array
    {
        $sale->loadMissing('items', 'salesChannel');

        return [
            'external_order_id' => $sale->channel_order_ref,
            'order_number' => $sale->order_number,
            'receipt_number' => $sale->receipt_number,
            'channel_status' => $sale->channel_status,
            'order_status' => $sale->order_status,
            'order_type' => $sale->order_type,
            'total' => (string) $sale->total,
            'commission' => (string) $sale->channel_commission,
            'fulfilment' => $sale->fulfilment,
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'notes' => $item->notes,
            ])->all(),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}

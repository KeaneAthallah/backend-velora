<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChannelEvent;
use App\Models\ChannelMenuMapping;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Services\AuthorizationService;
use App\Services\ChannelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected ChannelService $channels,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'channels.view', $businessId);

        $query = SalesChannel::where('business_id', $businessId)
            ->withCount(['mappings', 'sales'])
            ->orderBy('name')
            ->orderBy('id');

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->input('outlet_id'));
        }
        if ($request->filled('kind')) {
            $query->where('kind', $request->input('kind'));
        }

        return $this->success($query->get()->map(fn (SalesChannel $channel) => $this->present($channel)));
    }

    public function summary(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'channels.view', $businessId);

        return $this->success($this->channels->summary(
            $businessId,
            $request->input('from'),
            $request->input('to'),
        ));
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $data = $this->validateChannel($request, $businessId);
        $data['business_id'] = $businessId;
        $data['code'] = $data['code'] ?? Str::slug($data['name'], '_');

        if (SalesChannel::where('business_id', $businessId)->where('code', $data['code'])->exists()) {
            return $this->error('A channel with this code already exists', 422, [], 'CHANNEL_ERROR');
        }

        $channel = SalesChannel::create($data);
        $secret = $channel->issueCredentials();

        return $this->success(
            $this->present($channel->fresh()) + [
                'secret' => $secret,
                'webhook_url' => $channel->webhookUrl(),
            ],
            'Channel created',
            201
        );
    }

    public function update(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $data = $this->validateChannel($request, $businessId);

        if (isset($data['code']) && $data['code'] !== $channel->code) {
            $taken = SalesChannel::where('business_id', $businessId)
                ->where('code', $data['code'])
                ->whereKeyNot($channel->id)
                ->exists();
            if ($taken) {
                return $this->error('A channel with this code already exists', 422, [], 'CHANNEL_ERROR');
            }
        }

        $channel->fill($data)->save();

        return $this->success($this->present($channel->fresh()), 'Channel updated');
    }

    public function destroy(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        if (Sale::where('sales_channel_id', $channel->id)->exists()) {
            return $this->error(
                'This channel already has orders; deactivate it instead of deleting it',
                422,
                [],
                'CHANNEL_IN_USE'
            );
        }

        $channel->delete();

        return $this->success(null, 'Channel deleted');
    }

    /**
     * Replace the connector credential pair. The old token stops working at once.
     */
    public function rotateSecret(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $secret = $channel->issueCredentials();

        return $this->success([
            'webhook_url' => $channel->webhookUrl(),
            'secret' => $secret,
        ], 'Channel credentials rotated');
    }

    public function mappings(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.view', $businessId);

        $rows = ChannelMenuMapping::where('sales_channel_id', $channel->id)
            ->with('product:id,name,sku', 'variant:id,name,sku')
            ->orderBy('external_item_id')
            ->get()
            ->map(fn (ChannelMenuMapping $mapping) => $this->presentMapping($mapping));

        return $this->success($rows);
    }

    public function syncMappings(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $data = $request->validate([
            'mappings' => 'present|array',
            'mappings.*.external_item_id' => 'required|string|max:128',
            'mappings.*.external_variant_id' => 'nullable|string|max:128',
            'mappings.*.product_id' => 'required|integer',
            'mappings.*.product_variant_id' => 'nullable|integer',
            'mappings.*.option_map' => 'nullable|array',
            'mappings.*.option_map.*' => 'integer',
            'mappings.*.is_active' => 'nullable|boolean',
        ]);

        $rows = $data['mappings'];

        $productIds = collect($rows)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values();
        $products = Product::where('business_id', $businessId)->whereIn('id', $productIds)->pluck('id')->all();
        if (count($products) !== $productIds->count()) {
            return $this->error('A mapped product does not belong to this business', 422, [], 'CHANNEL_ERROR');
        }

        foreach ($rows as $row) {
            if (empty($row['product_variant_id'])) {
                continue;
            }
            $ok = ProductVariant::where('id', (int) $row['product_variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->exists();
            if (! $ok) {
                return $this->error('A mapped variant does not belong to its product', 422, [], 'CHANNEL_ERROR');
            }
        }

        $optionIds = collect($rows)
            ->flatMap(fn ($row) => array_values($row['option_map'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($optionIds->isNotEmpty()) {
            $found = ModifierOption::where('business_id', $businessId)
                ->whereIn('id', $optionIds)
                ->count();
            if ($found !== $optionIds->count()) {
                return $this->error('A mapped modifier option does not belong to this business', 422, [], 'CHANNEL_ERROR');
            }
        }

        DB::transaction(function () use ($channel, $rows, $businessId) {
            $existing = ChannelMenuMapping::where('sales_channel_id', $channel->id)
                ->get()
                ->keyBy(fn (ChannelMenuMapping $mapping) => $mapping->external_item_id.':'.$mapping->external_variant_id);

            $seen = [];

            foreach ($rows as $row) {
                $externalVariant = (string) ($row['external_variant_id'] ?? '');
                $key = $row['external_item_id'].':'.$externalVariant;
                $seen[$key] = true;

                $attributes = [
                    'external_item_id' => $row['external_item_id'],
                    'external_variant_id' => $externalVariant,
                    'product_id' => (int) $row['product_id'],
                    'product_variant_id' => ! empty($row['product_variant_id']) ? (int) $row['product_variant_id'] : null,
                    'option_map' => $row['option_map'] ?? [],
                    'is_active' => $row['is_active'] ?? true,
                ];

                if ($mapping = $existing->get($key)) {
                    $mapping->fill($attributes)->save();
                } else {
                    ChannelMenuMapping::create($attributes + [
                        'business_id' => $businessId,
                        'sales_channel_id' => $channel->id,
                    ]);
                }
            }

            foreach ($existing as $key => $mapping) {
                if (! isset($seen[$key])) {
                    $mapping->delete();
                }
            }
        });

        $rows = ChannelMenuMapping::where('sales_channel_id', $channel->id)
            ->with('product:id,name,sku', 'variant:id,name,sku')
            ->orderBy('external_item_id')
            ->get()
            ->map(fn (ChannelMenuMapping $mapping) => $this->presentMapping($mapping));

        return $this->success($rows, 'Channel menu mappings saved');
    }

    public function orders(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.view', $businessId);

        $query = Sale::where('business_id', $businessId)
            ->where('sales_channel_id', $channel->id)
            ->with('items', 'customer', 'table')
            ->orderByDesc('id');

        $status = $request->input('status', 'all');
        if ($status === 'active') {
            $query->activeOrders();
        } elseif ($status !== 'all') {
            $query->where('channel_status', $status);
        }

        return $this->success($query->paginate(50));
    }

    public function acceptOrder(Request $request, SalesChannel $channel, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId || $sale->business_id !== $businessId || $sale->sales_channel_id !== $channel->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.manage', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Only open channel orders can be accepted', 422, [], 'CHANNEL_ERROR');
        }

        $this->channels->accept($sale);

        return $this->success($sale->fresh()->load('items', 'customer', 'salesChannel'), 'Channel order accepted');
    }

    public function rejectOrder(Request $request, SalesChannel $channel, Sale $sale)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId || $sale->business_id !== $businessId || $sale->sales_channel_id !== $channel->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'orders.cancel', $businessId);

        if (! $sale->isOpenOrder()) {
            return $this->error('Only open channel orders can be rejected', 422, [], 'CHANNEL_ERROR');
        }

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->channels->reject($sale, $data['reason']);

        return $this->success($sale->fresh()->load('items', 'customer', 'salesChannel'), 'Channel order rejected');
    }

    public function events(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.view', $businessId);

        $query = ChannelEvent::where('sales_channel_id', $channel->id)->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->success($query->paginate(50));
    }

    public function ackEvent(Request $request, SalesChannel $channel, ChannelEvent $event)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId || $event->sales_channel_id !== $channel->id) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $data = $request->validate([
            'status' => ['required', Rule::in(['sent', 'failed'])],
            'error' => 'nullable|string|max:255',
        ]);

        $event->attempts = (int) $event->attempts + 1;
        if ($data['status'] === 'sent') {
            $event->status = 'sent';
            $event->sent_at = now();
            $event->last_error = null;
        } else {
            $event->status = 'failed';
            $event->last_error = $data['error'] ?? 'Delivery failed';
        }
        $event->save();

        return $this->success($event, 'Event acknowledged');
    }

    /**
     * Queue the channel's current menu for delivery to the partner.
     */
    public function pushMenu(Request $request, SalesChannel $channel)
    {
        $businessId = $request->attributes->get('business_id');
        if ($channel->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'channels.manage', $businessId);

        $outletId = $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null;

        try {
            $snapshot = $this->channels->menuSnapshot($channel, $outletId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'CHANNEL_ERROR');
        }

        $event = ChannelEvent::create([
            'business_id' => $businessId,
            'sales_channel_id' => $channel->id,
            'sale_id' => null,
            'type' => 'menu.pushed',
            'payload' => $snapshot,
            'status' => 'pending',
        ]);

        return $this->success(['snapshot' => $snapshot, 'event' => $event], 'Channel menu queued');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateChannel(Request $request, int $businessId): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'code' => 'nullable|string|max:32|regex:/^[a-z0-9_]+$/',
            'kind' => ['nullable', Rule::in(SalesChannel::KINDS)],
            'outlet_id' => ['nullable', Rule::exists('outlets', 'id')->where('business_id', $businessId)],
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'payment_method_code' => [
                'nullable', 'string', 'max:64',
                Rule::exists('payment_methods', 'code')->where('business_id', $businessId),
            ],
            'auto_accept' => 'nullable|boolean',
            'prep_buffer_minutes' => 'nullable|integer|min:0|max:240',
            'is_active' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SalesChannel $channel): array
    {
        return array_merge($channel->toArray(), [
            'webhook_url' => $channel->webhookUrl(),
            'has_credentials' => (bool) $channel->webhook_token,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMapping(ChannelMenuMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'external_item_id' => $mapping->external_item_id,
            'external_variant_id' => $mapping->external_variant_id ?: null,
            'product_id' => $mapping->product_id,
            'product_variant_id' => $mapping->product_variant_id,
            'product_name' => $mapping->product?->name,
            'variant_name' => $mapping->variant?->name,
            'option_map' => $mapping->option_map ?? [],
            'is_active' => $mapping->is_active,
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Services\ChannelService;
use Illuminate\Http\Request;

/**
 * Public entry point for channel partners pushing orders into Velora.
 *
 * The route is not behind Sanctum: a marketplace calls it server to server.
 * The unguessable webhook token picks the channel (and with it the tenant), and
 * every request must carry an HMAC-SHA256 signature of the raw body made with
 * the channel secret.
 */
class ChannelWebhookController extends Controller
{
    public function __construct(protected ChannelService $channels) {}

    public function receive(Request $request, string $token)
    {
        $channel = SalesChannel::where('webhook_token', $token)->where('is_active', true)->first();
        if (! $channel) {
            return $this->error('Not found', 404, [], 'CHANNEL_NOT_FOUND');
        }

        $secret = (string) $channel->secret;
        $signature = (string) $request->header('X-Channel-Signature', '');
        $expected = $secret !== '' ? hash_hmac('sha256', $request->getContent(), $secret) : '';

        if ($expected === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            return $this->error('Invalid channel signature', 401, [], 'CHANNEL_AUTH');
        }

        $data = $request->validate([
            'event' => 'nullable|in:order.created,order.cancelled',
            'external_order_id' => 'required|string|max:128',
            'outlet_id' => 'nullable|integer',
            'order_type' => 'nullable|in:delivery,pickup',
            'placed_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'promotion_code' => 'nullable|string|max:64',
            'reason' => 'nullable|string|max:500',
            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.phone' => 'nullable|string|max:32',
            'customer.email' => 'nullable|email|max:255',
            'customer.address' => 'nullable|string|max:500',
            'fulfilment' => 'nullable|array',
            'fulfilment.contact_name' => 'nullable|string|max:255',
            'fulfilment.contact_phone' => 'nullable|string|max:32',
            'fulfilment.address' => 'nullable|string|max:500',
            'fulfilment.instructions' => 'nullable|string|max:500',
            'fulfilment.scheduled_at' => 'nullable|date',
            'items' => 'required_without:event|array|min:1',
            'items.*.external_item_id' => 'required_with:items|string|max:128',
            'items.*.external_variant_id' => 'nullable|string|max:128',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.options' => 'nullable|array',
            'items.*.options.*' => 'string|max:128',
            'items.*.notes' => 'nullable|string|max:255',
        ]);

        if (($data['event'] ?? 'order.created') === 'order.cancelled') {
            $sale = Sale::where('business_id', $channel->business_id)
                ->where('sales_channel_id', $channel->id)
                ->where('channel_order_ref', $data['external_order_id'])
                ->first();

            if (! $sale) {
                return $this->error('Not found', 404);
            }

            if (! $sale->isOpenOrder()) {
                return $this->error(
                    'Only open channel orders can be cancelled; refund completed orders in Velora',
                    422,
                    [],
                    'CHANNEL_ERROR'
                );
            }

            $this->channels->reject($sale, $data['reason'] ?? 'Cancelled by the channel');

            return $this->success($this->presentOrder($sale->fresh()->load('items')), 'Channel order cancelled');
        }

        try {
            $result = $this->channels->createOrder($channel, $data);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422, [], 'CHANNEL_ERROR');
        }

        return $this->success(
            $this->presentOrder($result['sale']),
            $result['created'] ? 'Channel order received' : 'Channel order already exists',
            $result['created'] ? 201 : 200
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'order_number' => $sale->order_number,
            'external_order_id' => $sale->channel_order_ref,
            'channel_status' => $sale->channel_status,
            'order_status' => $sale->order_status,
            'order_type' => $sale->order_type,
            'total' => (string) $sale->total,
            'commission' => (string) $sale->channel_commission,
            'fulfilment' => $sale->fulfilment,
            'items' => $sale->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (string) $item->quantity,
                'line_total' => (string) $item->line_total,
            ])->all(),
            'created_at' => $sale->created_at?->toIso8601String(),
        ];
    }
}

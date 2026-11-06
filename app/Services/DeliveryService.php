<?php

namespace App\Services;

use App\Exceptions\DeliveryException;
use App\Models\Delivery;
use App\Models\DeliveryEvent;
use App\Models\DeliveryLine;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\StorefrontOrder;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeliveryService
{
    public function __construct(protected StorefrontService $storefronts) {}

    public function number(): string
    {
        return 'DL-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $businessId, array $data, ?int $actorId = null): Delivery
    {
        $outlet = Outlet::where('id', $data['outlet_id'])->where('business_id', $businessId)->first();
        if (! $outlet) {
            throw new DeliveryException('Outlet not found.', 'OUTLET_NOT_FOUND');
        }

        $sale = null;
        if (! empty($data['sale_id'])) {
            $sale = Sale::where('id', $data['sale_id'])->where('business_id', $businessId)->first();
            if (! $sale) {
                throw new DeliveryException('Sale not found.', 'SALE_NOT_FOUND');
            }
        }

        $order = null;
        if (! empty($data['storefront_order_id'])) {
            $order = StorefrontOrder::where('id', $data['storefront_order_id'])
                ->where('business_id', $businessId)
                ->first();
            if (! $order) {
                throw new DeliveryException('Order not found.', 'ORDER_NOT_FOUND');
            }
        }

        $zone = null;
        if (! empty($data['delivery_zone_id'])) {
            $zone = DeliveryZone::where('id', $data['delivery_zone_id'])
                ->where('business_id', $businessId)
                ->first();
            if (! $zone) {
                throw new DeliveryException('Delivery zone not found.', 'ZONE_NOT_FOUND');
            }
        }

        return DB::transaction(function () use ($businessId, $data, $sale, $order, $zone, $outlet, $actorId) {
            $fulfilment = $sale?->fulfilment ?? [];
            $address = $data['address'] ?? data_get($fulfilment, 'address') ?? $order?->delivery_address ?? null;

            $delivery = Delivery::create([
                'business_id' => $businessId,
                'outlet_id' => $outlet->id,
                'sale_id' => $sale?->id,
                'storefront_order_id' => $order?->id,
                'delivery_zone_id' => $zone?->id,
                'number' => $this->number(),
                'status' => ! empty($data['scheduled_at']) ? 'scheduled' : 'pending',
                'recipient_name' => $data['recipient_name'] ?? data_get($fulfilment, 'contact_name') ?? $order?->customer_name,
                'recipient_phone' => $data['recipient_phone'] ?? data_get($fulfilment, 'contact_phone') ?? $order?->customer_phone,
                'address' => is_array($address) ? $address : ($address ? ['line1' => $address] : null),
                'fee' => $data['fee'] ?? ($zone ? (string) $zone->fee : ($order ? (string) $order->delivery_fee : '0')),
                'distance_km' => $data['distance_km'] ?? null,
                'sequence' => $data['sequence'] ?? 0,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($sale) {
                foreach ($sale->items as $item) {
                    DeliveryLine::create([
                        'delivery_id' => $delivery->id,
                        'sale_item_id' => $item->id,
                        'description' => $item->product_name,
                        'quantity' => $item->quantity,
                    ]);
                }
            }

            $this->event($delivery, $delivery->status, 'Delivery created.', $actorId);

            return $delivery->fresh()->load('lines', 'events', 'zone', 'sale');
        });
    }

    public function createForStorefrontOrder(StorefrontOrder $order, ?int $actorId = null): Delivery
    {
        return $this->create($order->business_id, [
            'outlet_id' => $order->outlet_id,
            'storefront_order_id' => $order->id,
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $order->customer_phone,
            'address' => $order->delivery_address,
            'fee' => (string) $order->delivery_fee,
            'scheduled_at' => $order->scheduled_at?->toDateTimeString(),
        ], $actorId);
    }

    public function assign(Delivery $delivery, ?int $driverId, ?int $vehicleId, ?int $actorId = null): Delivery
    {
        if (! in_array($delivery->status, ['pending', 'scheduled', 'assigned'], true)) {
            throw new DeliveryException('Only undispatched deliveries can be assigned.', 'INVALID_STATUS');
        }

        $driver = null;
        if ($driverId) {
            $driver = Driver::where('id', $driverId)
                ->where('business_id', $delivery->business_id)
                ->where('is_active', true)
                ->first();
            if (! $driver) {
                throw new DeliveryException('Driver not found or inactive.', 'DRIVER_NOT_FOUND');
            }
        }

        $vehicle = null;
        if ($vehicleId) {
            $vehicle = Vehicle::where('id', $vehicleId)
                ->where('business_id', $delivery->business_id)
                ->where('is_active', true)
                ->first();
            if (! $vehicle) {
                throw new DeliveryException('Vehicle not found or inactive.', 'VEHICLE_NOT_FOUND');
            }
        }

        return DB::transaction(function () use ($delivery, $driver, $vehicle, $actorId) {
            $delivery->update([
                'driver_id' => $driver?->id ?? $delivery->driver_id,
                'vehicle_id' => $vehicle?->id ?? $delivery->vehicle_id,
                'status' => $delivery->status === 'pending' ? 'assigned' : $delivery->status,
            ]);

            if ($driver) {
                $driver->update(['status' => 'on_delivery']);
            }
            if ($vehicle) {
                $vehicle->update(['status' => 'in_use']);
            }

            $this->event($delivery, 'assigned', 'Assigned to '.($driver?->name ?? 'a driver').'.', $actorId);

            return $delivery->fresh()->load('driver', 'vehicle', 'events');
        });
    }

    public function transition(Delivery $delivery, string $status, ?int $actorId = null, ?string $note = null, ?float $latitude = null, ?float $longitude = null): Delivery
    {
        $allowed = [
            'pending' => ['scheduled', 'assigned', 'cancelled'],
            'scheduled' => ['assigned', 'cancelled'],
            'assigned' => ['picked_up', 'cancelled'],
            'picked_up' => ['in_transit', 'delivered', 'failed'],
            'in_transit' => ['delivered', 'failed'],
            'delivered' => [],
            'failed' => [],
            'cancelled' => [],
        ];

        if (! in_array($status, Delivery::STATUSES, true)) {
            throw new DeliveryException('Unknown delivery status.', 'INVALID_STATUS');
        }
        if (! in_array($status, $allowed[$delivery->status] ?? [], true)) {
            throw new DeliveryException("Cannot move a delivery from {$delivery->status} to {$status}.", 'INVALID_TRANSITION');
        }

        return DB::transaction(function () use ($delivery, $status, $actorId, $note, $latitude, $longitude) {
            $delivery->status = $status;
            if (in_array($status, ['picked_up', 'in_transit'], true) && ! $delivery->dispatched_at) {
                $delivery->dispatched_at = now();
            }
            if ($status === 'delivered') {
                $delivery->delivered_at = now();
            }
            $delivery->save();

            if (in_array($status, ['delivered', 'failed', 'cancelled'], true)) {
                $this->releaseDriver($delivery);
            }

            $this->event($delivery, $status, $note, $actorId, $latitude, $longitude);
            $this->syncStorefrontOrder($delivery, $status, $actorId);

            return $delivery->fresh()->load('driver', 'vehicle', 'events', 'lines');
        });
    }

    /**
     * @param  array<string, mixed>  $proof
     */
    public function proof(Delivery $delivery, array $proof, ?int $actorId = null): Delivery
    {
        if (! in_array($delivery->status, ['picked_up', 'in_transit'], true)) {
            throw new DeliveryException('Proof of delivery needs a dispatched delivery.', 'INVALID_STATUS');
        }

        $delivery->update([
            'proof' => $proof,
            'recipient_name' => $proof['recipient_name'] ?? $delivery->recipient_name,
            'collected_amount' => $proof['collected_amount'] ?? $delivery->collected_amount,
        ]);

        return $this->transition($delivery->fresh(), 'delivered', $actorId, $proof['notes'] ?? 'Proof of delivery captured.');
    }

    /**
     * Deliver part of the load now and spawn a follow-up delivery for the
     * remainder. Each line tracks how much has already been delivered.
     *
     * @param  array<int, array{delivery_line_id?: int, sale_item_id?: int, quantity: string|float|int}>  $items
     * @return array{delivery: Delivery, follow_up: Delivery|null}
     */
    public function partial(Delivery $delivery, array $items, ?int $actorId = null): array
    {
        if ($items === []) {
            throw new DeliveryException('Select at least one line to deliver.', 'NO_LINES');
        }

        return DB::transaction(function () use ($delivery, $items, $actorId) {
            $delivery->load('lines');
            $remaining = [];

            foreach ($items as $entry) {
                $line = $delivery->lines->firstWhere('id', $entry['delivery_line_id'] ?? null)
                    ?? $delivery->lines->firstWhere('sale_item_id', $entry['sale_item_id'] ?? null);
                if (! $line) {
                    throw new DeliveryException('Delivery line not found.', 'LINE_NOT_FOUND');
                }

                $quantity = (string) $entry['quantity'];
                $outstanding = bcsub((string) $line->quantity, (string) $line->delivered_quantity, 4);
                if (bccomp($quantity, '0', 4) !== 1 || bccomp($quantity, $outstanding, 4) === 1) {
                    throw new DeliveryException('Delivered quantity exceeds the outstanding quantity.', 'INVALID_QUANTITY');
                }

                $line->delivered_quantity = bcadd((string) $line->delivered_quantity, $quantity, 4);
                $line->save();

                $left = bcsub((string) $line->quantity, (string) $line->delivered_quantity, 4);
                if (bccomp($left, '0', 4) === 1) {
                    $remaining[] = ['line' => $line, 'quantity' => $left];
                }
            }

            $followUp = null;
            if ($remaining !== []) {
                $followUp = Delivery::create([
                    'business_id' => $delivery->business_id,
                    'outlet_id' => $delivery->outlet_id,
                    'sale_id' => $delivery->sale_id,
                    'storefront_order_id' => $delivery->storefront_order_id,
                    'delivery_zone_id' => $delivery->delivery_zone_id,
                    'driver_id' => $delivery->driver_id,
                    'vehicle_id' => $delivery->vehicle_id,
                    'number' => $this->number(),
                    'status' => 'pending',
                    'recipient_name' => $delivery->recipient_name,
                    'recipient_phone' => $delivery->recipient_phone,
                    'address' => $delivery->address,
                    'fee' => '0',
                    'sequence' => (int) $delivery->sequence + 1,
                    'notes' => 'Follow-up for '.$delivery->number,
                ]);

                foreach ($remaining as $entry) {
                    DeliveryLine::create([
                        'delivery_id' => $followUp->id,
                        'sale_item_id' => $entry['line']->sale_item_id,
                        'description' => $entry['line']->description,
                        'quantity' => $entry['quantity'],
                    ]);
                }

                $this->event($followUp, 'pending', 'Created from partial delivery '.$delivery->number.'.', $actorId);
            }

            $this->event($delivery, $delivery->status, 'Partial delivery recorded'.($followUp ? '; follow-up '.$followUp->number : '').'.', $actorId);

            return ['delivery' => $delivery->fresh()->load('lines', 'events'), 'follow_up' => $followUp?->fresh()->load('lines')];
        });
    }

    public function reconcile(Delivery $delivery, $collectedAmount, ?int $actorId = null, ?string $notes = null): Delivery
    {
        if ($delivery->status !== 'delivered') {
            throw new DeliveryException('Only delivered jobs can be reconciled.', 'INVALID_STATUS');
        }
        if ($delivery->reconciled_at) {
            throw new DeliveryException('This delivery is already reconciled.', 'ALREADY_RECONCILED');
        }

        return DB::transaction(function () use ($delivery, $collectedAmount, $actorId, $notes) {
            $delivery->update([
                'collected_amount' => $collectedAmount,
                'reconciled_at' => now(),
                'reconciled_by' => $actorId,
            ]);

            $this->event($delivery, 'delivered', $notes ?: 'Cash reconciled.', $actorId);

            return $delivery->fresh();
        });
    }

    /**
     * Dispatch board: every delivery scheduled for a day, grouped by driver.
     */
    public function board(int $businessId, ?string $date = null, ?int $outletId = null): array
    {
        $day = $date ? Carbon::parse($date) : Carbon::today();

        $deliveries = Delivery::where('business_id', $businessId)
            ->when($outletId, fn ($query) => $query->where('outlet_id', $outletId))
            ->where(function ($query) use ($day) {
                $query->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                    ->orWhere(function ($inner) use ($day) {
                        $inner->whereNull('scheduled_at')
                            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
                    });
            })
            ->with('driver', 'vehicle', 'zone')
            ->orderBy('sequence')
            ->get();

        return [
            'date' => $day->toDateString(),
            'deliveries' => $deliveries,
            'summary' => [
                'total' => $deliveries->count(),
                'unassigned' => $deliveries->whereNull('driver_id')->count(),
                'in_progress' => $deliveries->whereIn('status', ['assigned', 'picked_up', 'in_transit'])->count(),
                'delivered' => $deliveries->where('status', 'delivered')->count(),
                'fees' => number_format((float) $deliveries->sum('fee'), 4, '.', ''),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */

    private function event(Delivery $delivery, string $status, ?string $note = null, ?int $actorId = null, ?float $latitude = null, ?float $longitude = null): void
    {
        DeliveryEvent::create([
            'delivery_id' => $delivery->id,
            'status' => $status,
            'note' => $note,
            'actor_type' => 'staff',
            'actor_id' => $actorId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    private function releaseDriver(Delivery $delivery): void
    {
        if ($delivery->driver_id) {
            $activeJobs = Delivery::where('driver_id', $delivery->driver_id)
                ->whereKeyNot($delivery->id)
                ->whereIn('status', Delivery::ACTIVE_STATUSES)
                ->count();
            if ($activeJobs === 0) {
                Driver::whereKey($delivery->driver_id)->update(['status' => 'available']);
            }
        }

        if ($delivery->vehicle_id) {
            $activeJobs = Delivery::where('vehicle_id', $delivery->vehicle_id)
                ->whereKeyNot($delivery->id)
                ->whereIn('status', Delivery::ACTIVE_STATUSES)
                ->count();
            if ($activeJobs === 0) {
                Vehicle::whereKey($delivery->vehicle_id)->update(['status' => 'available']);
            }
        }
    }

    private function syncStorefrontOrder(Delivery $delivery, string $status, ?int $actorId): void
    {
        if (! $delivery->storefront_order_id) {
            return;
        }

        $order = StorefrontOrder::find($delivery->storefront_order_id);
        if (! $order) {
            return;
        }

        try {
            if (in_array($status, ['picked_up', 'in_transit'], true) && $order->status === 'ready') {
                $this->storefronts->transition($order, 'out_for_delivery', $actorId, 'Out for delivery.');
            } elseif ($status === 'delivered' && $order->status === 'out_for_delivery') {
                $this->storefronts->transition($order, 'delivered', $actorId, 'Delivered.');
            }
        } catch (\Throwable) {
            // The order board may already have moved the order on; the delivery
            // remains the source of truth for the courier timeline.
        }
    }
}

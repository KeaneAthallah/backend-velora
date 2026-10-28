<?php

namespace App\Services;

use App\Models\Business;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketCounter;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KitchenService
{
    /**
     * Create one kitchen ticket per station for a sale that is being fired.
     *
     * Calling it twice is safe: existing tickets for the sale are returned
     * untouched, so re-firing never duplicates work in the kitchen.
     *
     * @return Collection<int, KitchenTicket>
     */
    public function fireOrder(Sale $sale): Collection
    {
        $existing = KitchenTicket::where('sale_id', $sale->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $stations = KitchenStation::where('business_id', $sale->business_id)
            ->where('outlet_id', $sale->outlet_id)
            ->active()
            ->with('categories:id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($stations->isEmpty()) {
            return collect();
        }

        $fallback = $stations->firstWhere('is_default', true) ?? $stations->first();
        $categoryStation = [];
        foreach ($stations as $station) {
            foreach ($station->categories as $category) {
                $categoryStation[$category->id] ??= $station;
            }
        }

        $sale->loadMissing('items.product:id,category_id');
        $grouped = [];

        foreach ($sale->items as $item) {
            $station = $categoryStation[$item->product?->category_id] ?? $fallback;
            $grouped[$station->id] ??= ['station' => $station, 'items' => []];
            $grouped[$station->id]['items'][] = $this->ticketItem($item);
        }

        $outlet = Outlet::findOrFail($sale->outlet_id);

        return DB::transaction(function () use ($grouped, $sale, $outlet) {
            $tickets = collect();

            foreach ($grouped as $group) {
                $tickets->push(KitchenTicket::create([
                    'business_id' => $sale->business_id,
                    'outlet_id' => $sale->outlet_id,
                    'sale_id' => $sale->id,
                    'kitchen_station_id' => $group['station']->id,
                    'ticket_number' => $this->nextTicketNumber($outlet),
                    'status' => 'queued',
                    'items' => $group['items'],
                    'note' => $sale->notes,
                ]));
            }

            return $tickets;
        });
    }

    /**
     * Keep ticket state in step when the order board moves an order forward.
     */
    public function syncFromOrderStatus(Sale $sale, string $orderStatus): void
    {
        $tickets = KitchenTicket::where('sale_id', $sale->id)->where('status', '!=', 'voided');
        if (! $tickets->exists()) {
            return;
        }

        if ($orderStatus === 'ready') {
            $tickets->get()->each(function (KitchenTicket $ticket) {
                if ($ticket->status === 'queued') {
                    $ticket->started_at = $ticket->started_at ?? now();
                }
                $ticket->status = 'ready';
                $ticket->ready_at = $ticket->ready_at ?? now();
                $ticket->save();
            });
        } elseif (in_array($orderStatus, ['served', 'completed'], true)) {
            $tickets->get()->each(function (KitchenTicket $ticket) {
                $ticket->started_at = $ticket->started_at ?? now();
                $ticket->ready_at = $ticket->ready_at ?? now();
                $ticket->status = 'served';
                $ticket->served_at = $ticket->served_at ?? now();
                $ticket->save();
            });
        }
    }

    /** Cancelled or voided orders must not linger on a station screen. */
    public function voidForSale(Sale $sale): void
    {
        KitchenTicket::where('sale_id', $sale->id)
            ->where('status', '!=', 'voided')
            ->update(['status' => 'voided', 'served_at' => now()]);
    }

    /**
     * Advance the order when every ticket is ready (or served).
     */
    public function refreshSaleStatus(Sale $sale): void
    {
        $tickets = KitchenTicket::where('sale_id', $sale->id)->where('status', '!=', 'voided')->get();
        $sale->loadMissing('items');

        if ($tickets->isEmpty() || $tickets->contains(fn (KitchenTicket $ticket) => ! in_array($ticket->status, ['ready', 'served'], true))) {
            return;
        }

        $allServed = $tickets->every(fn (KitchenTicket $ticket) => $ticket->status === 'served');
        $target = $allServed ? 'served' : 'ready';

        if (! $sale->isOpenOrder() || $sale->order_status === 'completed') {
            return;
        }

        $currentRank = array_search($sale->order_status, ['open', 'in_kitchen', 'ready', 'served', 'completed'], true) ?: 0;
        $targetRank = array_search($target, ['open', 'in_kitchen', 'ready', 'served', 'completed'], true) ?: 0;

        if ($targetRank <= $currentRank) {
            return;
        }

        $sale->order_status = $target;
        if ($target === 'ready') {
            $sale->ready_at = $sale->ready_at ?? now();
        } else {
            $sale->ready_at = $sale->ready_at ?? now();
            $sale->served_at = now();
        }
        $sale->save();
    }

    /**
     * Plain-text ticket for a 42-column thermal printer or browser print.
     */
    public function printText(KitchenTicket $ticket): string
    {
        $ticket->loadMissing('station', 'sale.table', 'sale');
        $width = 42;
        $line = str_repeat('-', $width);
        $sale = $ticket->sale;
        $station = $ticket->station;

        $rows = [];
        $rows[] = $this->center(strtoupper($station?->name ?? 'KITCHEN'), $width);
        $rows[] = $line;
        $rows[] = 'Ticket '.$ticket->ticket_number;
        $rows[] = 'Order  '.($sale?->order_number ?? $sale?->receipt_number ?? '#'.$ticket->sale_id);
        $rows[] = 'Type   '.str_replace('_', ' ', ucfirst($sale?->order_type ?? 'takeaway'));
        if ($sale?->table) {
            $rows[] = 'Table  '.$sale->table->name;
        }
        $rows[] = 'Time   '.$ticket->created_at?->timezone($this->timezone($sale))->format('H:i');
        if ($ticket->note) {
            $rows[] = 'Note   '.$this->wrap($ticket->note, $width - 7);
        }
        $rows[] = $line;

        foreach ($ticket->items as $item) {
            $rows[] = $this->quantity($item['quantity'] ?? 1).' x '.($item['name'] ?? 'Item');
            foreach ($item['modifiers'] ?? [] as $modifier) {
                $rows[] = '    + '.$modifier;
            }
            if (! empty($item['notes'])) {
                $rows[] = '    >> '.$item['notes'];
            }
        }

        $rows[] = $line;
        $rows[] = 'Station '.($station?->code ?? '-').'   Printed '.now()->timezone($this->timezone($sale))->format('H:i');

        return implode("\n", $rows);
    }

    public function ticketPayload(KitchenTicket $ticket, bool $withText = false): array
    {
        $ticket->loadMissing('station', 'sale.table');

        $payload = [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'note' => $ticket->note,
            'items' => $ticket->items,
            'printed_at' => $ticket->printed_at?->toIso8601String(),
            'print_count' => $ticket->print_count,
            'started_at' => $ticket->started_at?->toIso8601String(),
            'ready_at' => $ticket->ready_at?->toIso8601String(),
            'served_at' => $ticket->served_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'station' => [
                'id' => $ticket->station?->id,
                'name' => $ticket->station?->name,
                'code' => $ticket->station?->code,
                'kind' => $ticket->station?->kind,
                'printer_name' => $ticket->station?->printer_name,
                'printer_target' => $ticket->station?->printer_target,
                'copies' => $ticket->station?->copies ?? 1,
                'auto_print' => (bool) $ticket->station?->auto_print,
            ],
            'sale' => [
                'id' => $ticket->sale?->id,
                'order_number' => $ticket->sale?->order_number,
                'order_type' => $ticket->sale?->order_type,
                'order_status' => $ticket->sale?->order_status,
                'table' => $ticket->sale?->table?->name,
                'notes' => $ticket->sale?->notes,
            ],
        ];

        if ($withText) {
            $payload['text'] = $this->printText($ticket);
        }

        return $payload;
    }

    /** Next ticket number for an outlet, resetting on the business day. */
    public function nextTicketNumber(Outlet $outlet): string
    {
        $outlet->loadMissing('business');
        $timezone = $this->timezone(null, $outlet);
        $now = Carbon::now($timezone);
        $businessDate = $now->format('Y-m-d');

        return DB::transaction(function () use ($outlet, $businessDate, $now) {
            $counter = KitchenTicketCounter::lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outlet->id, 'business_date' => $businessDate],
                ['business_id' => $outlet->business_id, 'last_number' => 0]
            );

            $counter->last_number = (int) $counter->last_number + 1;
            $counter->save();

            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($outlet->code ?: 'O'.$outlet->id)), 0, 8));

            return sprintf('%s-K-%s-%03d', $code, $now->format('Ymd'), $counter->last_number);
        });
    }

    private function ticketItem(SaleItem $item): array
    {
        return [
            'sale_item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->product_name,
            'quantity' => $this->quantity($item->quantity),
            'notes' => $item->notes,
            'modifiers' => collect($item->modifiers ?? [])->pluck('option_name')->filter()->values()->all(),
        ];
    }

    private function quantity(mixed $quantity): string
    {
        $value = rtrim(rtrim(number_format((float) $quantity, 4, '.', ''), '0'), '.');

        return $value === '' ? '0' : $value;
    }

    private function timezone(?Sale $sale, ?Outlet $outlet = null): string
    {
        $outlet ??= $sale?->outlet ?? ($sale ? Outlet::find($sale->outlet_id) : null);

        return $outlet?->timezone
            ?? $outlet?->business?->timezone
            ?? ($sale ? Business::find($sale->business_id)?->timezone : null)
            ?? config('app.timezone');
    }

    private function center(string $text, int $width): string
    {
        $pad = max(0, (int) floor(($width - mb_strlen($text)) / 2));

        return str_repeat(' ', $pad).$text;
    }

    private function wrap(string $text, int $width): string
    {
        return wordwrap($text, max(10, $width), "\n", true);
    }
}

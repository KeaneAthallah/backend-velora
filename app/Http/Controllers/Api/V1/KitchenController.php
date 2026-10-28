<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\KitchenTicket;
use App\Models\Outlet;
use App\Services\AuthorizationService;
use App\Services\ChannelService;
use App\Services\KitchenService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KitchenController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected KitchenService $kitchen,
        protected ChannelService $channels,
    ) {}

    public function stations(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'kitchen.view', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }

        $query = KitchenStation::where('business_id', $businessId)->with('categories:id,name');
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        return $this->success(
            $query->orderBy('sort_order')->orderBy('name')->get()->map(fn (KitchenStation $station) => $this->stationPayload($station))
        );
    }

    public function storeStation(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'kitchen.manage', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $this->validateStation($request, $outletId);

        $station = KitchenStation::create([
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'name' => $data['name'],
            'code' => $this->stationCode($data['code'] ?? null, $data['name'], $outletId),
            'kind' => $data['kind'] ?? 'kitchen',
            'printer_name' => $data['printer_name'] ?? null,
            'printer_target' => $data['printer_target'] ?? null,
            'copies' => $data['copies'] ?? 1,
            'auto_print' => $data['auto_print'] ?? true,
            'is_default' => $data['is_default'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->syncCategories($station, $data['category_ids'] ?? []);
        $this->ensureSingleDefault($station);

        return $this->success($this->stationPayload($station->load('categories:id,name')), 'Station created', 201);
    }

    public function updateStation(Request $request, KitchenStation $station)
    {
        $businessId = $request->attributes->get('business_id');
        if ($station->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'kitchen.manage', $businessId);

        $data = $this->validateStation($request, $station->outlet_id, $station->id);

        if (array_key_exists('code', $data) && $data['code']) {
            $data['code'] = $this->stationCode($data['code'], $station->name, $station->outlet_id, $station->id);
        }

        $station->fill(collect($data)->except('category_ids')->all())->save();
        if (array_key_exists('category_ids', $data)) {
            $this->syncCategories($station, $data['category_ids'] ?? []);
        }
        $this->ensureSingleDefault($station);

        return $this->success($this->stationPayload($station->fresh()->load('categories:id,name')), 'Station updated');
    }

    public function destroyStation(Request $request, KitchenStation $station)
    {
        $businessId = $request->attributes->get('business_id');
        if ($station->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'kitchen.manage', $businessId);

        if ($station->tickets()->exists()) {
            return $this->error('This station already has tickets. Deactivate it instead.', 422, [], 'KITCHEN_ERROR');
        }

        $station->delete();

        return $this->success(null, 'Station deleted');
    }

    public function tickets(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'kitchen.view', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }

        $status = $request->input('status', 'active');
        $query = KitchenTicket::where('business_id', $businessId)->with('station', 'sale.table');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if ($request->filled('station_id')) {
            $stationId = (int) $request->input('station_id');
            if (! KitchenStation::where('id', $stationId)->where('business_id', $businessId)->exists()) {
                return $this->error('Not found', 404);
            }
            $query->where('kitchen_station_id', $stationId);
        }
        if ($status === 'active') {
            $query->active();
        } elseif ($status !== 'all' && in_array($status, KitchenTicket::STATUSES, true)) {
            $query->where('status', $status);
        }

        $tickets = $query->orderBy('created_at')->orderBy('id')->limit(200)->get();

        $counts = KitchenTicket::where('business_id', $businessId)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($request->filled('station_id'), fn ($q) => $q->where('kitchen_station_id', (int) $request->input('station_id')))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return $this->success([
            'tickets' => $tickets->map(fn (KitchenTicket $ticket) => $this->kitchen->ticketPayload($ticket))->values(),
            'counts' => [
                'queued' => (int) ($counts['queued'] ?? 0),
                'preparing' => (int) ($counts['preparing'] ?? 0),
                'ready' => (int) ($counts['ready'] ?? 0),
                'served' => (int) ($counts['served'] ?? 0),
                'voided' => (int) ($counts['voided'] ?? 0),
            ],
        ]);
    }

    public function ticketStatus(Request $request, KitchenTicket $ticket)
    {
        $businessId = $request->attributes->get('business_id');
        if ($ticket->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'kitchen.manage', $businessId);

        $data = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'served'])],
        ]);

        $next = $data['status'];

        if ($ticket->status === 'voided') {
            return $this->error('This ticket was cancelled with its order', 422, [], 'KITCHEN_ERROR');
        }
        if ($next === $ticket->status) {
            return $this->success($this->kitchen->ticketPayload($ticket->fresh()));
        }
        if (KitchenTicket::STATUS_RANK[$next] <= KitchenTicket::STATUS_RANK[$ticket->status]) {
            return $this->error('Tickets can only move forward through the kitchen', 422, [], 'KITCHEN_ERROR');
        }

        $ticket->status = $next;
        if ($next === 'preparing') {
            $ticket->started_at = $ticket->started_at ?? now();
        } elseif ($next === 'ready') {
            $ticket->started_at = $ticket->started_at ?? now();
            $ticket->ready_at = $ticket->ready_at ?? now();
        } else {
            $ticket->started_at = $ticket->started_at ?? now();
            $ticket->ready_at = $ticket->ready_at ?? now();
            $ticket->served_at = now();
        }
        $ticket->save();

        $this->kitchen->refreshSaleStatus($ticket->sale);

        // A ticket moving to ready can roll the order forward, which a channel
        // partner needs to hear about too.
        $sale = $ticket->sale->fresh();
        if ($sale) {
            $this->channels->syncOrderStatus($sale, $sale->order_status);
        }

        return $this->success($this->kitchen->ticketPayload($ticket->fresh()), 'Ticket updated');
    }

    public function printTicket(Request $request, KitchenTicket $ticket)
    {
        $businessId = $request->attributes->get('business_id');
        if ($ticket->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'kitchen.print', $businessId);

        return $this->success($this->kitchen->ticketPayload($ticket, true));
    }

    public function markPrinted(Request $request, KitchenTicket $ticket)
    {
        $businessId = $request->attributes->get('business_id');
        if ($ticket->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'kitchen.print', $businessId);

        $ticket->printed_at = now();
        $ticket->print_count = (int) $ticket->print_count + 1;
        $ticket->save();

        return $this->success($this->kitchen->ticketPayload($ticket->fresh()), 'Ticket marked as printed');
    }

    /**
     * Returns the outlet id from the header or query string, null when the
     * caller wants every outlet, or false when the outlet is not ours.
     */
    private function resolveOutlet(Request $request, int $businessId): int|false|null
    {
        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if (! $outletId) {
            return null;
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return false;
        }

        return (int) $outletId;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStation(Request $request, int $outletId, ?int $ignoreId = null): array
    {
        $businessId = $request->attributes->get('business_id');

        return $request->validate([
            'name' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:80',
                Rule::unique('kitchen_stations', 'name')->where('outlet_id', $outletId)->ignore($ignoreId),
            ],
            'code' => [
                'nullable',
                'string',
                'max:12',
                Rule::unique('kitchen_stations', 'code')->where('outlet_id', $outletId)->ignore($ignoreId),
            ],
            'kind' => ['sometimes', Rule::in(KitchenStation::KINDS)],
            'printer_name' => 'nullable|string|max:80',
            'printer_target' => 'nullable|string|max:120',
            'copies' => 'sometimes|integer|min:1|max:5',
            'auto_print' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:999',
            'category_ids' => 'sometimes|array',
            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where('business_id', $businessId),
            ],
        ]);
    }

    /**
     * @param  array<int, int|string>  $categoryIds
     */
    private function syncCategories(KitchenStation $station, array $categoryIds): void
    {
        $ids = Category::where('business_id', $station->business_id)
            ->whereIn('id', array_map('intval', $categoryIds))
            ->pluck('id')
            ->all();

        $station->categories()->sync($ids);
    }

    /** Only one station can catch unrouted items per outlet. */
    private function ensureSingleDefault(KitchenStation $station): void
    {
        if (! $station->is_default) {
            return;
        }

        KitchenStation::where('outlet_id', $station->outlet_id)
            ->where('id', '!=', $station->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function stationCode(?string $code, string $name, int $outletId, ?int $ignoreId = null): string
    {
        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($code ?: $name)));
        $candidate = substr($candidate !== '' ? $candidate : 'KIT', 0, 6) ?: 'KIT';
        $base = $candidate;
        $suffix = 1;

        while (KitchenStation::where('outlet_id', $outletId)
            ->where('code', $candidate)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = substr($base, 0, 4).$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function stationPayload(KitchenStation $station): array
    {
        return [
            'id' => $station->id,
            'outlet_id' => $station->outlet_id,
            'name' => $station->name,
            'code' => $station->code,
            'kind' => $station->kind,
            'printer_name' => $station->printer_name,
            'printer_target' => $station->printer_target,
            'copies' => (int) $station->copies,
            'auto_print' => (bool) $station->auto_print,
            'is_default' => (bool) $station->is_default,
            'is_active' => (bool) $station->is_active,
            'sort_order' => (int) $station->sort_order,
            'categories' => $station->categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\FloorArea;
use App\Models\Outlet;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'tables.view', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }

        $includeInactive = $request->boolean('include_inactive');

        $areasQuery = FloorArea::where('business_id', $businessId);
        $tablesQuery = DiningTable::where('business_id', $businessId)
            ->with(['area', 'outlet', 'activeOrder' => fn ($query) => $query->withCount('items')]);

        if ($outletId) {
            $areasQuery->where('outlet_id', $outletId);
            $tablesQuery->where('outlet_id', $outletId);
        }
        if (! $includeInactive) {
            $tablesQuery->where('is_active', true);
        }

        $areas = $areasQuery->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (FloorArea $area) => $this->areaPayload($area));

        $tables = $tablesQuery->orderBy('name')->get()
            ->map(fn (DiningTable $table) => $this->tablePayload($table));

        return $this->success([
            'areas' => $areas,
            'tables' => $tables,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $this->validateTable($request, $outletId);

        $table = DiningTable::create([
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'floor_area_id' => $data['floor_area_id'] ?? null,
            'name' => $data['name'],
            'seats' => $data['seats'] ?? 2,
            'shape' => $data['shape'] ?? 'square',
            'position_x' => $data['position_x'] ?? 0,
            'position_y' => $data['position_y'] ?? 0,
            'status' => $data['status'] ?? 'available',
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->success($this->tablePayload($table->load('area', 'outlet')), 'Table created', 201);
    }

    public function update(Request $request, DiningTable $table)
    {
        $businessId = $request->attributes->get('business_id');
        if ($table->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        $data = $this->validateTable($request, $table->outlet_id, $table->id);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $table->isOccupied()) {
            return $this->error('A table with an open order cannot be deactivated', 422, [], 'TABLE_ERROR');
        }

        $table->fill($data)->save();

        return $this->success($this->tablePayload($table->fresh()->load('area', 'outlet')), 'Table updated');
    }

    public function destroy(Request $request, DiningTable $table)
    {
        $businessId = $request->attributes->get('business_id');
        if ($table->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        if ($table->sales()->exists()) {
            return $this->error('This table has sales history. Deactivate it instead.', 422, [], 'TABLE_ERROR');
        }

        $table->delete();

        return $this->success(null, 'Table deleted');
    }

    public function status(Request $request, DiningTable $table)
    {
        $businessId = $request->attributes->get('business_id');
        if ($table->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        $data = $request->validate([
            'status' => ['required', Rule::in(DiningTable::STATUSES)],
        ]);

        if ($table->isOccupied() && $data['status'] !== $table->status) {
            return $this->error('This table has an open order. Check the order out or cancel it first.', 422, [], 'TABLE_ERROR');
        }

        $table->status = $data['status'];
        $table->save();

        return $this->success($this->tablePayload($table->fresh()->load('area', 'outlet')), 'Table updated');
    }

    public function areas(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'tables.view', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }

        $query = FloorArea::where('business_id', $businessId);
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        return $this->success(
            $query->orderBy('sort_order')->orderBy('name')->get()->map(fn (FloorArea $area) => $this->areaPayload($area))
        );
    }

    public function storeArea(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        $outletId = $this->resolveOutlet($request, $businessId);
        if ($outletId === false) {
            return $this->error('Not found', 404);
        }
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }

        $data = $this->validateArea($request, $outletId);

        $area = FloorArea::create([
            'business_id' => $businessId,
            'outlet_id' => $outletId,
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $this->success($this->areaPayload($area), 'Area created', 201);
    }

    public function updateArea(Request $request, FloorArea $area)
    {
        $businessId = $request->attributes->get('business_id');
        if ($area->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        $data = $this->validateArea($request, $area->outlet_id, $area->id);

        $area->fill($data)->save();

        return $this->success($this->areaPayload($area->fresh()), 'Area updated');
    }

    public function destroyArea(Request $request, FloorArea $area)
    {
        $businessId = $request->attributes->get('business_id');
        if ($area->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'tables.manage', $businessId);

        if ($area->tables()->exists()) {
            return $this->error('Move or delete the tables in this area first.', 422, [], 'TABLE_ERROR');
        }

        $area->delete();

        return $this->success(null, 'Area deleted');
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
    private function validateTable(Request $request, int $outletId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'floor_area_id' => [
                'nullable',
                'integer',
                Rule::exists('floor_areas', 'id')->where('business_id', $request->attributes->get('business_id'))->where('outlet_id', $outletId),
            ],
            'name' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:32',
                Rule::unique('dining_tables', 'name')->where('outlet_id', $outletId)->ignore($ignoreId),
            ],
            'seats' => 'sometimes|integer|min:1|max:200',
            'shape' => ['sometimes', Rule::in(DiningTable::SHAPES)],
            'position_x' => 'sometimes|integer|min:0|max:10000',
            'position_y' => 'sometimes|integer|min:0|max:10000',
            'status' => ['sometimes', Rule::in(DiningTable::STATUSES)],
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:255',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateArea(Request $request, int $outletId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                $ignoreId ? 'sometimes' : 'required',
                'string',
                'max:80',
                Rule::unique('floor_areas', 'name')->where('outlet_id', $outletId)->ignore($ignoreId),
            ],
            'sort_order' => 'sometimes|integer|min:0|max:999',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function areaPayload(FloorArea $area): array
    {
        return [
            'id' => $area->id,
            'outlet_id' => $area->outlet_id,
            'name' => $area->name,
            'sort_order' => $area->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tablePayload(DiningTable $table): array
    {
        $order = $table->relationLoaded('activeOrder') ? $table->activeOrder : $table->activeOrder()->withCount('items')->first();

        return [
            'id' => $table->id,
            'outlet_id' => $table->outlet_id,
            'outlet_name' => $table->relationLoaded('outlet') ? $table->outlet?->name : null,
            'name' => $table->name,
            'seats' => $table->seats,
            'shape' => $table->shape,
            'position_x' => $table->position_x,
            'position_y' => $table->position_y,
            'status' => $order ? 'occupied' : $table->status,
            'manual_status' => $table->status,
            'occupied' => (bool) $order,
            'is_active' => $table->is_active,
            'notes' => $table->notes,
            'area' => $table->area ? [
                'id' => $table->area->id,
                'name' => $table->area->name,
            ] : null,
            'active_order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_status' => $order->order_status,
                'total' => $order->total,
                'items_count' => (int) ($order->items_count ?? $order->items()->count()),
                'created_at' => $order->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}

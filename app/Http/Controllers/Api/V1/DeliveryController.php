<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DeliveryException;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Services\AuthorizationService;
use App\Services\DeliveryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected DeliveryService $deliveries,
    ) {}

    public function index(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'deliveries.view', $businessId);

        return $this->success(
            Delivery::where('business_id', $businessId)
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
                ->when($request->filled('driver_id'), fn ($query) => $query->where('driver_id', $request->input('driver_id')))
                ->when($request->filled('outlet_id'), fn ($query) => $query->where('outlet_id', $request->input('outlet_id')))
                ->when($request->filled('from'), fn ($query) => $query->where('scheduled_at', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($query) => $query->where('scheduled_at', '<=', $request->input('to')))
                ->with('driver', 'vehicle', 'zone')
                ->orderByDesc('id')
                ->paginate(50)
        );
    }

    public function board(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'deliveries.view', $businessId);

        return $this->success($this->deliveries->board(
            $businessId,
            $request->input('date'),
            $request->filled('outlet_id') ? (int) $request->input('outlet_id') : null,
        ));
    }

    public function store(Request $request)
    {
        $businessId = $this->businessId($request);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $businessId);

        $data = $request->validate([
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where('business_id', $businessId)->whereNull('deleted_at'),
            ],
            'sale_id' => [
                'nullable', 'integer',
                Rule::exists('sales', 'id')->where('business_id', $businessId),
            ],
            'storefront_order_id' => [
                'nullable', 'integer',
                Rule::exists('storefront_orders', 'id')->where('business_id', $businessId),
            ],
            'delivery_zone_id' => [
                'nullable', 'integer',
                Rule::exists('delivery_zones', 'id')->where('business_id', $businessId),
            ],
            'recipient_name' => 'nullable|string|max:120',
            'recipient_phone' => 'nullable|string|max:32',
            'address' => 'nullable|array',
            'fee' => 'nullable|numeric|min:0',
            'distance_km' => 'nullable|numeric|min:0',
            'sequence' => 'nullable|integer|min:0',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $delivery = $this->deliveries->create($businessId, $data, $request->user()->id);
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($delivery, 'Delivery created', 201);
    }

    public function show(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.view', $delivery->business_id);

        return $this->success($delivery->load('lines.saleItem', 'events', 'driver', 'vehicle', 'zone', 'sale', 'storefrontOrder'));
    }

    public function assign(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $delivery->business_id);

        $data = $request->validate([
            'driver_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer',
        ]);

        try {
            $delivery = $this->deliveries->assign(
                $delivery,
                isset($data['driver_id']) ? (int) $data['driver_id'] : null,
                isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                $request->user()->id,
            );
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($delivery, 'Delivery assigned');
    }

    public function status(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $delivery->business_id);

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', Delivery::STATUSES),
            'note' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $delivery = $this->deliveries->transition(
                $delivery,
                $data['status'],
                $request->user()->id,
                $data['note'] ?? null,
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
            );
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($delivery, 'Delivery updated');
    }

    public function proof(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $delivery->business_id);

        $data = $request->validate([
            'recipient_name' => 'nullable|string|max:120',
            'photo_url' => 'nullable|string|max:500',
            'signature' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'collected_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            $delivery = $this->deliveries->proof($delivery, $data, $request->user()->id);
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($delivery, 'Proof of delivery recorded');
    }

    public function partial(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $delivery->business_id);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.delivery_line_id' => 'nullable|integer',
            'items.*.sale_item_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
        ]);

        try {
            $result = $this->deliveries->partial($delivery, $data['items'], $request->user()->id);
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($result, 'Partial delivery recorded');
    }

    public function reconcile(Request $request, Delivery $delivery)
    {
        $this->guard($request, $delivery);
        $this->authz->requirePermission($request->user(), 'deliveries.manage', $delivery->business_id);

        $data = $request->validate([
            'collected_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $delivery = $this->deliveries->reconcile($delivery, $data['collected_amount'], $request->user()->id, $data['notes'] ?? null);
        } catch (DeliveryException $e) {
            return $this->error($e->getMessage(), 422, $e->errors, $e->errorCode);
        }

        return $this->success($delivery, 'Delivery reconciled');
    }

    /* ------------------------------------------------------------------ */

    private function guard(Request $request, Delivery $delivery): void
    {
        if ((int) $delivery->business_id !== $this->businessId($request)) {
            abort(404);
        }
    }

    private function businessId(Request $request): int
    {
        return (int) $request->attributes->get('business_id');
    }
}

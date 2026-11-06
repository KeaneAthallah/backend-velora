<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuthorizationService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookEndpointController extends Controller
{
    public function __construct(
        protected AuthorizationService $authz,
        protected WebhookService $webhooks,
    ) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.view', $businessId);

        $endpoints = WebhookEndpoint::where('business_id', $businessId)
            ->withCount('deliveries')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WebhookEndpoint $endpoint) => $this->endpointRow($endpoint));

        return $this->success([
            'endpoints' => $endpoints,
            'events' => WebhookEndpoint::EVENTS,
        ]);
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);

        $data = $this->validated($request);
        $secret = Str::random(48);

        $endpoint = WebhookEndpoint::create([
            ...$data,
            'business_id' => $businessId,
            'secret' => $secret,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success([
            'endpoint' => $this->endpointRow($endpoint),
            'secret' => $secret,
        ], 'Webhook endpoint created. Copy the signing secret now.', 201);
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);
        abort_unless($webhookEndpoint->business_id === $businessId, 404);

        $webhookEndpoint->update($this->validated($request, true));

        return $this->success(['endpoint' => $this->endpointRow($webhookEndpoint->fresh())], 'Webhook endpoint updated');
    }

    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);
        abort_unless($webhookEndpoint->business_id === $businessId, 404);

        $webhookEndpoint->delete();

        return $this->success(null, 'Webhook endpoint deleted');
    }

    public function rotateSecret(Request $request, WebhookEndpoint $webhookEndpoint)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);
        abort_unless($webhookEndpoint->business_id === $businessId, 404);

        $secret = Str::random(48);
        $webhookEndpoint->update(['secret' => $secret]);

        return $this->success(['endpoint' => $this->endpointRow($webhookEndpoint->fresh()), 'secret' => $secret], 'Signing secret rotated');
    }

    public function test(Request $request, WebhookEndpoint $webhookEndpoint)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);
        abort_unless($webhookEndpoint->business_id === $businessId, 404);

        $delivery = $this->webhooks->test($webhookEndpoint);

        return $this->success(['delivery' => $this->deliveryRow($delivery)], 'Test delivery sent');
    }

    public function deliveries(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.view', $businessId);

        $query = WebhookDelivery::with('endpoint:id,name,url')
            ->where('business_id', $businessId);

        if ($request->filled('endpoint_id')) {
            $query->where('webhook_endpoint_id', $request->input('endpoint_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        $deliveries = $query->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 50))
            ->through(fn (WebhookDelivery $delivery) => $this->deliveryRow($delivery));

        return $this->success($deliveries);
    }

    public function retry(Request $request, WebhookDelivery $webhookDelivery)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'webhooks.manage', $businessId);
        abort_unless($webhookDelivery->business_id === $businessId, 404);

        $delivery = $this->webhooks->retry($webhookDelivery);

        return $this->success(['delivery' => $this->deliveryRow($delivery)], 'Delivery retried');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'url' => [$required, 'url:https,http', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in([...WebhookEndpoint::EVENTS, '*'])],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function endpointRow(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => (string) $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'is_active' => $endpoint->is_active,
            'description' => $endpoint->description,
            'failure_count' => $endpoint->failure_count,
            'last_success_at' => $endpoint->last_success_at?->toIso8601String(),
            'last_failure_at' => $endpoint->last_failure_at?->toIso8601String(),
            'deliveries_count' => $endpoint->deliveries_count ?? null,
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }

    private function deliveryRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => (string) $delivery->id,
            'event' => $delivery->event,
            'endpoint' => $delivery->endpoint?->name,
            'url' => $delivery->endpoint?->url,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'response_body' => $delivery->response_body,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }
}

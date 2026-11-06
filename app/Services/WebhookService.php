<?php

namespace App\Services;

use App\Models\Business;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class WebhookService
{
    /** Send an event to every active endpoint subscribed to it. */
    public function dispatch(Business|int $business, string $event, array $data): array
    {
        $businessId = $business instanceof Business ? $business->id : $business;

        $endpoints = WebhookEndpoint::where('business_id', $businessId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribesTo($event));

        $deliveries = [];
        foreach ($endpoints as $endpoint) {
            $envelope = [
                'id' => (string) Str::uuid(),
                'event' => $event,
                'business_id' => (string) $businessId,
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ];

            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'business_id' => $businessId,
                'event' => $event,
                'payload' => $envelope,
                'status' => 'pending',
            ]);

            $deliveries[] = $this->attempt($delivery);
        }

        return $deliveries;
    }

    /** Deliver one queued payload and record the outcome. */
    public function attempt(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = $delivery->endpoint;
        if (! $endpoint) {
            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'response_body' => 'Endpoint removed']);

            return $delivery;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $timestamp = (string) now()->timestamp;
        $signature = $this->signature($endpoint->secret, $timestamp, $body);

        $delivery->increment('attempts');

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Velora-Event' => $delivery->event,
                    'X-Velora-Delivery' => (string) $delivery->id,
                    'X-Velora-Signature' => 't='.$timestamp.',v1='.$signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $ok = $response->successful();

            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => Str::limit((string) $response->body(), 2000),
                'status' => $ok ? 'delivered' : 'failed',
                'delivered_at' => $ok ? now() : null,
                'next_attempt_at' => $ok ? null : $this->nextAttemptAt($delivery->attempts),
            ]);

            $ok
                ? $endpoint->update(['failure_count' => 0, 'last_success_at' => now()])
                : $endpoint->update(['failure_count' => $endpoint->failure_count + 1, 'last_failure_at' => now()]);
        } catch (Throwable $e) {
            $delivery->update([
                'response_status' => null,
                'response_body' => Str::limit($e->getMessage(), 2000),
                'status' => 'failed',
                'next_attempt_at' => $this->nextAttemptAt($delivery->attempts),
            ]);
            $endpoint->update(['failure_count' => $endpoint->failure_count + 1, 'last_failure_at' => now()]);
        }

        return $delivery->fresh();
    }

    public function retry(WebhookDelivery $delivery): WebhookDelivery
    {
        $delivery->update(['status' => 'pending', 'next_attempt_at' => null]);

        return $this->attempt($delivery);
    }

    /** A synthetic event so a customer can verify their endpoint and signature. */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $envelope = [
            'id' => (string) Str::uuid(),
            'event' => 'webhook.test',
            'business_id' => (string) $endpoint->business_id,
            'occurred_at' => now()->toIso8601String(),
            'data' => ['message' => 'This is a test delivery from Velora.'],
        ];

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'business_id' => $endpoint->business_id,
            'event' => 'webhook.test',
            'payload' => $envelope,
            'status' => 'pending',
        ]);

        return $this->attempt($delivery);
    }

    public function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Exponential-ish backoff: 1, 5, 15, then hourly. */
    private function nextAttemptAt(int $attempts): Carbon
    {
        $minutes = match (true) {
            $attempts <= 1 => 1,
            $attempts === 2 => 5,
            $attempts === 3 => 15,
            default => 60,
        };

        return now()->addMinutes($minutes);
    }
}

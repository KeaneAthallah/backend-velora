<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PaymentWebhookEvent;
use App\Models\PlatformSetting;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

class PlatformService
{
    /** Headline numbers for the platform dashboard. */
    public function overview(): array
    {
        $now = now();
        $activeSubscriptions = Subscription::with('plan')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->get();

        $mrr = (int) $activeSubscriptions->sum(function (Subscription $subscription) {
            if (! $subscription->plan || $subscription->plan->isTrialPlan()) {
                return 0;
            }

            return $subscription->billing_period === 'yearly'
                ? (int) round($subscription->price_amount / 12)
                : (int) $subscription->price_amount;
        });

        $counts = [];
        foreach (Subscription::STATUSES ?? [] as $status) {
            $counts[$status] = Subscription::where('status', $status)->count();
        }
        $counts['none'] = max(0, Business::count() - Subscription::count());

        $salesThisMonth = Sale::where('status', 'completed')
            ->whereBetween('completed_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

        return [
            'businesses' => [
                'total' => Business::count(),
                'active' => Business::where('status', 'active')->count(),
                'new_this_month' => Business::where('created_at', '>=', $now->copy()->startOfMonth())->count(),
                'by_subscription' => $counts,
            ],
            'users' => [
                'total' => User::count(),
                'new_this_month' => User::where('created_at', '>=', $now->copy()->startOfMonth())->count(),
                'platform_admins' => User::where('is_platform_admin', true)->count(),
            ],
            'commerce' => [
                'sales_this_month' => (clone $salesThisMonth)->count(),
                'revenue_this_month' => (string) (clone $salesThisMonth)->sum('total'),
                'invoices_this_month' => SubscriptionInvoice::where('status', SubscriptionInvoice::STATUS_PAID)
                    ->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
                    ->count(),
                'mrr' => $mrr,
                'currency' => config('subscriptions.currency', 'IDR'),
            ],
            'health' => [
                'open_tickets' => SupportTicket::whereIn('status', ['open', 'pending'])->count(),
                'failed_webhooks_24h' => WebhookDelivery::where('status', 'failed')
                    ->where('updated_at', '>=', $now->copy()->subDay())->count(),
                'failed_payment_webhooks_24h' => PaymentWebhookEvent::where('status', 'failed')
                    ->where('updated_at', '>=', $now->copy()->subDay())->count(),
                'past_due_subscriptions' => Subscription::where('status', Subscription::STATUS_PAST_DUE)->count(),
            ],
            'recent_signups' => Business::with('subscription.plan')
                ->orderByDesc('id')
                ->limit(8)
                ->get()
                ->map(fn (Business $business) => [
                    'id' => (string) $business->id,
                    'name' => $business->name,
                    'industry' => $business->industry,
                    'created_at' => $business->created_at?->toIso8601String(),
                    'plan' => $business->subscription?->plan?->name,
                    'subscription_status' => $business->subscription?->effectiveStatus(),
                ]),
        ];
    }

    /** Tenant directory with the counts an administrator needs at a glance. */
    public function tenants(array $filters = [], int $perPage = 20)
    {
        $query = Business::query()
            ->with(['subscription.plan'])
            ->withCount(['outlets', 'memberships'])
            ->withCount(['memberships as active_memberships_count' => fn ($builder) => $builder->where('status', 'active')])
            ->withCount(['subscriptionInvoices as paid_invoices_count' => fn ($builder) => $builder
                ->where('status', SubscriptionInvoice::STATUS_PAID)]);

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhere('legal_name', 'like', $term));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['industry'])) {
            $query->where('industry', $filters['industry']);
        }
        if (! empty($filters['plan'])) {
            $query->whereHas('subscription.plan', fn ($builder) => $builder->where('slug', $filters['plan']));
        }
        if (! empty($filters['subscription_status'])) {
            $query->whereHas('subscription', fn ($builder) => $builder->where('status', $filters['subscription_status']));
        }

        return $query->orderByDesc('id')->paginate($perPage)->through(fn (Business $business) => $this->tenantRow($business));
    }

    public function tenant(Business $business): array
    {
        $business->load(['subscription.plan']);
        $sales = Sale::where('business_id', $business->id)->where('status', 'completed');

        return [
            'tenant' => $this->tenantRow($business, true),
            'subscription' => $business->subscription ? [
                'status' => $business->subscription->effectiveStatus(),
                'plan' => $business->subscription->plan?->name,
                'plan_slug' => $business->subscription->plan?->slug,
                'billing_period' => $business->subscription->billing_period,
                'trial_ends_at' => $business->subscription->trial_ends_at?->toIso8601String(),
                'ends_at' => $business->subscription->ends_at?->toIso8601String(),
                'days_remaining' => $business->subscription->daysRemaining(),
            ] : null,
            'sales' => [
                'count' => (clone $sales)->count(),
                'revenue' => (string) (clone $sales)->sum('total'),
                'last_sale_at' => (clone $sales)->max('completed_at'),
            ],
            'outlets' => $business->outlets()->orderBy('name')->get(['id', 'name', 'code', 'is_active'])
                ->map(fn ($outlet) => [
                    'id' => (string) $outlet->id,
                    'name' => $outlet->name,
                    'code' => $outlet->code,
                    'is_active' => (bool) $outlet->is_active,
                ]),
            'tickets' => SupportTicket::where('business_id', $business->id)
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn (SupportTicket $ticket) => $this->ticketRow($ticket)),
            'recent_audit' => AuditLog::where('business_id', $business->id)
                ->orderByDesc('id')->limit(15)->get(['id', 'action', 'actor_id', 'created_at']),
        ];
    }

    /** Operational signals: failures, overdue subscriptions and hot audit actions. */
    public function monitoring(): array
    {
        return [
            'failed_webhook_deliveries' => WebhookDelivery::with('endpoint:id,name,url')
                ->where('status', 'failed')
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn (WebhookDelivery $delivery) => [
                    'id' => (string) $delivery->id,
                    'event' => $delivery->event,
                    'endpoint' => $delivery->endpoint?->name,
                    'url' => $delivery->endpoint?->url,
                    'attempts' => $delivery->attempts,
                    'response_status' => $delivery->response_status,
                    'updated_at' => $delivery->updated_at?->toIso8601String(),
                ]),
            'failed_payment_webhooks' => PaymentWebhookEvent::where('status', 'failed')
                ->orderByDesc('id')->limit(10)->get(['id', 'provider', 'event_id', 'error', 'updated_at']),
            'past_due' => Subscription::with(['business:id,name', 'plan:id,name'])
                ->where('status', Subscription::STATUS_PAST_DUE)
                ->orderBy('id')->limit(20)->get()
                ->map(fn (Subscription $subscription) => [
                    'business' => $subscription->business?->name,
                    'plan' => $subscription->plan?->name,
                    'ends_at' => $subscription->ends_at?->toIso8601String(),
                ]),
            'audit_activity_24h' => AuditLog::where('created_at', '>=', now()->subDay())
                ->select('action', DB::raw('count(*) as total'))
                ->groupBy('action')
                ->orderByDesc('total')
                ->limit(15)
                ->get(),
            'counts' => [
                'businesses' => Business::count(),
                'users' => User::count(),
                'outlets' => DB::table('outlets')->count(),
                'sales' => Sale::count(),
                'documents' => DB::table('documents')->count(),
                'webhook_deliveries' => WebhookDelivery::count(),
            ],
        ];
    }

    public function settings(): array
    {
        $defaults = config('platform.defaults', []);
        $stored = PlatformSetting::orderBy('group')->orderBy('key')->get();

        return [
            'settings' => $stored->map(fn (PlatformSetting $setting) => [
                'key' => $setting->key,
                'group' => $setting->group,
                'value' => $setting->value,
                'description' => $setting->description,
                'updated_at' => $setting->updated_at?->toIso8601String(),
            ]),
            'defaults' => $defaults,
        ];
    }

    public function updateSettings(array $settings, ?int $userId): array
    {
        $saved = [];
        foreach ($settings as $key => $value) {
            $defaults = config('platform.defaults', []);
            $group = str_contains($key, '.') ? str($key)->before('.')->toString() : 'general';
            $description = $defaults[$key]['description'] ?? null;
            $saved[] = PlatformSetting::set($key, $value, $userId, $group);
            if ($description) {
                $saved[count($saved) - 1]->update(['description' => $description]);
            }
        }

        return array_map(fn (PlatformSetting $setting) => [
            'key' => $setting->key,
            'group' => $setting->group,
            'value' => $setting->value,
        ], $saved);
    }

    public function ticketRow(SupportTicket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'business_id' => $ticket->business_id ? (string) $ticket->business_id : null,
            'business' => $ticket->relationLoaded('business') ? $ticket->business?->name : null,
            'created_by' => $ticket->creator?->name,
            'assigned_to' => $ticket->assignee?->name,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    private function tenantRow(Business $business, bool $detailed = false): array
    {
        $row = [
            'id' => (string) $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'industry' => $business->industry,
            'status' => $business->status,
            'currency' => $business->currency,
            'created_at' => $business->created_at?->toIso8601String(),
            'outlets' => $business->outlets_count ?? null,
            'members' => $business->memberships_count ?? null,
            'active_members' => $business->active_memberships_count ?? null,
            'paid_invoices' => $business->paid_invoices_count ?? null,
            'subscription' => $business->subscription ? [
                'status' => $business->subscription->effectiveStatus(),
                'plan' => $business->subscription->plan?->name,
                'plan_slug' => $business->subscription->plan?->slug,
                'billing_period' => $business->subscription->billing_period,
                'days_remaining' => $business->subscription->daysRemaining(),
            ] : null,
        ];

        if ($detailed) {
            $row['settings'] = $business->settings;
            $row['tax_id'] = $business->tax_id;
            $row['legal_name'] = $business->legal_name;
            $row['timezone'] = $business->timezone;
        }

        return $row;
    }
}

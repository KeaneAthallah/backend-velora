<?php

namespace App\Services;

use App\Exceptions\IndustryChangeBlockedException;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Industry;
use App\Models\InventoryMovement;
use App\Models\Plan;
use App\Models\PlanIndustryFeature;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Subscription\PlanService;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Support\Collection;

class IndustryService
{
    private bool $seeded = false;

    public function __construct(
        protected PlanService $plans,
    ) {}

    public function ensureSeeded(): void
    {
        if ($this->seeded) {
            return;
        }

        $this->plans->ensureSeeded();

        $config = config('industries.industries', []);

        foreach ($config as $slug => $definition) {
            Industry::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'short_name' => $definition['short_name'] ?? $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 0,
                    'is_active' => $definition['is_active'] ?? true,
                    'capabilities' => $definition['modules'] ?? [],
                    'terminology' => $definition['terminology'] ?? [],
                    'metadata' => [
                        'examples' => $definition['examples'] ?? [],
                        'benefits' => $definition['benefits'] ?? [],
                        'icon' => $definition['icon'] ?? null,
                        'default_route' => $this->defaultRouteFor($slug),
                    ],
                ]
            );
        }

        // Seed per-industry feature overrides from subscription config.
        $planConfigs = config('subscriptions.plans', []);
        foreach ($planConfigs as $slug => $definition) {
            $plan = Plan::where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }

            $industryFeatures = $definition['industry_features'] ?? [];
            foreach ($industryFeatures as $industry => $features) {
                foreach ($features as $key => $enabled) {
                    PlanIndustryFeature::firstOrCreate(
                        ['plan_id' => $plan->id, 'industry' => $industry, 'feature_key' => $key],
                        ['enabled' => (bool) $enabled]
                    );
                }
            }
        }

        $this->seeded = true;
    }

    protected function defaultRouteFor(string $slug): string
    {
        return match ($slug) {
            'retail' => '/sales',
            'barber_salon' => '/',
            'distributor' => '/sales',
            default => '/',
        };
    }

    /** @return Collection<int, Industry> */
    public function all(): Collection
    {
        $this->ensureSeeded();

        return Industry::where('is_active', true)->orderBy('sort_order')->get();
    }

    public function find(string $slug): ?Industry
    {
        $this->ensureSeeded();

        return Industry::where('slug', $slug)->first();
    }

    public function defaultIndustry(): string
    {
        return (string) config('industries.default', 'food_beverage');
    }

    public function resolve(Business|int $business): array
    {
        $model = $business instanceof Business ? $business : Business::findOrFail($business);
        $this->ensureSeeded();

        $industry = $model->industry ?: $this->defaultIndustry();
        $industryModel = $this->find($industry);

        $subscriptions = app(SubscriptionService::class);
        $subscription = $subscriptions->forBusiness($model);
        $subscriptions->refreshStatus($subscription);
        $plan = $subscription->plan;

        $modules = $industryModel?->capabilities ?? [];

        // Resolve features scoped to industry.
        $features = [];
        $keys = array_merge(
            array_keys(config('subscriptions.feature_catalog', [])),
            array_keys(config('industries.feature_keys', []))
        );
        foreach (array_unique($keys) as $key) {
            $features[$key] = $plan ? (bool) $plan->featureEnabledFor($key, $industry) : false;
        }
        if ($plan && is_array($plan->features)) {
            foreach ($plan->features as $key => $enabled) {
                if (! array_key_exists($key, $features)) {
                    $features[$key] = (bool) $enabled;
                }
            }
        }

        return [
            'industry' => $industry,
            'industry_label' => $industryModel?->name,
            'modules' => $modules,
            'features' => $features,
            'limits' => $plan?->limits ?? [],
            'terminology' => $industryModel?->terminology ?? [],
            'default_route' => $industryModel?->metadata['default_route'] ?? '/',
        ];
    }

    public function modulesFor(Business|int $business): array
    {
        return $this->resolve($business)['modules'];
    }

    /**
     * @throws \RuntimeException
     */
    public function assertChangeAllowed(Business|int $business, string $newIndustry, bool $force = false, ?User $actor = null): void
    {
        $model = $business instanceof Business ? $business : Business::findOrFail($business);
        $this->ensureSeeded();

        $target = $this->find($newIndustry);
        if (! $target || ! $target->is_active) {
            throw new \InvalidArgumentException('Unknown or inactive industry.');
        }

        if ($model->industry === $newIndustry) {
            return;
        }

        $counts = $this->blockingCounts($model);

        $blocked = array_filter($counts, fn ($c) => $c > 0);
        if (! empty($blocked) && ! $force) {
            $e = new \RuntimeException('INDUSTRY_CHANGE_BLOCKED');
            // Attach counts for controller.
            throw new IndustryChangeBlockedException($counts);
        }

        if ($force && ! empty($blocked)) {
            AuditLog::create([
                'business_id' => $model->id,
                'outlet_id' => null,
                'actor_id' => $actor?->id,
                'action' => 'business.industry_changed_forced',
                'resource_type' => Business::class,
                'resource_id' => (string) $model->id,
                'before' => ['industry' => $model->industry],
                'after' => ['industry' => $newIndustry],
                'ip_address' => null,
                'user_agent' => null,
                'created_at' => now(),
            ]);
        }
    }

    public function blockingCounts(Business $business): array
    {
        return [
            'completed_sales' => Sale::where('business_id', $business->id)->where('status', 'completed')->count(),
            'products' => Product::where('business_id', $business->id)->count(),
            'inventory_movements' => InventoryMovement::where('business_id', $business->id)->count(),
            'purchase_orders' => PurchaseOrder::where('business_id', $business->id)->count(),
            'paid_invoices' => SubscriptionInvoice::where('business_id', $business->id)->where('status', 'paid')->count(),
        ];
    }
}

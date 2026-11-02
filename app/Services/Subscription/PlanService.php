<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Support\Collection;

class PlanService
{
    private bool $seeded = false;

    /**
     * Create any plan or feature row that is missing from the database.
     *
     * Existing rows are never overwritten: once a platform administrator has
     * edited a price or a limit in the database, that value is authoritative.
     * Use `subscriptions:sync-plans --force` to deliberately reset from config.
     */
    public function ensureSeeded(): void
    {
        if ($this->seeded) {
            return;
        }

        $config = config('subscriptions.plans', []);

        foreach ($config as $slug => $definition) {
            $plan = Plan::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 0,
                    'is_public' => $definition['is_public'] ?? true,
                    'is_active' => true,
                    'trial_days' => $definition['trial_days'] ?? 0,
                    'currency' => config('subscriptions.currency', 'IDR'),
                    'monthly_price' => (int) ($definition['prices']['monthly'] ?? 0),
                    'yearly_price' => (int) ($definition['prices']['yearly'] ?? 0),
                    'limits' => $definition['limits'] ?? [],
                    'features' => $definition['features'] ?? [],
                ]
            );

            $this->syncFeatureRows($plan, $definition['features'] ?? []);
        }

        $this->seeded = true;
    }

    /**
     * Reset every plan to the config defaults. Intended for deployment and
     * the `subscriptions:sync-plans --force` command, never for a request.
     */
    public function syncFromConfig(): void
    {
        $config = config('subscriptions.plans', []);

        foreach ($config as $slug => $definition) {
            $plan = Plan::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 0,
                    'is_public' => $definition['is_public'] ?? true,
                    'is_active' => true,
                    'trial_days' => $definition['trial_days'] ?? 0,
                    'currency' => config('subscriptions.currency', 'IDR'),
                    'monthly_price' => (int) ($definition['prices']['monthly'] ?? 0),
                    'yearly_price' => (int) ($definition['prices']['yearly'] ?? 0),
                    'limits' => $definition['limits'] ?? [],
                    'features' => $definition['features'] ?? [],
                ]
            );

            $this->syncFeatureRows($plan, $definition['features'] ?? []);
        }

        $this->seeded = true;
    }

    private function syncFeatureRows(Plan $plan, array $features): void
    {
        $catalog = config('subscriptions.feature_catalog', []);

        foreach ($features as $key => $enabled) {
            PlanFeature::firstOrCreate(
                ['plan_id' => $plan->id, 'feature_key' => $key],
                [
                    'enabled' => (bool) $enabled,
                    'label' => $catalog[$key]['label'] ?? $key,
                    'description' => $catalog[$key]['description'] ?? null,
                ]
            );
        }
    }

    public function findBySlug(string $slug): ?Plan
    {
        $this->ensureSeeded();

        return Plan::where('slug', $slug)->where('is_active', true)->first();
    }

    public function trialPlan(): ?Plan
    {
        return $this->findBySlug('trial') ?? Plan::where('trial_days', '>', 0)->where('is_active', true)->first();
    }

    /** @return Collection<int, Plan> */
    public function publicPlans(): Collection
    {
        $this->ensureSeeded();

        return Plan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Plan> */
    public function allPlans(): Collection
    {
        $this->ensureSeeded();

        return Plan::orderBy('sort_order')->get();
    }

    /**
     * Pricing page payload: plans plus a feature-by-feature comparison grouped
     * by the catalogue's group label.
     */
    public function pricingPayload(): array
    {
        $plans = $this->publicPlans();
        $catalog = config('subscriptions.feature_catalog', []);
        $groups = [];

        foreach ($catalog as $key => $meta) {
            $groups[$meta['group']][] = [
                'key' => $key,
                'label' => $meta['label'],
                'plans' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->slug => $plan->featureEnabled($key)])->all(),
            ];
        }

        return [
            'currency' => config('subscriptions.currency', 'IDR'),
            'trial_days' => (int) config('subscriptions.trial_days', 30),
            'plans' => $plans->map(fn (Plan $plan) => $plan->toApiArray())->values()->all(),
            'comparison' => collect($groups)
                ->map(fn (array $features, string $group) => ['group' => $group, 'features' => $features])
                ->values()
                ->all(),
            'limits' => $this->limitRows($plans),
        ];
    }

    private function limitRows(Collection $plans): array
    {
        $rows = [];

        foreach (config('subscriptions.usage_metrics', []) as $metric => $meta) {
            $rows[] = [
                'metric' => $metric,
                'label' => $meta['label'],
                'plans' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->slug => $plan->limit($metric)])->all(),
            ];
        }

        return $rows;
    }
}

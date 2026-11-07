<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\IngredientBalance;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\UserNotification;
use App\Services\AuthorizationService;
use App\Services\RecipeService;
use Illuminate\Console\Command;

/**
 * Turn `reorder_level` into an inbox alert that names the damage.
 *
 * For every outlet, an ingredient at or below its reorder level produces one
 * notification per member who can see inventory, listing the menu items that
 * ingredient limits and how many of each can still be made. Re-running is safe:
 * a member is not re-alerted about the same ingredient and outlet within a day.
 */
final class ScanLowStock extends Command
{
    protected $signature = 'inventory:scan-low-stock {--business= : Only scan one business id}';

    protected $description = 'Notify inventory staff about ingredients at or below their reorder level';

    public function handle(RecipeService $recipes, AuthorizationService $authz): int
    {
        $businessIds = Business::query()
            ->when($this->option('business'), fn ($query, $id) => $query->whereKey((int) $id))
            ->pluck('id');

        $alerts = 0;

        foreach ($businessIds as $businessId) {
            $members = BusinessMembership::query()
                ->with('user')
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->get()
                ->filter(fn ($membership) => $membership->user
                    && $authz->hasPermission($membership->user, 'inventory.view', (int) $businessId))
                ->pluck('user');

            if ($members->isEmpty()) {
                continue;
            }

            foreach (Outlet::where('business_id', $businessId)->get() as $outlet) {
                $alerts += $this->alertOutlet($recipes, (int) $businessId, $outlet, $members);
            }
        }

        $this->info("Low-stock alerts sent: {$alerts}");

        return self::SUCCESS;
    }

    private function alertOutlet(RecipeService $recipes, int $businessId, Outlet $outlet, $members): int
    {
        $balances = IngredientBalance::query()
            ->with('ingredient')
            ->where('business_id', $businessId)
            ->where('outlet_id', $outlet->id)
            ->get();

        $sent = 0;

        foreach ($balances as $balance) {
            $ingredient = $balance->ingredient;
            if (! $ingredient || ! $ingredient->track_inventory) {
                continue;
            }

            $reorderLevel = (string) $ingredient->reorder_level;
            if (bccomp($reorderLevel, '0', 4) <= 0) {
                continue;
            }
            if (bccomp((string) $balance->quantity, $reorderLevel, 4) > 0) {
                continue;
            }

            $products = Product::query()
                ->where('business_id', $businessId)
                ->whereIn('id', RecipeItem::query()
                    ->where('business_id', $businessId)
                    ->where('ingredient_id', $ingredient->id)
                    ->pluck('product_id'))
                ->orderBy('name')
                ->get();

            if ($products->isEmpty()) {
                continue;
            }

            $limited = $products
                ->map(function (Product $product) use ($recipes, $outlet) {
                    $availability = $recipes->availabilityFor($product, (int) $outlet->id);

                    return [
                        'product_id' => (string) $product->id,
                        'product_name' => $product->name,
                        'producible' => $availability['unlimited'] ? null : (int) ($availability['sellable'] ?? 0),
                    ];
                })
                ->values();

            $body = sprintf(
                '%s is at %s %s (reorder at %s %s). %s',
                $ingredient->name,
                rtrim(rtrim((string) $balance->quantity, '0'), '.') ?: '0',
                $ingredient->unit,
                rtrim(rtrim($reorderLevel, '0'), '.') ?: '0',
                $ingredient->unit,
                $limited
                    ->map(fn (array $row) => $row['producible'] === null
                        ? $row['product_name']
                        : "{$row['product_name']} can make {$row['producible']} more")
                    ->join('; '),
            );

            foreach ($members as $user) {
                if ($this->alreadyNotified($user->id, $ingredient->id, $outlet->id)) {
                    continue;
                }

                UserNotification::create([
                    'business_id' => $businessId,
                    'user_id' => $user->id,
                    'type' => 'inventory.low_stock',
                    'title' => 'Low stock: '.$ingredient->name,
                    'body' => $body,
                    'data' => [
                        'ingredient_id' => (string) $ingredient->id,
                        'outlet_id' => (string) $outlet->id,
                        'remaining' => (string) $balance->quantity,
                        'reorder_level' => $reorderLevel,
                        'products' => $limited->all(),
                    ],
                ]);

                $sent++;
            }
        }

        return $sent;
    }

    private function alreadyNotified(int $userId, int $ingredientId, int $outletId): bool
    {
        // JSON columns do not filter the same way on MySQL and SQLite, so the
        // last day's alerts are read back and matched in PHP.
        return UserNotification::query()
            ->where('user_id', $userId)
            ->where('type', 'inventory.low_stock')
            ->where('created_at', '>=', now()->subDay())
            ->get(['id', 'data'])
            ->contains(fn (UserNotification $notification) => (string) ($notification->data['ingredient_id'] ?? '') === (string) $ingredientId
                && (string) ($notification->data['outlet_id'] ?? '') === (string) $outletId);
    }
}

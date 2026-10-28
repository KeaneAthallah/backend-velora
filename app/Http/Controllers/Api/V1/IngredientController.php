<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBalance;
use App\Models\IngredientMovement;
use App\Models\Outlet;
use App\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngredientController extends Controller
{
    public function __construct(protected AuthorizationService $authz) {}

    public function index(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ingredients.view', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if ($outletId && ! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $query = Ingredient::where('business_id', $businessId);

        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(function ($sub) use ($term) {
                $sub->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            });
        }
        if ($request->filled('active')) {
            $query->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        $ingredients = $query->orderBy('name')->get();

        $balances = $outletId
            ? IngredientBalance::where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->pluck('quantity', 'ingredient_id')
            : collect();

        return $this->success($ingredients->map(function (Ingredient $ingredient) use ($balances) {
            $quantity = (string) ($balances[$ingredient->id] ?? '0');
            $value = bcmul($quantity, (string) $ingredient->cost_per_unit, 4);

            return array_merge($ingredient->toArray(), [
                'quantity' => $quantity,
                'stock_value' => $value,
                'low_stock' => bccomp($quantity, (string) $ingredient->reorder_level, 4) <= 0,
            ]);
        })->values());
    }

    public function store(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ingredients.manage', $businessId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:128',
            'unit' => 'nullable|string|max:32',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'track_inventory' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'opening_quantity' => 'nullable|numeric',
            'outlet_id' => 'nullable|integer',
        ]);

        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if ($outletId && ! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $ingredient = DB::transaction(function () use ($businessId, $data, $outletId, $request) {
            $ingredient = Ingredient::create([
                'business_id' => $businessId,
                'name' => $data['name'],
                'sku' => $data['sku'] ?? null,
                'unit' => $data['unit'] ?? 'g',
                'cost_per_unit' => $data['cost_per_unit'] ?? 0,
                'reorder_level' => $data['reorder_level'] ?? 0,
                'track_inventory' => $data['track_inventory'] ?? true,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $opening = (string) ($data['opening_quantity'] ?? '0');
            if ($outletId && bccomp($opening, '0', 4) !== 0) {
                $balance = IngredientBalance::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $opening,
                ]);

                IngredientMovement::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'ingredient_id' => $ingredient->id,
                    'type' => 'adjustment',
                    'quantity' => $opening,
                    'unit_cost' => $ingredient->cost_per_unit,
                    'balance_after' => $balance->quantity,
                    'reason' => 'Opening stock',
                    'actor_id' => $request->user()->id,
                ]);
            }

            return $ingredient;
        });

        return $this->success($ingredient, 'Ingredient created', 201);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $businessId = $request->attributes->get('business_id');
        if ($ingredient->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ingredients.manage', $businessId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'sku' => 'sometimes|nullable|string|max:128',
            'unit' => 'sometimes|string|max:32',
            'cost_per_unit' => 'sometimes|numeric|min:0',
            'reorder_level' => 'sometimes|numeric|min:0',
            'track_inventory' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $ingredient->update($data);

        return $this->success($ingredient, 'Ingredient updated');
    }

    public function destroy(Request $request, Ingredient $ingredient)
    {
        $businessId = $request->attributes->get('business_id');
        if ($ingredient->business_id !== $businessId) {
            return $this->error('Not found', 404);
        }
        $this->authz->requirePermission($request->user(), 'ingredients.manage', $businessId);

        if ($ingredient->recipeItems()->exists() || $ingredient->movements()->exists()) {
            return $this->error('Ingredient is used by recipes or stock history; deactivate it instead', 422);
        }

        $ingredient->balances()->delete();
        $ingredient->delete();

        return $this->success(null, 'Ingredient deleted');
    }

    public function adjust(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ingredients.manage', $businessId);

        $data = $request->validate([
            'ingredient_id' => 'required|integer',
            'outlet_id' => 'nullable|integer',
            'quantity' => 'required|numeric|not_in:0',
            'type' => 'nullable|in:adjustment,purchase,wastage,expired',
            'unit_cost' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:255',
        ]);

        $outletId = $request->attributes->get('outlet_id') ?? $data['outlet_id'] ?? null;
        if (! $outletId) {
            return $this->error('Outlet required', 422);
        }
        if (! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $ingredient = Ingredient::where('id', $data['ingredient_id'])->where('business_id', $businessId)->first();
        if (! $ingredient) {
            return $this->error('Not found', 404);
        }

        $type = $data['type'] ?? 'adjustment';
        $quantity = (string) $data['quantity'];

        try {
            DB::transaction(function () use ($businessId, $outletId, $ingredient, $type, $quantity, $data, $request) {
                $balance = IngredientBalance::lockForUpdate()->firstOrCreate(
                    ['outlet_id' => $outletId, 'ingredient_id' => $ingredient->id],
                    ['business_id' => $businessId, 'quantity' => 0],
                );

                $next = bcadd((string) $balance->quantity, $quantity, 4);
                if (bccomp($next, '0', 4) < 0) {
                    throw new \RuntimeException('Quantity cannot go below zero');
                }

                $balance->quantity = $next;
                $balance->save();

                if (isset($data['unit_cost'])) {
                    $ingredient->cost_per_unit = $data['unit_cost'];
                    $ingredient->save();
                }

                IngredientMovement::create([
                    'business_id' => $businessId,
                    'outlet_id' => $outletId,
                    'ingredient_id' => $ingredient->id,
                    'type' => $type,
                    'quantity' => $quantity,
                    'unit_cost' => $data['unit_cost'] ?? $ingredient->cost_per_unit,
                    'balance_after' => $balance->quantity,
                    'reason' => $data['reason'],
                    'actor_id' => $request->user()->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($ingredient->fresh(), 'Stock adjusted');
    }

    public function balances(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ingredients.view', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        if ($outletId && ! Outlet::where('id', $outletId)->where('business_id', $businessId)->exists()) {
            return $this->error('Not found', 404);
        }

        $query = IngredientBalance::where('business_id', $businessId)->with('ingredient', 'outlet');
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->input('ingredient_id'));
        }

        return $this->success($query->orderBy('ingredient_id')->paginate(100));
    }

    public function movements(Request $request)
    {
        $businessId = $request->attributes->get('business_id');
        $this->authz->requirePermission($request->user(), 'ingredients.view', $businessId);

        $outletId = $request->attributes->get('outlet_id') ?? $request->input('outlet_id');
        $query = IngredientMovement::where('business_id', $businessId)->with('ingredient', 'actor');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->input('ingredient_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return $this->success($query->orderByDesc('id')->paginate(100));
    }
}

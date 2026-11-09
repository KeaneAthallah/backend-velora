<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'sku', 'unit', 'cost_per_unit', 'reorder_level',
        'safety_stock', 'lead_time_days', 'shelf_life_days', 'preferred_supplier_id',
        'track_inventory', 'is_active',
    ];

    public const SHELF_LIFE_UNTRACKED = 0.0;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'safety_stock' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
            'shelf_life_days' => 'decimal:2',
            'lead_time_days' => 'integer',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stock at or under the safety floor. A zero floor is unset, so nothing is
     * critical until someone names the level the kitchen must never cross.
     */
    public function isCritical(string|float|int $available): bool
    {
        $floor = (string) $this->safety_stock;

        return bccomp($floor, '0', 4) > 0 && bccomp((string) $available, $floor, 4) <= 0;
    }

    /**
     * Stock on hand across every outlet.
     *
     * The reorder copilot forecasts per business, so an ingredient's stock is
     * the sum of its outlet balances. Eager load `balances` to avoid a query
     * per ingredient.
     */
    public function getCurrentStockAttribute(): float
    {
        if ($this->relationLoaded('balances')) {
            return (float) $this->balances->sum(fn ($balance) => (float) $balance->quantity);
        }

        return (float) $this->balances()->sum('quantity');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function balance()
    {
        return $this->hasOne(IngredientBalance::class);
    }

    public function balances()
    {
        return $this->hasMany(IngredientBalance::class);
    }

    public function movements()
    {
        return $this->hasMany(IngredientMovement::class);
    }

    public function recipes()
    {
        return $this->hasMany(RecipeIngredient::class, 'ingredient_id');
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class, 'ingredient_id');
    }

    public function usedInRecipes()
    {
        return $this->hasMany(RecipeItem::class, 'ingredient_id');
    }

    public function photoAndUsePatterns()
    {
        return $this->hasMany(IngredientPhotoAndUsePattern::class);
    }

    public static function boot(): void
    {
        parent::boot();

        static::deleting(function (Ingredient $ingredient) {
            if ($ingredient->recipeItems()->exists() || $ingredient->movements()->exists()) {
                throw new \RuntimeException('Ingredient is used by recipes or stock history; deactivate it instead');
            }
        });
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeTrackingInventory($query)
    {
        return $query->where('track_inventory', true);
    }

    public function currentStock(): float
    {
        if ($this->relationLoaded('balance') && $this->balance !== null) {
            return (float) $this->balance->quantity;
        }

        return (float) $this->balance()->value('quantity') ?? 0.0;
    }

    public function openingQuantity(): float
    {
        return (float) ($this->opening_quantity ?? $this->balance?->quantity ?? 0.0);
    }

    public function shelfLifeDays(): float
    {
        if ($this->shelf_life_days !== null && $this->shelf_life_days !== self::SHELF_LIFE_UNTRACKED) {
            return (float) $this->shelf_life_days;
        }

        return self::SHELF_LIFE_UNTRACKED;
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id')->withDefault();
    }

    public function consumesFromRecipe(int $productId, float $quantityPerUnit, float $yieldQuantity = 1.0): void
    {
        $this->movements()->create([
            'type' => 'consumption',
            'quantity' => $quantityPerUnit * $yieldQuantity,
            'unit_cost' => $this->cost_per_unit,
        ]);
    }

    public function offsetsByStockMoves(float $netChange): void
    {
        $this->movements()->create([
            'type' => 'adjustment',
            'quantity' => $netChange,
            'unit_cost' => $this->cost_per_unit,
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'sku', 'unit', 'cost_per_unit', 'reorder_level',
        'track_inventory', 'is_active',
    ];

    public const SHELF_LIFE_UNTRACKED = 0.0;

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
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

    public function preferredSupplier(): ?Supplier
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

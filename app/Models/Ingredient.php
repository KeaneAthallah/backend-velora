<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'sku', 'unit', 'cost_per_unit', 'reorder_level', 'track_inventory', 'is_active'];

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class);
    }

    public function balances()
    {
        return $this->hasMany(IngredientBalance::class);
    }

    public function movements()
    {
        return $this->hasMany(IngredientMovement::class);
    }
}

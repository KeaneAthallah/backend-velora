<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModifierOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'modifier_group_id', 'product_id', 'product_variant_id',
        'removes_ingredient_id', 'name', 'price_delta', 'is_default', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:4',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function group()
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The ingredient this option takes out of the recipe when selected
     * ("no milk" → milk). Only optional recipe lines are skipped.
     */
    public function removesIngredient()
    {
        return $this->belongsTo(Ingredient::class, 'removes_ingredient_id');
    }
}

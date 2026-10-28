<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['product_id', 'name', 'sku', 'barcode', 'cost_price', 'selling_price', 'attributes', 'is_active'];

    protected function casts(): array
    {
        return ['cost_price' => 'decimal:4', 'selling_price' => 'decimal:4', 'attributes' => 'array', 'is_active' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function recipeItems()
    {
        return $this->hasMany(RecipeItem::class);
    }
}

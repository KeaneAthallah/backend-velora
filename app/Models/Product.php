<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'category_id', 'name', 'sku', 'description', 'unit', 'cost_price', 'selling_price', 'track_inventory', 'is_active', 'attributes'];

    protected function casts(): array
    {
        return ['cost_price' => 'decimal:4', 'selling_price' => 'decimal:4', 'track_inventory' => 'boolean', 'is_active' => 'boolean', 'attributes' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function outletPrices()
    {
        return $this->hasMany(OutletProductPrice::class);
    }
}

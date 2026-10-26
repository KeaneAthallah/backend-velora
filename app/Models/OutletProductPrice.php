<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutletProductPrice extends Model
{
    use HasFactory;

    protected $fillable = ['outlet_id', 'product_id', 'product_variant_id', 'cost_price', 'selling_price', 'is_active'];

    protected function casts(): array
    {
        return ['cost_price' => 'decimal:4', 'selling_price' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

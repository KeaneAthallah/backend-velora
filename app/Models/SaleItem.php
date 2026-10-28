<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = ['sale_id', 'product_id', 'product_variant_id', 'product_name', 'sku', 'base_unit_price', 'unit_price', 'cost_price', 'quantity', 'discount_amount', 'tax_rate', 'tax_amount', 'line_total', 'notes', 'modifiers', 'option_ids'];

    protected function casts(): array
    {
        return ['base_unit_price' => 'decimal:4', 'unit_price' => 'decimal:4', 'cost_price' => 'decimal:4', 'quantity' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'modifiers' => 'array', 'option_ids' => 'array'];
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

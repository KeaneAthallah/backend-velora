<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StocktakeItem extends Model
{
    use HasFactory;

    protected $fillable = ['stocktake_id', 'product_id', 'product_variant_id', 'expected_quantity', 'counted_quantity'];

    protected function casts(): array
    {
        return ['expected_quantity' => 'decimal:4', 'counted_quantity' => 'decimal:4'];
    }

    public function stocktake()
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

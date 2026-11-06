<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorefrontItem extends Model
{
    use HasFactory;

    protected $fillable = ['storefront_id', 'product_id', 'is_visible', 'price_override', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'price_override' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    public function storefront()
    {
        return $this->belongsTo(Storefront::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

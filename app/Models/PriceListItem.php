<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    use HasFactory;

    protected $table = 'price_list_items';

    protected $fillable = ['price_list_id', 'product_id', 'product_variant_id', 'price', 'min_quantity'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'min_quantity' => 'integer'];
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

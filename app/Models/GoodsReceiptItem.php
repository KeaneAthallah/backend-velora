<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    use HasFactory;

    protected $fillable = ['goods_receipt_id', 'product_id', 'product_variant_id', 'quantity', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }

    public function receipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

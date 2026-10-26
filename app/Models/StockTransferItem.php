<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    use HasFactory;

    protected $fillable = ['stock_transfer_id', 'product_id', 'product_variant_id', 'quantity', 'received_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'received_quantity' => 'decimal:4'];
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_order_id', 'product_id', 'product_variant_id', 'quantity', 'received_quantity', 'unit_cost', 'tax_rate', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'received_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'tax_rate' => 'decimal:4', 'line_total' => 'decimal:4'];
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

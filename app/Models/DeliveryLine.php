<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryLine extends Model
{
    use HasFactory;

    protected $fillable = ['delivery_id', 'sale_item_id', 'description', 'quantity', 'delivered_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'delivered_quantity' => 'decimal:4'];
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }
}

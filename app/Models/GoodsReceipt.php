<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'purchase_order_id', 'supplier_id', 'receipt_number', 'status', 'notes', 'received_by', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}

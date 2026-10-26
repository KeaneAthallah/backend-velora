<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'supplier_id', 'po_number', 'status', 'subtotal', 'tax_amount', 'total', 'notes', 'created_by', 'approved_by', 'approved_at', 'expected_at'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total' => 'decimal:4', 'approved_at' => 'datetime', 'expected_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}

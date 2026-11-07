<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id', 'product_id', 'product_variant_id', 'quantity', 'received_quantity',
        'unit_cost', 'tax_rate', 'line_total',
        // Reorder-copilot columns (ingredient-based lines): written only by the
        // copilot, still null for lines drafted by the normal purchasing flow.
        'ingredient_id', 'unit', 'cost_per_unit', 'estimated_total', 'parcel_quantity', 'parcel_cost', 'supplier_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_total' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
            'estimated_total' => 'decimal:4',
            'parcel_quantity' => 'decimal:4',
            'parcel_cost' => 'decimal:4',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseOrderItemApproval::class);
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->whereHas('order', fn ($order) => $order->where('business_id', $businessId));
    }
}

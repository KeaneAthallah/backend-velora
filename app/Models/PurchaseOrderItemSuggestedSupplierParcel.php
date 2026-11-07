<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A supplier's parcel for a product or ingredient: the quantity it ships and
 * what that parcel costs. The reorder copilot prefers the parcel with the
 * lowest cost per unit.
 */
class PurchaseOrderItemSuggestedSupplierParcel extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'product_id', 'ingredient_id', 'supplier_id', 'parcel_quantity', 'parcel_cost',
    ];

    protected function casts(): array
    {
        return [
            'parcel_quantity' => 'decimal:4',
            'parcel_cost' => 'decimal:4',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
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

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}

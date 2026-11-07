<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A use pattern for an ingredient (how much is used per day when it applies)
 * and the supplier parcels that pattern suggests reordering.
 */
class IngredientPhotoAndUsePattern extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'ingredient_id', 'name', 'consumption_rate', 'photo_share_estimate'];

    protected function casts(): array
    {
        return [
            'consumption_rate' => 'decimal:4',
            'photo_share_estimate' => 'decimal:4',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Parcels this pattern suggests, matched on the ingredient.
     */
    public function suggestedParcels()
    {
        return $this->hasMany(PurchaseOrderItemSuggestedSupplierParcel::class, 'ingredient_id', 'ingredient_id');
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}

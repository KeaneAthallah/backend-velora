<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'product_id', 'product_variant_id', 'type', 'quantity', 'balance_after', 'reference_type', 'reference_id', 'reason', 'actor_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'balance_after' => 'decimal:4'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

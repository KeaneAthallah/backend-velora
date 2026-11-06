<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'storefront_id', 'customer_account_id', 'token', 'status', 'metadata', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'expires_at' => 'datetime'];
    }

    public function storefront()
    {
        return $this->belongsTo(Storefront::class);
    }

    public function customerAccount()
    {
        return $this->belongsTo(StorefrontCustomerAccount::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }
}

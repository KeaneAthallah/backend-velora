<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class StorefrontCustomerAccount extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'business_id', 'storefront_id', 'customer_id', 'name', 'email', 'phone',
        'password_hash', 'status', 'last_login_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function storefront()
    {
        return $this->belongsTo(Storefront::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class, 'customer_account_id');
    }

    public function orders()
    {
        return $this->hasMany(StorefrontOrder::class, 'customer_account_id');
    }
}

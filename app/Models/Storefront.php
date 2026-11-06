<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Storefront extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'outlet_id', 'name', 'slug', 'domain', 'currency', 'description',
        'theme', 'settings', 'is_active', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(StorefrontItem::class);
    }

    public function orders()
    {
        return $this->hasMany(StorefrontOrder::class);
    }

    public function customerAccounts()
    {
        return $this->hasMany(StorefrontCustomerAccount::class);
    }
}

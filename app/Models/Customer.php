<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id', 'name', 'phone', 'email', 'address', 'loyalty_number', 'loyalty_points',
        'total_spent', 'visits_count', 'last_visit_at', 'loyalty_joined_at', 'metadata',
        'credit_limit', 'payment_terms_days', 'balance',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'decimal:4', 'total_spent' => 'decimal:4', 'visits_count' => 'integer',
            'last_visit_at' => 'datetime', 'loyalty_joined_at' => 'datetime', 'metadata' => 'array',
            'credit_limit' => 'integer', 'payment_terms_days' => 'integer', 'balance' => 'integer',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function groups()
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_customer_group');
    }

    public function tags()
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag');
    }

    public function notes()
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(CustomerActivityLog::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}

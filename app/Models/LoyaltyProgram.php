<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgram extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'is_active', 'points_per_amount', 'redeem_value', 'min_redeem_points'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'points_per_amount' => 'decimal:4',
            'redeem_value' => 'decimal:4',
            'min_redeem_points' => 'decimal:4',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

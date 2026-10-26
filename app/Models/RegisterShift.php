<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RegisterShift extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'cash_register_id', 'cashier_id', 'status', 'opening_cash', 'expected_cash', 'actual_cash', 'difference', 'opened_at', 'closed_at', 'closed_by', 'notes'];

    protected function casts(): array
    {
        return ['opening_cash' => 'decimal:4', 'expected_cash' => 'decimal:4', 'actual_cash' => 'decimal:4', 'difference' => 'decimal:4', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function register()
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class, 'register_shift_id');
    }
}

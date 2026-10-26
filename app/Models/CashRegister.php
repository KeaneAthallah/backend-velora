<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'name', 'code', 'status'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function shifts()
    {
        return $this->hasMany(RegisterShift::class, 'cash_register_id');
    }
}

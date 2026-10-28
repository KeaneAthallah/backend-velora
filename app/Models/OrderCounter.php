<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderCounter extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'business_date', 'order_type', 'last_number'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}

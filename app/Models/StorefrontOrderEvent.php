<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorefrontOrderEvent extends Model
{
    use HasFactory;

    protected $fillable = ['storefront_order_id', 'status', 'message', 'actor_type', 'actor_id'];

    public function order()
    {
        return $this->belongsTo(StorefrontOrder::class, 'storefront_order_id');
    }
}

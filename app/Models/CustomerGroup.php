<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use HasFactory;

    protected $table = 'customer_groups';

    protected $fillable = ['business_id', 'name', 'description'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_customer_group');
    }
}

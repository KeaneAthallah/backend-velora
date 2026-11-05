<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerActivityLog extends Model
{
    use HasFactory;

    protected $table = 'customer_activity_logs';

    protected $fillable = ['business_id', 'customer_id', 'action', 'metadata', 'created_at'];

    protected $timestamps = false;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'service_id', 'staff_id', 'customer_id', 'starts_at', 'ends_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}

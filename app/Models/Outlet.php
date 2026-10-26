<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Outlet extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'code', 'address', 'phone', 'email', 'timezone', 'status', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function memberships()
    {
        return $this->hasMany(OutletMembership::class);
    }
}

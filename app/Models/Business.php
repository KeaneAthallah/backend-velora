<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'legal_name', 'tax_id', 'currency', 'timezone', 'status', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function outlets()
    {
        return $this->hasMany(Outlet::class);
    }

    public function memberships()
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}

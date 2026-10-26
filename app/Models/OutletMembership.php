<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutletMembership extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'outlet_id', 'role_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}

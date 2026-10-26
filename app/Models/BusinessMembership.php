<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessMembership extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'business_id', 'role_id', 'status', 'invited_at', 'joined_at'];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'joined_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}

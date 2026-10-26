<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['business_id', 'outlet_id', 'actor_id', 'action', 'resource_type', 'resource_id', 'before', 'after', 'ip_address', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }
}

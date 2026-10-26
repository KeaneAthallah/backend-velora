<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'name', 'device_uuid', 'type', 'status', 'last_seen_at', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}

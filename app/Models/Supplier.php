<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_id', 'lead_time_days', 'is_active'];

    protected function casts(): array
    {
        return ['lead_time_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KitchenStation extends Model
{
    use HasFactory;

    public const KINDS = ['kitchen', 'bar', 'pastry', 'cold', 'expediter'];

    protected $fillable = ['business_id', 'outlet_id', 'name', 'code', 'kind', 'printer_name', 'printer_target', 'copies', 'auto_print', 'is_default', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'copies' => 'integer',
            'auto_print' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'kitchen_station_categories')->withTimestamps();
    }

    public function tickets()
    {
        return $this->hasMany(KitchenTicket::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

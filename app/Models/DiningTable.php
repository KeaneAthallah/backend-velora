<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    use HasFactory;

    public const STATUSES = ['available', 'reserved', 'cleaning'];

    public const SHAPES = ['round', 'square', 'rect'];

    protected $fillable = ['business_id', 'outlet_id', 'floor_area_id', 'name', 'seats', 'shape', 'position_x', 'position_y', 'status', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function area()
    {
        return $this->belongsTo(FloorArea::class, 'floor_area_id');
    }

    public function activeOrder()
    {
        return $this->hasOne(Sale::class, 'table_id')
            ->whereIn('order_status', Sale::ACTIVE_ORDER_STATUSES);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'table_id');
    }

    /** A table is busy while an open tab still points at it. */
    public function isOccupied(): bool
    {
        return $this->activeOrder()->exists();
    }
}

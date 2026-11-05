<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseZone extends Model
{
    use HasFactory;

    protected $table = 'warehouse_zones';

    protected $fillable = ['warehouse_id', 'name', 'code'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bins()
    {
        return $this->hasMany(WarehouseBin::class, 'warehouse_zone_id');
    }
}

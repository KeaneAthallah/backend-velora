<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseBin extends Model
{
    use HasFactory;

    protected $table = 'warehouse_bins';

    protected $fillable = ['warehouse_zone_id', 'code', 'label'];

    public function zone()
    {
        return $this->belongsTo(WarehouseZone::class, 'warehouse_zone_id');
    }
}

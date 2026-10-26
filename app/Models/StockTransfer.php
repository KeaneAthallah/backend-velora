<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'from_outlet_id', 'to_outlet_id', 'status', 'notes', 'created_by', 'approved_by', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function fromOutlet()
    {
        return $this->belongsTo(Outlet::class, 'from_outlet_id');
    }

    public function toOutlet()
    {
        return $this->belongsTo(Outlet::class, 'to_outlet_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }
}

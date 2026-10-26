<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stocktake extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'outlet_id', 'status', 'notes', 'created_by', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(StocktakeItem::class);
    }
}

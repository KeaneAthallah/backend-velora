<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'code', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }
}

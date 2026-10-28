<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Daypart extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'start_time', 'end_time', 'days', 'is_active'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_dayparts')->withTimestamps();
    }
}

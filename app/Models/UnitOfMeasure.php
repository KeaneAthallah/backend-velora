<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    use HasFactory;

    protected $table = 'units_of_measure';

    protected $fillable = ['business_id', 'code', 'name', 'base_factor'];

    protected function casts(): array
    {
        return ['base_factor' => 'string'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

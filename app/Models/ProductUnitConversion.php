<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductUnitConversion extends Model
{
    use HasFactory;

    protected $table = 'product_unit_conversions';

    protected $fillable = ['product_id', 'unit_id', 'factor'];

    protected function casts(): array
    {
        return ['factor' => 'string'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }
}

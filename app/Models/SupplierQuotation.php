<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierQuotation extends Model
{
    use HasFactory;

    protected $table = 'supplier_quotations';

    protected $fillable = ['business_id', 'supplier_id', 'product_id', 'unit_price', 'min_quantity', 'valid_until', 'notes'];

    protected function casts(): array
    {
        return ['unit_price' => 'integer', 'min_quantity' => 'integer', 'valid_until' => 'date'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

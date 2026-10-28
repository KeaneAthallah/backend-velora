<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuAvailability extends Model
{
    use HasFactory;

    protected $table = 'menu_availability';

    protected $fillable = [
        'business_id', 'outlet_id', 'product_id', 'product_variant_id',
        'modifier_option_id', 'is_available', 'reason',
    ];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function option()
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }
}

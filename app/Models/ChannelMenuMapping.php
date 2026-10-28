<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelMenuMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'sales_channel_id', 'external_item_id', 'external_variant_id',
        'product_id', 'product_variant_id', 'option_map', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'option_map' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function channel()
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

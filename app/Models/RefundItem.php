<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One returned sale line on a refund (see the `refund_items` migration).
 */
class RefundItem extends Model
{
    use HasFactory;

    protected $fillable = ['refund_id', 'sale_item_id', 'quantity', 'reason'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One approve/reject decision on a suggested purchase-order line.
 *
 * A rejection is recorded as an approval row with `quantity` 0 and
 * `rejected_at` set, so the trail stays append-only.
 */
class PurchaseOrderItemApproval extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_order_item_id', 'approved_by', 'quantity', 'cost_per_unit', 'notes', 'rejected_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
            'rejected_at' => 'datetime',
        ];
    }

    public function item()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

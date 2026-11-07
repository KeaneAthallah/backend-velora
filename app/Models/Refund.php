<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'sale_id', 'payment_id', 'amount', 'reason', 'status', 'requested_by', 'approved_by', 'approved_at', 'completed_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'approved_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The sale lines this refund returns to stock.
     *
     * Empty for amount-only refunds, which deliberately leave ingredients
     * deducted because the amount cannot be mapped to quantities.
     */
    public function items()
    {
        return $this->hasMany(RefundItem::class);
    }
}

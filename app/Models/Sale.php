<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['business_id', 'outlet_id', 'device_id', 'cashier_id', 'customer_id', 'shift_id', 'receipt_number', 'status', 'subtotal', 'discount_amount', 'tax_amount', 'total', 'paid_amount', 'change_amount', 'notes', 'idempotency_key', 'completed_at', 'voided_at', 'voided_by', 'void_reason'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'total' => 'decimal:4', 'paid_amount' => 'decimal:4', 'change_amount' => 'decimal:4',
            'completed_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }
}

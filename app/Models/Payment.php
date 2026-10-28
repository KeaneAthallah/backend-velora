<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'sale_id', 'payment_method_id', 'method', 'amount', 'status', 'reference', 'provider', 'external_id', 'provider_response', 'idempotency_key', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'provider_response' => 'array', 'paid_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }
}

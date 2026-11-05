<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArInvoicePayment extends Model
{
    use HasFactory;

    protected $table = 'ar_invoice_payments';

    protected $fillable = ['ar_invoice_id', 'amount', 'payment_date', 'method', 'reference'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'integer'];
    }

    public function invoice()
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id');
    }
}

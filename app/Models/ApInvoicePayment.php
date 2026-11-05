<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApInvoicePayment extends Model
{
    use HasFactory;

    protected $table = 'ap_invoice_payments';

    protected $fillable = ['ap_invoice_id', 'amount', 'payment_date', 'method', 'reference'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'integer'];
    }

    public function invoice()
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id');
    }
}

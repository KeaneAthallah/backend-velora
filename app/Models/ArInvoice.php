<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArInvoice extends Model
{
    use HasFactory;

    protected $table = 'ar_invoices';

    protected $fillable = ['business_id', 'customer_id', 'number', 'issue_date', 'due_date', 'subtotal', 'tax', 'total', 'amount_paid', 'status', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'subtotal' => 'integer', 'tax' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(ArInvoicePayment::class, 'ar_invoice_id');
    }
}

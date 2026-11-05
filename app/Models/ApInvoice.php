<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApInvoice extends Model
{
    use HasFactory;

    protected $table = 'ap_invoices';

    protected $fillable = ['business_id', 'supplier_id', 'number', 'issue_date', 'due_date', 'subtotal', 'tax', 'total', 'amount_paid', 'status', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'subtotal' => 'integer', 'tax' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments()
    {
        return $this->hasMany(ApInvoicePayment::class, 'ap_invoice_id');
    }
}

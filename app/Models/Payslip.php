<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use HasFactory;

    protected $fillable = ['payroll_run_id', 'user_id', 'base_amount', 'allowances_total', 'deductions_total', 'overtime_amount', 'bonus_amount', 'commission_amount', 'net_amount', 'details'];

    protected function casts(): array
    {
        return ['base_amount' => 'integer', 'allowances_total' => 'integer', 'deductions_total' => 'integer', 'overtime_amount' => 'integer', 'bonus_amount' => 'integer', 'commission_amount' => 'integer', 'net_amount' => 'integer', 'details' => 'array'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

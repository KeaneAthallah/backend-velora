<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollStructure extends Model
{
    use HasFactory;

    protected $table = 'payroll_structures';

    protected $fillable = ['business_id', 'user_id', 'type', 'base_salary', 'hourly_rate', 'allowances', 'deductions'];

    protected function casts(): array
    {
        return ['base_salary' => 'integer', 'hourly_rate' => 'integer', 'allowances' => 'array', 'deductions' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

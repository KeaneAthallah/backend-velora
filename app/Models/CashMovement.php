<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = ['register_shift_id', 'type', 'amount', 'reason', 'actor_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function shift()
    {
        return $this->belongsTo(RegisterShift::class, 'register_shift_id');
    }
}

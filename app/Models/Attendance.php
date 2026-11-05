<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendances';

    protected $fillable = ['business_id', 'user_id', 'outlet_id', 'check_in_at', 'check_out_at', 'break_start_at', 'break_end_at', 'status', 'notes'];

    protected function casts(): array
    {
        return ['check_in_at' => 'datetime', 'check_out_at' => 'datetime', 'break_start_at' => 'datetime', 'break_end_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}

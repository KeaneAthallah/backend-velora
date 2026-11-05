<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $table = 'leave_requests';

    protected $fillable = ['business_id', 'user_id', 'leave_category_id', 'start_date', 'end_date', 'days', 'reason', 'status', 'approved_by', 'approved_at', 'approval_notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'days' => 'integer', 'approved_at' => 'datetime'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

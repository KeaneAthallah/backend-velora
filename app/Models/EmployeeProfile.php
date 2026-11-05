<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeProfile extends Model
{
    use HasFactory;

    protected $table = 'employee_profiles';

    protected $fillable = ['business_id', 'user_id', 'department_id', 'position_id', 'employee_number', 'start_date', 'end_date', 'employment_status', 'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'documents', 'metadata'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'documents' => 'array', 'metadata' => 'array'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }
}

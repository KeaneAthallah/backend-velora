<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedReport extends Model
{
    use HasFactory;

    public const TYPES = ['comparison', 'product_analytics', 'operational', 'financial'];

    public const FORMATS = ['csv', 'excel', 'pdf'];

    public const SCHEDULES = ['none', 'daily', 'weekly', 'monthly'];

    protected $fillable = [
        'business_id', 'name', 'type', 'filters', 'columns', 'format', 'schedule',
        'schedule_time', 'recipients', 'is_shared', 'last_run_at', 'next_run_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
            'recipients' => 'array',
            'is_shared' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs()
    {
        return $this->hasMany(ReportRun::class);
    }
}

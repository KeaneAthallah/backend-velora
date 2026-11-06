<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'saved_report_id', 'type', 'format', 'status', 'row_count', 'filters',
        'error', 'generated_by', 'delivered_at', 'delivery_status', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'delivered_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'row_count' => 'integer',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function savedReport()
    {
        return $this->belongsTo(SavedReport::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KitchenTicket extends Model
{
    use HasFactory;

    public const STATUSES = ['queued', 'preparing', 'ready', 'served', 'voided'];

    /** Forward-only progression inside the kitchen. */
    public const STATUS_RANK = ['queued' => 0, 'preparing' => 1, 'ready' => 2, 'served' => 3, 'voided' => -1];

    public const ACTIVE_STATUSES = ['queued', 'preparing', 'ready'];

    protected $fillable = ['business_id', 'outlet_id', 'sale_id', 'kitchen_station_id', 'ticket_number', 'status', 'items', 'note', 'printed_at', 'print_count', 'started_at', 'ready_at', 'served_at'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'printed_at' => 'datetime',
            'print_count' => 'integer',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function station()
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}

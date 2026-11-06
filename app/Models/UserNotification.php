<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification. Uses the `notifications` table created with the audit
 * migration, which predates Laravel's own notification storage and is
 * deliberately a simple business/user scoped inbox.
 */
class UserNotification extends Model
{
    use HasFactory;

    protected $table = 'notifications';

    protected $fillable = ['business_id', 'user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

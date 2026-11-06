<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    use HasFactory;

    protected $fillable = ['support_ticket_id', 'author_id', 'author_type', 'body', 'attachments'];

    protected function casts(): array
    {
        return ['attachments' => 'array'];
    }

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}

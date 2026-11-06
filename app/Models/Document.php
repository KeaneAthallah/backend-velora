<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;

    public const TYPES = ['general', 'receipt', 'invoice', 'contract', 'report', 'identity', 'other'];

    protected $fillable = [
        'business_id', 'outlet_id', 'uploaded_by', 'name', 'type', 'disk', 'path',
        'mime_type', 'size', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): ?string
    {
        return Storage::disk($this->disk)->exists($this->path)
            ? Storage::disk($this->disk)->url($this->path)
            : null;
    }
}

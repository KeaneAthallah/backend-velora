<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'short_name', 'description', 'sort_order', 'is_active',
        'capabilities', 'terminology', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'capabilities' => 'array',
            'terminology' => 'array',
            'metadata' => 'array',
        ];
    }
}

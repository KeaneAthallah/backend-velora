<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'services';

    protected $fillable = ['business_id', 'name', 'category', 'duration_minutes', 'price', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'price' => 'integer', 'is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

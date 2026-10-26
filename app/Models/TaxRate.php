<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'rate', 'type', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}

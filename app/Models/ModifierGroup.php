<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModifierGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'kind', 'selection_type', 'min_select',
        'max_select', 'is_required', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function options()
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function products()
    {
        return $this->morphedByMany(Product::class, 'assignable', 'modifier_group_assignments')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function categories()
    {
        return $this->morphedByMany(Category::class, 'assignable', 'modifier_group_assignments')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}

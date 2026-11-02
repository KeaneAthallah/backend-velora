<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanFeature extends Model
{
    use HasFactory;

    protected $table = 'subscription_plan_features';

    protected $fillable = ['plan_id', 'feature_key', 'enabled', 'label', 'description'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}

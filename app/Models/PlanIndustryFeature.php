<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanIndustryFeature extends Model
{
    use HasFactory;

    protected $table = 'subscription_plan_industry_features';

    protected $fillable = ['plan_id', 'industry', 'feature_key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}

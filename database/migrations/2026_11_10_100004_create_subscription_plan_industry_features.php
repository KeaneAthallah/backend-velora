<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_industry_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('subscription_plans')->cascadeOnDelete();
            $table->string('industry', 32);
            $table->string('feature_key', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['plan_id', 'industry', 'feature_key'], 'spif_plan_industry_feature_unique');
            $table->index(['industry', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_industry_features');
    }
};

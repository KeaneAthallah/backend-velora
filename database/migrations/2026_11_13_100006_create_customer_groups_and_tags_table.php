<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('customer_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'name']);
        });

        Schema::create('customer_customer_group', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->primary(['customer_id', 'customer_group_id']);
        });

        Schema::create('customer_customer_tag', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->primary(['customer_id', 'customer_tag_id']);
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('customer_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('action', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['business_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_activity_logs');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_customer_tag');
        Schema::dropIfExists('customer_customer_group');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_groups');
    }
};

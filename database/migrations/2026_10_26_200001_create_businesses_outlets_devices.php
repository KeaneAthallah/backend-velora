<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->enum('status', ['active', 'suspended', 'archived'])->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'code']);
            $table->index('business_id');
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('device_uuid', 128)->unique();
            $table->enum('type', ['pos', 'web', 'mobile', 'api'])->default('pos');
            $table->enum('status', ['active', 'revoked', 'inactive'])->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('businesses');
    }
};

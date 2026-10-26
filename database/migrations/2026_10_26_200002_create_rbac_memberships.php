<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 128);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['business_id', 'slug']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->boolean('is_platform_admin')->default(false)->after('phone');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('is_platform_admin');
        });

        Schema::create('business_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->enum('status', ['active', 'inactive', 'invited'])->default('active');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'business_id']);
        });

        Schema::create('outlet_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_memberships');
        Schema::dropIfExists('business_memberships');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_platform_admin', 'status']);
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};

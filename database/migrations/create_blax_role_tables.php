<?php

namespace Blax\Roles\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Permission
        Schema::create(config('permissions.table_names.permissions'), function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // PermissionUsage
        Schema::create(config('permissions.table_names.permission_usage'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->morphs('user');
            $table->json('context')->nullable();
            $table->timestamps();
        });
        
        // Role
        Schema::create(config('permissions.table_names.roles'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('roles')
                ->onDelete('set null');
            $table->string('name');
            $table->string('slug',32)->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // RoleMember
        Schema::create(config('permissions.table_names.role_members'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->morphs('member');
            $table->json('context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // RolePermission
        Schema::create(config('permissions.table_names.role_permission'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->json('context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

<?php

namespace Blax\Roles\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Idempotent: each Schema::create is gated by Schema::hasTable so running
     * after composer-update on a project that already has the tables (via a
     * previously published copy of this migration) is a no-op rather than a
     * failure. This keeps `php artisan migrate --force` safe to run as part
     * of an existing deploy pipeline.
     */
    public function up(): void
    {
        if (! Schema::hasTable(config('roles.table_names.permissions'))) {
            Schema::create(config('roles.table_names.permissions'), function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(config('roles.table_names.permission_member'))) {
            Schema::create(config('roles.table_names.permission_member'), function (Blueprint $table) {
                $table->id();
                $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
                $table->morphs('member');
                $table->json('context')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(config('roles.table_names.permission_usage'))) {
            Schema::create(config('roles.table_names.permission_usage'), function (Blueprint $table) {
                $table->id();
                $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
                $table->float('usage', 8)->default(1);
                $table->morphs('user');
                $table->json('context')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(config('roles.table_names.roles'))) {
            Schema::create(config('roles.table_names.roles'), function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')
                    ->nullable()
                    ->constrained('roles')
                    ->onDelete('set null');
                $table->string('name')->nullable();
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(config('roles.table_names.role_member'))) {
            Schema::create(config('roles.table_names.role_member'), function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
                $table->morphs('member');
                $table->json('context')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('roles.table_names.role_member'));
        Schema::dropIfExists(config('roles.table_names.roles'));
        Schema::dropIfExists(config('roles.table_names.permission_usage'));
        Schema::dropIfExists(config('roles.table_names.permission_member'));
        Schema::dropIfExists(config('roles.table_names.permissions'));
    }
};

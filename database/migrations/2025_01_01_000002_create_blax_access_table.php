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
     * Idempotent so it's safe to auto-run on a project that already has the
     * accesses table (created by a previously published copy). Source columns
     * are added via the separate add_source migration on existing installs.
     */
    public function up(): void
    {
        $table = config('roles.table_names.accesses', 'accesses');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->morphs('entity');        // Who has the access (User, Role, Permission)
            $blueprint->morphs('accessible');    // What they have access to (Lection, Scenario, etc.)
            $blueprint->nullableMorphs('source'); // What conferred this access (Subscription, Order, etc.)
            $blueprint->json('context')->nullable();
            $blueprint->timestamp('expires_at')->nullable();
            $blueprint->timestamps();

            // No DB-level unique here. SQL engines treat NULL as distinct, so a
            // constraint covering source_* would not enforce idempotency for
            // null-source rows. Idempotency is handled at the code level
            // (updateOrCreate keyed on entity + accessible + source).
            $blueprint->index(['entity_type', 'entity_id', 'accessible_type', 'accessible_id'], 'access_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('roles.table_names.accesses', 'accesses'));
    }
};

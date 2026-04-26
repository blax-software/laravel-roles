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
     * Upgrade-path migration: adds `source_type`/`source_id` morph columns to
     * the existing accesses table so grants can be tied to a conferring
     * source model (Subscription, Order, etc.) and cleaned up when that
     * source is removed.
     *
     * Also drops the old single-row uniqueness constraint, since a single
     * (entity, accessible) pair can now have multiple rows distinguished by
     * source. Idempotency is enforced at the code level by `grantAccess`.
     *
     * Idempotent: runs cleanly on installs that already have source columns
     * (e.g. a fresh install where the create migration already added them
     * via the auto-load order). Safe to re-run.
     */
    public function up(): void
    {
        $table = config('roles.table_names.accesses', 'accesses');

        // Drop the old uniqueness in its own statement so a missing index
        // (fresh install or already-dropped) doesn't abort the rest. Each
        // Blueprint flush is its own transaction-ish unit.
        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique('access_unique');
            });
        } catch (\Throwable $e) {
            // index doesn't exist — fine.
        }

        if (! Schema::hasColumn($table, 'source_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->nullableMorphs('source');
            });
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index(
                    ['entity_type', 'entity_id', 'accessible_type', 'accessible_id'],
                    'access_lookup'
                );
            });
        } catch (\Throwable $e) {
            // index already exists — fine.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $table = config('roles.table_names.accesses', 'accesses');

        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex('access_lookup');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        if (Schema::hasColumn($table, 'source_id')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropMorphs('source');
            });
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(
                    ['entity_type', 'entity_id', 'accessible_type', 'accessible_id'],
                    'access_unique'
                );
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
};

<?php

namespace Blax\Roles\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Required-access pivot.
 *
 * Each row says: to unlock the holder (e.g. a Lection), the entity
 * must already have access to the linked target (e.g. a Course). A holder
 * may list any number of targets — they are evaluated as an OR set, in the
 * same spirit as Required Roles / Required Permissions.
 *
 * Idempotent so it's safe to auto-run on a project that already has the
 * table from a previously published copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('roles.table_names.required_accesses', 'required_accesses');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->morphs('holder');   // The gated entity (e.g. Lection)
            $blueprint->morphs('required'); // The entity whose access unlocks the holder (e.g. Course)
            $blueprint->timestamps();

            $blueprint->unique(
                ['holder_type', 'holder_id', 'required_type', 'required_id'],
                'required_access_unique',
            );
            $blueprint->index(['required_type', 'required_id'], 'required_access_reverse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('roles.table_names.required_accesses', 'required_accesses'));
    }
};

<?php

namespace Blax\Roles\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original required_accesses migration used $table->morphs() (bigint
 * holder/required ids) and $table->id() (bigint PK). That doesn't match
 * the RequiredAccess model (HasUuids) or the typical host app whose
 * gated models also use UUIDs — every insert blew up with
 *   "Incorrect integer value: '<uuid>' for column 'holder_id'"
 *
 * Drop and recreate the table with uuidMorphs + uuid PK. We can drop
 * because no row could have ever been inserted under the old schema.
 * Hosts that somehow do have rows should run a manual data migration
 * before this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('roles.table_names.required_accesses', 'required_accesses');

        if (! Schema::hasTable($table)) {
            return;
        }

        // Skip when the schema is already correct — this happens on fresh
        // installs that ran the updated create migration, and on hosts who
        // hand-fixed the table before this fix-up shipped. Use Laravel's
        // Schema::getColumns() so this works on MySQL/PostgreSQL/SQLite
        // (INFORMATION_SCHEMA is MySQL-only).
        $holderIdInfo = collect(Schema::getColumns($table))
            ->firstWhere('name', 'holder_id');
        $isUuidColumn = $holderIdInfo
            && in_array(strtolower((string) ($holderIdInfo['type_name'] ?? '')), ['char', 'varchar', 'uuid'], true);
        if ($isUuidColumn) {
            return;
        }

        // Defensive: refuse to drop a non-empty table. Nobody's been able to
        // insert a row under the broken schema, so this should always be 0
        // — but if a host migrated rows in by hand we don't want to nuke them.
        $rowCount = DB::table($table)->count();
        if ($rowCount > 0) {
            throw new \RuntimeException(
                "Refusing to recreate `{$table}`: it contains {$rowCount} rows. "
                . 'Migrate them off, drop the table, and re-run the migration manually.'
            );
        }

        Schema::drop($table);

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();
            $blueprint->uuidMorphs('holder');
            $blueprint->uuidMorphs('required');
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
        // No down — there's no good way to restore the broken bigint schema
        // and nothing useful was stored in it anyway.
    }
};

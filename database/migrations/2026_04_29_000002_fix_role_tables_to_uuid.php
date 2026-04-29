<?php

namespace Blax\Roles\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Convert the role/permission/access tables from the broken bigint
 * schema (which several earlier published migrations created) to the
 * UUID schema the package's models actually expect.
 *
 * Earlier migrations shipped with `$table->id()` and `$table->morphs()`
 * — bigint everywhere — but the models (Permission / Role /
 * RoleMember / PermissionMember / Access / RequiredAccess) all use
 * `HasUuids`. So every insert blew up with
 *     "Incorrect integer value: '<uuid>' for column 'id'"
 *
 * Conversion is non-destructive:
 *   1. Each affected table is snapshotted into a sibling
 *      `<table>_bak_bigint_uuid_2026_04_29` with `CREATE TABLE LIKE` +
 *      `INSERT … SELECT *` BEFORE any DDL runs.
 *   2. PK and FK columns are swapped in place — rows are never deleted,
 *      only their `id` / `*_id` columns are replaced with UUIDs while
 *      every reference is rewritten to point at the new id. No
 *      `DROP TABLE`, no truncation, no row-level mutation other than
 *      the id column rewrite.
 *   3. After conversion, row counts are compared against the snapshot
 *      and FK integrity is verified (no orphan refs). Any mismatch
 *      throws and the snapshot tables stay around for the operator
 *      to recover from with a manual `INSERT … SELECT` + manual cleanup.
 *
 * The snapshot tables are intentionally NOT auto-dropped on success.
 * After verifying that everything works on the application side, drop
 * them manually with the `roles:drop-uuid-migration-backup-tables`
 * command shipped alongside this migration.
 *
 * Idempotent. Each phase checks whether the conversion has already
 * been applied and skips when the schema is already correct, so
 * re-running is safe and fresh installs (whose newer create migration
 * already produces UUIDs) take a fast no-op path that doesn't even
 * create snapshot tables.
 *
 * Caveats:
 *   - MySQL only. SQLite hosts get the correct schema directly from
 *     the create migration; the multi-table UPDATE/ALTER syntax we
 *     use here isn't portable.
 *   - DDL is auto-commit on MySQL; a failure mid-run leaves a partial
 *     conversion. The snapshot tables are how you recover from that.
 *     Plus the deploy.sh-level `workkit:db:backup` snapshot — defence
 *     in depth.
 *   - Morph id columns become CHAR(36). Hosts whose entities use bigint
 *     PKs (e.g. User on this app) have their ints stringified; Laravel's
 *     morph relations compare loosely, so this is transparent.
 */
return new class extends Migration
{
    /**
     * Suffix used for snapshot tables. Fixed (non-timestamped) so a
     * mid-migration crash followed by a retry doesn't lose the original
     * snapshot under a different name.
     */
    private const BAK_SUFFIX = '_bak_bigint_uuid_2026_04_29';

    public function up(): void
    {
        // The fix-up only runs on MySQL — that's the driver where the
        // broken bigint schema was actually created on production hosts.
        // On SQLite (used by package tests and some hosts in dev) the
        // updated create migration produces the correct UUID schema
        // directly, and the multi-table UPDATE/ALTER syntax we use here
        // (MySQL-specific) isn't supported anyway.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $tables = [
            'permissions'        => config('roles.table_names.permissions', 'permissions'),
            'permission_members' => config('roles.table_names.permission_member', 'permission_members'),
            'permission_usages'  => config('roles.table_names.permission_usage', 'permission_usages'),
            'roles'              => config('roles.table_names.roles', 'roles'),
            'role_members'       => config('roles.table_names.role_member', 'role_members'),
            'accesses'           => config('roles.table_names.accesses', 'accesses'),
        ];

        // Bail early if every table already has the correct schema —
        // saves us a round of snapshot churn on hosts where the migration
        // already ran (dev / staging) or fresh installs that came up
        // straight on UUID via the create migration.
        $needsConversion = array_filter($tables, fn($t) => Schema::hasTable($t) && ! $this->columnIsUuid($t, 'id'));
        if (! $needsConversion) {
            return;
        }

        // ── 1. Snapshot every affected table BEFORE any DDL ──
        // Gives us a row-perfect copy under {table}_bak_bigint_uuid_2026_04_29
        // that the operator can use for recovery if anything below blows up.
        // Bak tables are deliberately not auto-dropped — the operator removes
        // them once they're confident the conversion succeeded.
        $rowCounts = [];
        foreach ($tables as $orig) {
            if (! Schema::hasTable($orig)) {
                continue;
            }
            $rowCounts[$orig] = (int) DB::table($orig)->count();
            $this->snapshotTable($orig);
        }

        // ── 2. Convert ──
        // (Same in-place column-swap logic as before. Data is preserved
        // throughout — rows are never deleted, only their id/FK columns
        // are rewritten with UUIDs. Verification in step 3 confirms.)

        // Phase 1 — parent tables (permissions, roles): convert PK from
        // bigint → uuid AND propagate the new ids into every dependent
        // table's FK column in the same step, so dependents are valid
        // again before we drop their old bigint FK columns in Phase 2.
        $this->convertParent($tables['permissions'], [
            $tables['permission_members'] => ['permission_id', 'permission_members_permission_id_foreign', 'cascade', false],
            $tables['permission_usages']  => ['permission_id', 'permission_usages_permission_id_foreign', 'cascade', false],
        ]);

        // Roles has a self-FK on parent_id, so the "dependent" includes the
        // table itself. The fourth tuple element flags a self-FK column.
        $this->convertParent($tables['roles'], [
            $tables['role_members'] => ['role_id',   'role_members_role_id_foreign', 'cascade', false],
            $tables['roles']        => ['parent_id', 'roles_parent_id_foreign',      'set null', true],
        ]);

        // Phase 2 — for each child table, swap its own bigint id (PK) to
        // uuid. No FKs reference these so we can convert independently.
        foreach (['permission_members', 'permission_usages', 'role_members'] as $key) {
            $this->convertOwnIdColumn($tables[$key]);
        }

        // Phase 3 — accesses table: own id, plus polymorphic morph id
        // columns (entity_id, source_id). accessible_id is already
        // varchar(36) on most installs.
        $this->convertOwnIdColumn($tables['accesses']);
        $this->convertMorphIdColumn($tables['accesses'], 'entity_id');
        $this->convertMorphIdColumn($tables['accesses'], 'source_id');
        $this->convertMorphIdColumn($tables['accesses'], 'accessible_id');

        // ── 3. Verify ──
        // Row count must match the pre-conversion snapshot exactly. Any
        // mismatch means the conversion lost or duplicated rows; abort
        // loudly so the operator can restore from the snapshot table.
        foreach ($rowCounts as $orig => $expected) {
            $actual = (int) DB::table($orig)->count();
            if ($actual !== $expected) {
                throw new \RuntimeException(sprintf(
                    "Row count mismatch on `%s`: expected %d, got %d after conversion. "
                    . 'Original rows are preserved in `%s%s` — restore from there.',
                    $orig, $expected, $actual, $orig, self::BAK_SUFFIX
                ));
            }
        }

        // FK integrity: every child must still reference a row that exists.
        $this->verifyFkIntegrity($tables['permission_members'], 'permission_id', $tables['permissions']);
        $this->verifyFkIntegrity($tables['permission_usages'], 'permission_id', $tables['permissions']);
        $this->verifyFkIntegrity($tables['role_members'],     'role_id',       $tables['roles']);
        $this->verifyFkIntegrity($tables['roles'],            'parent_id',     $tables['roles'], allowNull: true);
    }

    public function down(): void
    {
        // No-op. There's no safe way to recover the bigint schema once
        // UUIDs are assigned (we'd be inventing fake auto-increment ids
        // and hoping references match). Hosts who need to roll back
        // should restore from backup.
    }

    /**
     * Convert $parent's id from bigint to uuid, propagating the new id
     * into every dependent FK column listed in $children.
     *
     * @param  array<string, array{0: string, 1: string, 2: string, 3: bool}>  $children
     *         keyed by child table name; tuple = [fkColumn, fkConstraintName, onDelete, isSelfFk]
     */
    private function convertParent(string $parent, array $children): void
    {
        if (! Schema::hasTable($parent)) {
            return;
        }

        // Already uuid? Skip the whole pass.
        if ($this->columnIsUuid($parent, 'id')) {
            return;
        }

        // 1. Add the staging column for the new uuid id.
        if (! Schema::hasColumn($parent, '_uuid_id')) {
            DB::statement("ALTER TABLE `{$parent}` ADD COLUMN `_uuid_id` CHAR(36) NULL");
        }

        // 2. Populate _uuid_id row by row. Cheap PHP loop is fine — the
        // tables this migration targets are small (admin role/permission
        // catalogs, not user-scale).
        $rows = DB::table($parent)->whereNull('_uuid_id')->get(['id']);
        foreach ($rows as $row) {
            DB::table($parent)->where('id', $row->id)->update(['_uuid_id' => (string) Str::uuid()]);
        }

        // 3. For each dependent: drop its FK, add a staging uuid FK column,
        // populate via JOIN on the parent's old/new ids.
        foreach ($children as $child => [$fkCol, $fkName, , $isSelfFk]) {
            $this->dropForeignKeyIfExists($child, $fkName);

            $stagingCol = '_uuid_' . $fkCol;
            if (! Schema::hasColumn($child, $stagingCol)) {
                DB::statement("ALTER TABLE `{$child}` ADD COLUMN `{$stagingCol}` CHAR(36) NULL");
            }

            // The self-FK case: in roles, parent_id can be NULL. Use a left
            // join so NULLs stay NULL in the staging column.
            if ($isSelfFk) {
                DB::statement(
                    "UPDATE `{$child}` c "
                    . "LEFT JOIN `{$parent}` p ON c.`{$fkCol}` = p.`id` "
                    . "SET c.`{$stagingCol}` = p.`_uuid_id` "
                    . "WHERE c.`{$fkCol}` IS NOT NULL AND c.`{$stagingCol}` IS NULL"
                );
            } else {
                DB::statement(
                    "UPDATE `{$child}` c "
                    . "JOIN `{$parent}` p ON c.`{$fkCol}` = p.`id` "
                    . "SET c.`{$stagingCol}` = p.`_uuid_id` "
                    . "WHERE c.`{$stagingCol}` IS NULL"
                );
            }
        }

        // 4. Swap parent's id. MySQL refuses to drop the primary key
        // while the column is auto_increment, so first strip the
        // auto_increment attribute, then drop. We resolve the underlying
        // column type so this works for both BIGINT and INT etc.
        $this->dropAutoIncrementId($parent);
        DB::statement("ALTER TABLE `{$parent}` DROP PRIMARY KEY");
        DB::statement("ALTER TABLE `{$parent}` DROP COLUMN `id`");
        DB::statement("ALTER TABLE `{$parent}` CHANGE `_uuid_id` `id` CHAR(36) NOT NULL");
        DB::statement("ALTER TABLE `{$parent}` ADD PRIMARY KEY (`id`)");

        // 5. Swap each child's FK column and re-create the FK constraint.
        foreach ($children as $child => [$fkCol, $fkName, $onDelete, $isSelfFk]) {
            $stagingCol = '_uuid_' . $fkCol;

            DB::statement("ALTER TABLE `{$child}` DROP COLUMN `{$fkCol}`");

            $nullable = $isSelfFk ? 'NULL' : 'NOT NULL';
            DB::statement("ALTER TABLE `{$child}` CHANGE `{$stagingCol}` `{$fkCol}` CHAR(36) {$nullable}");

            $onDeleteSql = strtoupper($onDelete) === 'SET NULL' ? 'SET NULL' : 'CASCADE';
            DB::statement(
                "ALTER TABLE `{$child}` "
                . "ADD CONSTRAINT `{$fkName}` "
                . "FOREIGN KEY (`{$fkCol}`) REFERENCES `{$parent}`(`id`) "
                . "ON DELETE {$onDeleteSql}"
            );
        }
    }

    /**
     * Convert $table's own id column from bigint auto-increment to uuid.
     * Used for tables where nothing references the id (or the references
     * have already been converted in a prior phase).
     */
    private function convertOwnIdColumn(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if ($this->columnIsUuid($table, 'id')) {
            return;
        }

        if (! Schema::hasColumn($table, '_uuid_id')) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `_uuid_id` CHAR(36) NULL");
        }

        $rows = DB::table($table)->whereNull('_uuid_id')->get(['id']);
        foreach ($rows as $row) {
            DB::table($table)->where('id', $row->id)->update(['_uuid_id' => (string) Str::uuid()]);
        }

        $this->dropAutoIncrementId($table);
        DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `id`");
        DB::statement("ALTER TABLE `{$table}` CHANGE `_uuid_id` `id` CHAR(36) NOT NULL");
        DB::statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
    }

    /**
     * Strip AUTO_INCREMENT from $table.id by re-declaring the column with
     * its current SQL type but no auto-increment. MySQL otherwise refuses
     * "DROP PRIMARY KEY" on an auto-increment column. No-op when the
     * column is already non-auto-increment.
     */
    private function dropAutoIncrementId(string $table): void
    {
        // Only relevant on MySQL — SQLite handles auto-increment differently
        // and doesn't need (or support) MODIFY COLUMN to strip it.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $info = $this->columnInfo($table, 'id');
        if (! $info) {
            return;
        }
        // type_name on MySQL gives us "bigint" / "int". Combine with the
        // unsigned/signed marker from the full type string so we recreate
        // the same column with auto_increment stripped.
        $isUnsigned = str_contains(strtolower($info['type']), 'unsigned');
        $type = $info['type_name'] . ($isUnsigned ? ' unsigned' : '');
        DB::statement("ALTER TABLE `{$table}` MODIFY `id` {$type} NOT NULL");
    }

    /**
     * Widen a polymorphic morph_id column to CHAR(36) so it can hold
     * either a UUID (host models that use HasUuids) or a stringified
     * bigint (host models that use auto-increment, e.g. User on apps
     * where users haven't been migrated yet). Existing values are
     * cast to string in place.
     */
    private function convertMorphIdColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if ($this->columnIsUuid($table, $column)) {
            return;
        }

        // Whether the column allows NULL — we want to preserve that.
        $nullable = $this->columnIsNullable($table, $column);

        $stagingCol = '_uuid_' . $column;
        if (! Schema::hasColumn($table, $stagingCol)) {
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$stagingCol}` CHAR(36) NULL");
        }
        // Stringify the existing bigint value into the staging column.
        DB::statement(
            "UPDATE `{$table}` SET `{$stagingCol}` = CAST(`{$column}` AS CHAR) "
            . "WHERE `{$column}` IS NOT NULL AND `{$stagingCol}` IS NULL"
        );

        DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");

        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        DB::statement("ALTER TABLE `{$table}` CHANGE `{$stagingCol}` `{$column}` CHAR(36) {$nullSql}");
    }

    /**
     * Driver-agnostic column inspection. Schema::getColumns() is the
     * Laravel-supported API that works on MySQL, PostgreSQL, and SQLite —
     * unlike INFORMATION_SCHEMA, which exists on neither SQLite nor in
     * the form expected on every MySQL build.
     *
     * @return array{type_name: string, type: string, nullable: bool}|null
     */
    private function columnInfo(string $table, string $column): ?array
    {
        if (! Schema::hasColumn($table, $column)) {
            return null;
        }
        foreach (Schema::getColumns($table) as $c) {
            if (($c['name'] ?? null) === $column) {
                return [
                    'type_name' => strtolower((string) ($c['type_name'] ?? '')),
                    'type'      => (string) ($c['type'] ?? ''),
                    'nullable'  => (bool) ($c['nullable'] ?? false),
                ];
            }
        }
        return null;
    }

    private function columnIsUuid(string $table, string $column): bool
    {
        $info = $this->columnInfo($table, $column);
        return $info && in_array($info['type_name'], ['char', 'varchar', 'uuid'], true);
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $info = $this->columnInfo($table, $column);
        return $info && $info['nullable'];
    }

    /**
     * Take a row-perfect copy of $orig into a sibling backup table.
     * Idempotent: a previous run that successfully snapshotted but then
     * crashed mid-conversion is preserved on retry — we never overwrite
     * an existing populated snapshot. The bak table is the operator's
     * recovery path if the in-place conversion later fails verification.
     */
    private function snapshotTable(string $orig): void
    {
        $bak = $orig . self::BAK_SUFFIX;

        if (! Schema::hasTable($bak)) {
            // Fresh snapshot — copy structure and rows in one pass.
            DB::statement("CREATE TABLE `{$bak}` LIKE `{$orig}`");
            DB::statement("INSERT INTO `{$bak}` SELECT * FROM `{$orig}`");
            return;
        }

        // Bak table already exists from a prior run. If it's empty,
        // populate it now (the previous run died right after CREATE).
        // If it already has rows, leave it alone — that's the original
        // snapshot we want to preserve.
        if ((int) DB::table($bak)->count() === 0) {
            DB::statement("INSERT INTO `{$bak}` SELECT * FROM `{$orig}`");
        }
    }

    /**
     * Confirm every non-null value in $childTable.$fkCol resolves to a
     * row in $parentTable. Throws on orphan, naming the affected table
     * so the operator can take it from there. allowNull keeps NULL refs
     * legal (e.g. roles.parent_id can legitimately be NULL).
     */
    private function verifyFkIntegrity(string $childTable, string $fkCol, string $parentTable, bool $allowNull = false): void
    {
        if (! Schema::hasTable($childTable) || ! Schema::hasColumn($childTable, $fkCol)) {
            return;
        }

        $query = DB::table($childTable . ' as c')
            ->leftJoin($parentTable . ' as p', 'c.' . $fkCol, '=', 'p.id')
            ->whereNull('p.id');

        if ($allowNull) {
            $query->whereNotNull('c.' . $fkCol);
        }

        $orphans = (int) $query->count();
        if ($orphans > 0) {
            throw new \RuntimeException(sprintf(
                "FK integrity check failed: %d orphan(s) in %s.%s pointing to missing %s rows. "
                . 'Original rows are preserved in `%s%s`.',
                $orphans, $childTable, $fkCol, $parentTable, $childTable, self::BAK_SUFFIX
            ));
        }
    }

    private function dropForeignKeyIfExists(string $table, string $fkName): void
    {
        // SQLite doesn't support DROP FOREIGN KEY at all and instead
        // requires a full table rebuild. The migration is designed for
        // MySQL bigint→UUID conversion in production; on SQLite the
        // schema is created fresh as UUID via the create migration so
        // there's nothing to drop.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        try {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fkName}`");
        } catch (\Throwable $e) {
            // FK doesn't exist — fine.
        }
    }
};

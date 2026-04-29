<?php

namespace Blax\Roles\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the snapshot tables created by the bigint→UUID fix-up migration
 * (2026_04_29_000002_fix_role_tables_to_uuid).
 *
 * The migration deliberately doesn't auto-drop them — operators want to
 * verify the application works against the converted schema before
 * losing the only on-disk copy of the original rows. Once they're
 * confident, this command tidies up. Idempotent: a host that never had
 * snapshot tables (fresh install or already-cleaned) sees a no-op.
 */
class DropUuidMigrationBackupTablesCommand extends Command
{
    private const BAK_SUFFIX = '_bak_bigint_uuid_2026_04_29';

    protected $signature = 'roles:drop-uuid-migration-backup-tables
        {--dry-run : List the tables that would be dropped without dropping them}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Drop the snapshot tables left behind by the bigint→UUID schema migration.';

    public function handle(): int
    {
        $candidates = [
            config('roles.table_names.permissions', 'permissions'),
            config('roles.table_names.permission_member', 'permission_members'),
            config('roles.table_names.permission_usage', 'permission_usages'),
            config('roles.table_names.roles', 'roles'),
            config('roles.table_names.role_member', 'role_members'),
            config('roles.table_names.accesses', 'accesses'),
            config('roles.table_names.required_accesses', 'required_accesses'),
        ];

        $bakTables = [];
        foreach ($candidates as $orig) {
            $bak = $orig . self::BAK_SUFFIX;
            if (Schema::hasTable($bak)) {
                $bakTables[$bak] = (int) DB::table($bak)->count();
            }
        }

        if (! $bakTables) {
            $this->info('No snapshot tables found — nothing to drop.');
            return self::SUCCESS;
        }

        $this->info('Found snapshot tables:');
        foreach ($bakTables as $bak => $rows) {
            $this->line(sprintf('  %s — %d rows', $bak, $rows));
        }

        if ($this->option('dry-run')) {
            $this->info('--dry-run: nothing dropped.');
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('This will permanently delete the pre-migration row snapshots.');
            $this->warn('Only proceed if the application is verified to work against the converted schema.');
            if (! $this->confirm('Drop these tables?', false)) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        foreach (array_keys($bakTables) as $bak) {
            DB::statement("DROP TABLE `{$bak}`");
            $this->line("dropped: {$bak}");
        }

        $this->info(sprintf('Dropped %d snapshot table(s).', count($bakTables)));
        return self::SUCCESS;
    }
}

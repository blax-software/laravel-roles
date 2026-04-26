<?php

namespace Blax\Roles\Tests\Unit;

use Blax\Roles\RolesServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Verifies the plug-and-play migration story:
 *  - With `roles.run_migrations = true` (default), the package's own
 *    migrations auto-run on `migrate`, creating every expected table.
 *  - With the flag flipped to false, no tables are created — the host project
 *    is in charge of publishing + running them.
 */
class MigrationAutoLoadTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [RolesServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_auto_load_creates_all_tables_on_migrate(): void
    {
        config()->set('roles.run_migrations', true);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('role_members'));
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('permission_members'));
        $this->assertTrue(Schema::hasTable('permission_usages'));
        $this->assertTrue(Schema::hasTable('accesses'));
    }

    public function test_auto_load_includes_source_columns_on_accesses(): void
    {
        config()->set('roles.run_migrations', true);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('accesses', 'source_type'));
        $this->assertTrue(Schema::hasColumn('accesses', 'source_id'));
    }

    /**
     * Production upgrade simulation: project already has the legacy access
     * table (no source columns) from a previously published create migration.
     * The new package version is composer-updated and `php artisan migrate`
     * runs in the deploy pipeline. The auto-load create migrations must
     * no-op (idempotent) and the add_source migration must add the columns.
     */
    public function test_upgrade_with_existing_tables_runs_cleanly(): void
    {
        config()->set('roles.run_migrations', true);

        // Pre-create the old-style accesses table (no source columns) and
        // the role tables, simulating a project that ran a previously
        // published copy of these migrations.
        \Illuminate\Support\Facades\Schema::create('permissions', function ($t) {
            $t->id();
            $t->string('slug')->unique();
            $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('roles', function ($t) {
            $t->id();
            $t->string('slug')->unique();
            $t->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('accesses', function ($t) {
            $t->id();
            $t->morphs('entity');
            $t->morphs('accessible');
            $t->json('context')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['entity_type', 'entity_id', 'accessible_type', 'accessible_id'], 'access_unique');
        });

        // The deploy pipeline's `php artisan migrate --force` step:
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        // Old-style accesses table now has source columns added.
        $this->assertTrue(Schema::hasColumn('accesses', 'source_id'));
        $this->assertTrue(Schema::hasColumn('accesses', 'source_type'));
    }

    // Note: the disabled-flag case (run_migrations = false) is exercised by
    // every other test in this suite — they all set the flag false in
    // defineEnvironment and rely on workbench migrations instead. If the
    // package were auto-loading despite the flag, those tests would error on
    // "table already exists" because workbench creates the same tables.
}

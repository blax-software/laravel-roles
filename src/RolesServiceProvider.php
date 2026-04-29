<?php

namespace Blax\Roles;

class RolesServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/roles.php',
            'roles'
        );

        // Bind the MorphAliasRegistry as a singleton with no aliases registered
        // by default. Hosts add their own (alias, FQCN) pairs in their service
        // provider via app(MorphAliasRegistry::class)->register(...). Hosts
        // that override the binding entirely (e.g. to swap in a stricter
        // implementation) still work — Laravel honours the host's binding.
        $this->app->singleton(\Blax\Roles\Support\MorphAliasRegistry::class);
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->offerPublishing();

        $this->registerMigrations();

        $this->registerModelBindings();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Blax\Roles\Console\DropUuidMigrationBackupTablesCommand::class,
            ]);
        }
    }

    /**
     * Auto-load the package's migrations so fresh installs work without
     * publishing. Disabled via `roles.run_migrations = false` for projects
     * that prefer to publish + manage migrations themselves.
     */
    protected function registerMigrations(): void
    {
        if (! config('roles.run_migrations', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Set up the publishing of configuration files.
     *
     * @return void
     */
    protected function offerPublishing()
    {
        if (
            ! $this->app->runningInConsole()
        ) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/roles.php' => $this->app->configPath('roles.php'),
        ], 'roles-config');

        // Publish migrations to the host project keeping the same filename as
        // the source file. That filename is what Laravel's migrator records in
        // the `migrations` table, so any migration that has already run via
        // auto-load will be marked as run for the published copy too — no
        // duplicate execution.
        $migrationsPath = __DIR__ . '/../database/migrations';
        $publishMap = [];
        foreach (glob($migrationsPath . '/*.php') as $sourcePath) {
            $publishMap[$sourcePath] = $this->app->databasePath('migrations/' . basename($sourcePath));
        }
        $this->publishes($publishMap, 'roles-migrations');
    }

    protected function registerModelBindings(): void
    {
        $this->app->bind(\Blax\Roles\Models\Role::class, fn($app) => $app->make($app->config['roles.models.role']));
        $this->app->bind(\Blax\Roles\Models\RoleMember::class, fn($app) => $app->make($app->config['roles.models.role_member']));
        $this->app->bind(\Blax\Roles\Models\Permission::class, fn($app) => $app->make($app->config['roles.models.permission']));
        $this->app->bind(\Blax\Roles\Models\PermissionUsage::class, fn($app) => $app->make($app->config['roles.models.permission_usage']));
        $this->app->bind(\Blax\Roles\Models\PermissionMember::class, fn($app) => $app->make($app->config['roles.models.permission_member']));
        $this->app->bind(\Blax\Roles\Models\Access::class, fn($app) => $app->make($app->config['roles.models.access']));
        $this->app->bind(\Blax\Roles\Models\RequiredAccess::class, fn($app) => $app->make($app->config['roles.models.required_access']));
    }
}

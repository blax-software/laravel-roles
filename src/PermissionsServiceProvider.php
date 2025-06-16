<?php

namespace Blax\Roles;

class PermissionsServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/roles.php',
            'roles'
        );
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->offerPublishing();

        $this->registerModelBindings();
    }

    /**
     * Set up the publishing of configuration files.
     *
     * @return void
     */
    protected function offerPublishing()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! function_exists('config_path')) {
            // function not available and 'publish' not relevant in Lumen
            return;
        }

        $this->publishes([
            __DIR__.'/../config/roles.php' => config_path('roles.php'),
        ], 'roles-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_blax_role_tables.php.stub' => $this->getMigrationFileName('create_blax_role_tables.php'),
        ], 'roles-migrations');
    }

        /**
     * Returns existing migration file if found, else uses the current timestamp.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(\Illuminate\Filesystem\Filesystem::class);

        return \Illuminate\Support\Collection::make([$this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR])
            ->flatMap(fn ($path) => $filesystem->glob($path.'*_'.$migrationFileName))
            ->push($this->app->databasePath()."/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }

    protected function registerModelBindings(): void
    {
        $this->app->bind(\Blax\Roles\Models\Role::class, fn ($app) => $app->make($app->config['roles.models.role']));
        $this->app->bind(\Blax\Roles\Models\RoleMember::class, fn ($app) => $app->make($app->config['roles.models.role_member']));
        $this->app->bind(\Blax\Roles\Models\RolePermission::class, fn ($app) => $app->make($app->config['roles.models.role_permission']));
        $this->app->bind(\Blax\Roles\Models\Permission::class, fn ($app) => $app->make($app->config['roles.models.permission']));
        $this->app->bind(\Blax\Roles\Models\PermissionUsage::class, fn ($app) => $app->make($app->config['roles.models.permission_usage']));
    }
}

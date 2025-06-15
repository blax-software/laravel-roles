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
        $this->offerPublishing();

    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // Load routes, migrations, etc. if needed
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
            __DIR__.'/../config/permission.php' => config_path('permission.php'),
        ], 'permission-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_permission_tables.php.stub' => $this->getMigrationFileName('create_permission_tables.php'),
        ], 'permission-migrations');
    }
}

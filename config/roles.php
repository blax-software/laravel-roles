<?php

return [

    /*
     * Whether the package should auto-run its migrations.
     *
     * Default: true — fresh installs work plug-and-play (composer require +
     * php artisan migrate). The package's own migrations live in vendor/ and
     * are auto-loaded.
     *
     * Set to false if you have already published migrations to your project's
     * database/migrations directory and want to manage the schema yourself.
     * If you publish *and* leave this true, Laravel's migrator will see the
     * same filenames in both locations and run each migration once — but
     * that requires the published filename to match the source filename. If
     * you've published with a different timestamp prefix, disable this flag
     * to avoid re-runs.
     */
    'run_migrations' => true,

    'models' => [
        'role' => \Blax\Roles\Models\Role::class,
        'role_member' => \Blax\Roles\Models\RoleMember::class,
        'permission' => \Blax\Roles\Models\Permission::class,
        'permission_usage' => \Blax\Roles\Models\PermissionUsage::class,
        'permission_member' => \Blax\Roles\Models\PermissionMember::class,
        'access' => \Blax\Roles\Models\Access::class,
        'required_access' => \Blax\Roles\Models\RequiredAccess::class,
    ],

    'table_names' => [
        'permissions' => 'permissions',
        'permission_usage' => 'permission_usages',
        'permission_member' => 'permission_members',
        'roles' => 'roles',
        'role_member' => 'role_members',
        'role_permission' => 'role_permissions',
        'accesses' => 'accesses',
        'required_accesses' => 'required_accesses',
    ],

];

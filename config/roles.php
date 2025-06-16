<?php

return [

    'models' => [
        'role' => \Blax\Roles\Models\Role::class,
        'role_member' => \Blax\Roles\Models\RoleMember::class,
        'role_permission' => \Blax\Roles\Models\RolePermission::class,
        'permission' => \Blax\Roles\Models\Permission::class,
        'permission_usage' => \Blax\Roles\Models\PermissionUsage::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'permission_usage' => 'permission_usage',
    ],

];

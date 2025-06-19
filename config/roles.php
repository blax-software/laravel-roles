<?php

return [

    'models' => [
        'role' => \Blax\Roles\Models\Role::class,
        'role_member' => \Blax\Roles\Models\RoleMember::class,
        'permission' => \Blax\Roles\Models\Permission::class,
        'permission_usage' => \Blax\Roles\Models\PermissionUsage::class,
        'permission_member' => \Blax\Roles\Models\PermissionMember::class,
    ],

    'table_names' => [
        'permissions' => 'permissions',
        'permission_usage' => 'permission_usages',
        'permission_member' => 'permission_members',
        'roles' => 'roles',
        'role_member' => 'role_members',
        'role_permission' => 'role_permissions',
    ],

];

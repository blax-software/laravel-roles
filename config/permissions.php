<?php

return [
    'models' => [
        'role' => YourVendor\Permissions\Models\Role::class,
        'permission' => YourVendor\Permissions\Models\Permission::class,
    ],
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
        'role_has_permissions' => 'role_has_permissions',
    ],
];

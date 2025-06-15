<?php

return [

    'models' => [
        'role' => \Blax\Roles\Models\Role::class,
        'permission' => \Blax\Roles\Models\Permission::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_roles' => 'model_has_roles',
        'model_has_permissions' => 'model_has_permissions',
        'role_has_permissions' => 'role_has_permissions',
    ],

];

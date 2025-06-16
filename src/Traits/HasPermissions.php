<?php

namespace Blax\Roles\Traits;

trait HasPermissions
{
    public function hasPermission(string $permission, array $context = []): bool
    {
        return $this->permissions()
            ->where('name', $permission)
            ->where(function ($query) use ($context) {
                if (!empty($context)) {
                    $query->where('context', $context);
                }
            })
            ->exists();
    }

    public function permissions()
    {
        return $this->morphToMany(
            config('roles.models.permission'),
            'member',
            config('roles.table_names.permission_members')
        );
    }
}

<?php

namespace Blax\Roles\Traits;

use Illuminate\Support\Collection;

trait HasPermissions
{
    public function hasPermission(string $permission): bool
    {
        $allpermissions = $this->permissions();

        if ($allpermissions->contains('slug', '*')) {
            return true; // If any permission is '*', all permissions are granted
        }

        return $allpermissions->contains(function ($perm) use ($permission) {
            return $perm->slug === $permission || $perm->name === $permission;
        });
    }

    public function rolePermissions()
    {
        return $this->hasManyThrough(
            config('roles.models.permission'),
            config('roles.models.role_member'),
            'member_id',
            'id',
            'id',
            'role_id'
        );
    }

    public function individualPermissions()
    {
        return $this->morphToMany(
            config('roles.models.permission'),
            'member',
            config('roles.table_names.permission_member', 'permission_member')
        );
    }

    public function permissions()
    {
        $rolePerms   = $this->rolePermissions()->get();
        $directPerms = $this->permissions()->get();

        return $rolePerms
            ->merge($directPerms)
            ->unique('id');
    }

    public function assignPermission($permission)
    {
        $permission_class = config('roles.models.permission');

        if (is_numeric($permission)) {
            $permission = $permission_class::find($permission);
        } elseif (is_string($permission)) {
            $permission = $permission_class::firstOrCreate([
                'slug' => $permission
            ]);
        }

        if ($permission instanceof $permission_class) {
            // Already a Permission instance
        } else {
            throw new \InvalidArgumentException('Permission must be a string, numeric ID, or an instance of Permission.');
        }

        if ($permission) {
            $this->permissions()->syncWithoutDetaching($permission);

            return true;
        }

        return false;
    }

    public function removePermission($permission): bool
    {
        $permission_class = config('roles.models.permission');

        if (is_numeric($permission)) {
            $permission = $permission_class::find($permission);
        } elseif (is_string($permission)) {
            $permission = $permission_class::where('slug', $permission)->first();
        }

        if ($permission) {
            $this->permissions()->detach($permission);

            return true;
        }

        return true;
    }
}

<?php

namespace Blax\Roles\Traits;

use Illuminate\Support\Collection;

trait HasPermissions
{
    public function can(string $permission): bool
    {
        return $this->hasPermission($permission);
    }

    public function hasPermission(string $permission): bool
    {
        $allpermissions = $this->allPermissions();

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

    public function permissions()
    {
        return $this->morphToMany(
            config('roles.models.permission'),
            'member',
            config('roles.table_names.permission_member', 'permission_member')
        );
    }

    public function addPermission($permission)
    {
        $permission_class = config('roles.models.permission');

        if (is_numeric($permission)) {
            $permission = $permission_class::find($permission);
        } elseif (is_string($permission)) {
            $permission = $permission_class::where('slug', $permission)->firstOrCreate();
        }

        if ($permission instanceof $permission_class) {
            // Already a Permission instance
        } else {
            throw new \InvalidArgumentException('Permission must be a string, numeric ID, or an instance of Permission.');
        }

        if ($permission) {
            return $this->permissions()->syncWithoutDetaching($permission);
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

        if ($permission instanceof $permission_class) {
            // Already a Permission instance
        } else {
            throw new \InvalidArgumentException('Permission must be a string, numeric ID, or an instance of Permission.');
        }

        if ($permission) {
            return $this->permissions()->detach($permission);
        }

        return false;
    }

    /**
     * Get all permissions directly assigned or inherited via roles.
     *
     * @return Collection
     */
    public function allPermissions()
    {
        // Directly assigned permissions
        $direct = $this->permissions()->get();

        // Permissions via roles (if the roles() relation exists)
        if (method_exists($this, 'roles')) {
            $rolePermissions = $this->roles()
                ->with('permissions')
                ->get()
                ->pluck('permissions')
                ->flatten();
        } else {
            $rolePermissions = collect();
        }

        // Merge and dedupe by 'id'
        return $direct
            ->merge($rolePermissions)
            ->unique('id')
            ->values();
    }
}

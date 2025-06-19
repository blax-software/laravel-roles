<?php

namespace Blax\Roles\Traits;

use Illuminate\Support\Collection;

trait HasPermissions
{
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()
            ->where('name', $permission)
            ->orWhere('slug', '*')
            ->exists();
    }

    public function permissions()
    {
        $permissionClass       = config('roles.models.permission');
        $permissionTable       = config('roles.table_names.permissions');
        $permissionMemberTable = config('roles.table_names.permission_member');

        // direct assignment
        $direct = $this->morphToMany(
            $permissionClass,
            'member',
            $permissionMemberTable
        );

        if (! method_exists($this, 'roles')) {
            return $direct;
        }

        // inherited via roles
        $permissionRoleTable = config('roles.table_names.permission_role');
        $roleMemberTable     = config('roles.table_names.role_member');
        $memberType          = $this->getMorphClass();

        $viaRoles = $permissionClass::query()
            ->select("$permissionTable.*")
            ->join($permissionRoleTable, "$permissionTable.id", '=', "$permissionRoleTable.permission_id")
            ->join($roleMemberTable,     "$permissionRoleTable.role_id", '=', "$roleMemberTable.role_id")
            ->where("$roleMemberTable.member_id",   $this->getKey())
            ->where("$roleMemberTable.member_type", $memberType);

        return $direct->union($viaRoles);
    }

    public function addPermission($permission): bool
    {
        $permission_class = config('roles.models.permission');

        if (is_numeric($permission)) {
            $permission = $permission_class::find($permission);
        } elseif (is_string($permission)) {
            $permission = $permission_class::where('slug', $permission)->firstOrCreate();
        } elseif ($permission instanceof $permission_class) {
            // Already a Permission instance
        } else {
            throw new \InvalidArgumentException('Permission must be a string, numeric ID, or an instance of Permission.');
        }

        if ($permission) {
            return $this->permissions()->attach($permission);
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
        } elseif ($permission instanceof $permission_class) {
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
    public function allPermissions(): Collection
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

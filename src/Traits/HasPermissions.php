<?php

namespace Blax\Roles\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait HasPermissions
{
    use HasAccess;
    /**
     * Check if the entity has a specific permission.
     *
     * Supports hierarchical matching: having permission 'lection' also
     * grants 'lection.45', 'lection.foo.bar', etc. (parent acts as wildcard).
     */
    public function hasPermission(string $permission): bool
    {
        $allpermissions = $this->permissions();

        // Wildcard: '*' grants everything
        if ($allpermissions->contains('slug', '*')) {
            return true;
        }

        return $allpermissions->contains(function ($perm) use ($permission) {
            // Exact match
            if ($perm->slug === $permission) {
                return true;
            }

            // Hierarchical: permission 'lection' grants 'lection.45', 'lection.foo.bar', etc.
            if (str_starts_with($permission, $perm->slug . '.')) {
                return true;
            }

            return false;
        });
    }

    /**
     * Get permissions inherited through roles.
     *
     * Resolves: entity → role_members (get role IDs) → permission_members
     * where member_type is a Role → permissions.
     */
    public function rolePermissions(): Collection
    {
        $roleModel = config('roles.models.role');
        $permissionModel = config('roles.models.permission');
        $roleMemberTable = config('roles.table_names.role_member', 'role_members');
        $permMemberTable = config('roles.table_names.permission_member', 'permission_members');

        // Get the actual morph class that Role instances use (may differ from config if app extends vendor model)
        $roleMorphClass = (new $roleModel)->getMorphClass();

        // Get role IDs this entity belongs to (via role_members)
        $roleIds = DB::table($roleMemberTable)
            ->where('member_id', $this->getKey())
            ->where('member_type', $this->getMorphClass())
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect();
        }

        // Get permission IDs assigned to those roles (roles are members in permission_members)
        $permissionIds = DB::table($permMemberTable)
            ->whereIn('member_id', $roleIds)
            ->where('member_type', $roleMorphClass)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('permission_id')
            ->unique();

        if ($permissionIds->isEmpty()) {
            return collect();
        }

        return $permissionModel::whereIn('id', $permissionIds)->get();
    }

    /**
     * Get permissions directly assigned to this entity (via permission_members morphToMany).
     */
    public function individualPermissions()
    {
        return $this->morphToMany(
            config('roles.models.permission'),
            'member',
            config('roles.table_names.permission_member', 'permission_members')
        );
    }

    /**
     * Get all permissions: role-based + directly assigned, deduplicated.
     */
    public function permissions(): Collection
    {
        $rolePerms   = $this->rolePermissions();
        $directPerms = $this->individualPermissions()->get();

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

        if (! ($permission instanceof $permission_class)) {
            throw new \InvalidArgumentException('Permission must be a string, numeric ID, or an instance of Permission.');
        }

        $this->individualPermissions()->syncWithoutDetaching($permission);

        return true;
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
            $this->individualPermissions()->detach($permission);
        }

        return true;
    }
}

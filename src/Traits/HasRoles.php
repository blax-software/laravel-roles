<?php

namespace Blax\Roles\Traits;

use Blax\Roles\Models\Role;

trait HasRoles
{
    use HasPermissions;

    /**
     * Get all roles for the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function roles()
    {
        return $this->morphToMany(
            config('roles.models.role', \Blax\Roles\Models\Role::class),
            'member',
            config('roles.table_names.role_members', 'role_members')
        );
    }

    /**
     * Check if the user has a specific role.
     *
     * @param string $roleSlug
     * @return bool
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Assigns the role to the memberable
     * 
     * @param int|string|Role $role
     * 
     * @return $this
     */
    public function assignRole(string|Role $role)
    {
        if (is_string($role) && !is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::where('slug', $role)->first();
        } elseif (is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif ($role instanceof Role) {
            $this->roles()->attach($role);
        } else {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        return $this;
    }

    /**
     * Removes the role from the memberable
     * 
     * @param int|string|Role $role
     * 
     * @return $this
     */
    public function removeRole(string|Role $role)
    {
        if (is_string($role) && !is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::where('slug', $role)->first();
        } elseif (is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif ($role instanceof Role) {
            $this->roles()->detach($role);
        } else {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        return $this;
    }

    /**
     * Syncs the roles for the memberable
     * 
     * @param array $roles
     * 
     * @return $this
     */
    public function syncRoles(array $roles)
    {
        $roleIds = [];
        foreach ($roles as $role) {
            if (is_string($role) && !is_numeric($role)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::where('slug', $role)->first();
            } elseif (is_numeric($role)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
            } elseif ($role instanceof Role) {
                $roleModel = $role;
            }

            if ($roleModel instanceof Role) {
                $roleIds[] = $roleModel->id;
            }
        }

        $this->roles()->sync($roleIds);

        return $this;
    }

    /**
     * Checks if the memberable has any of the given roles
     * 
     * @param array $roles
     * 
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }
        return false;
    }
    /**
     * Checks if the memberable has all of the given roles
     * 
     * @param array $roles
     * 
     * @return bool
     */
    public function hasAllRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if (!$this->hasRole($role)) {
                return false;
            }
        }
        return true;
    }
}

<?php

namespace Blax\Roles\Traits;

trait HasRoles
{
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
}

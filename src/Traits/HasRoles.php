<?php

namespace Blax\Roles;

trait HasRoles
{
    /**
     * The roles that belong to the model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(
            config('permissions.models.role'),
            config('permissions.table_names.model_has_roles'),
            'model_id',
            'role_id'
        );
    }

    /**
     * Check if the model has a specific role.
     *
     * @param  string  $role
     * @return bool
     */
    public function hasRole($role)
    {
        return $this->roles()->where('name', $role)->exists();
    }
}

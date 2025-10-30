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
        $pivotTable = config('roles.table_names.role_member', 'role_members');

        return $this->morphToMany(
            config('roles.models.role', \Blax\Roles\Models\Role::class),
            'member',
            $pivotTable
        )->withPivot('expires_at', 'created_at', 'updated_at')
            ->withTimestamps()
            ->where(function ($q) use ($pivotTable) {
                $q->wherePivot('expires_at', '>', now())
                    ->orWhereNull($pivotTable . '.expires_at');
            });
    }

    /**
     * Check if the user has a specific role.
     *
     * @param int|string|Role $role
     * @return bool
     */
    public function hasRole(string|Role $role): bool
    {
        if (is_string($role) && !is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::where('slug', $role)->first();
        } elseif (is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif ($role instanceof Role) {
            return $this->roles()->wherePivot('role_id', $role->id)->exists();
        } else {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        return $role
            ? $this->roles()->wherePivot('role_id', $role->id)->exists()
            : false;
    }

    /**
     * Assigns the role to the memberable
     *
     * @param int|string|Role $role
     *
     * @return $this
     */
    public function assignRole(string|Role $role, int $max_times = 1)
    {
        if (is_string($role) && !is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                'name' => $role,
                'slug' => str()->slug($role)
            ]);
        } elseif (is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        }

        if ($max_times >= 0) {
            $currentCount = $this->roles()->wherePivot('role_id', $role->id)->count();
            if ($currentCount >= $max_times) {
                return $this;
            }
        }

        if ($role instanceof Role) {
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
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                    'name' => $role,
                    'slug' => str()->slug($role)
                ]);
            } elseif (is_numeric($role)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
            } elseif ($role instanceof Role) {
                $roleModel = $role;
            } elseif (is_object($role) && isset($role->id)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role->id);
            } elseif (is_array($role) && isset($role['id'])) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role['id']);
            } else {
                throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
            }

            if (@$roleModel instanceof Role) {
                $roleIds[] = $roleModel->id;
            }
        }

        $this->roles()->sync($roleIds);

        return $this;
    }

    /**
     * Extend the expiration of an existing role by the given hours, or attach the role
     * with an expiration if the member does not already have it.
     * If the existing role has no expiration (expires_at is null), it will be left as-is.
     *
     * @param int|string|Role $role
     * @param int $hours
     * @return $this
     */
    public function extendOrAddRole($role, $hours)
    {
        $hours = (int) $hours;
        if ($hours <= 0) {
            return $this;
        }

        // Resolve role
        if (is_string($role) && !is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                'name' => $role,
                'slug' => str()->slug($role)
            ]);
        } elseif (is_numeric($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif (!$role instanceof Role) {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        if (!$role) {
            return $this;
        }

        $roleMemberModel = config('roles.models.role_member', \Blax\Roles\Models\RoleMember::class);

        $existing = $roleMemberModel::withoutGlobalScopes()
            ->where('role_id', $role->id)
            ->where('member_id', $this->getKey())
            ->where('member_type', $this->getMorphClass())
            ->first();

        if ($existing) {
            // Extend expiry. If it does not expire (null), leave it unchanged.
            $existing->extendByHours($hours, false);
        } else {
            $this->roles()->attach($role->id, [
                'expires_at' => now()->addHours($hours),
            ]);
        }

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

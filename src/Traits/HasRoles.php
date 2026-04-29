<?php

namespace Blax\Roles\Traits;

use Blax\Roles\Models\Role;
use Illuminate\Support\Str;

trait HasRoles
{
    use HasPermissions;

    /**
     * A "role identifier" is anything that can be passed in lieu of a Role
     * model: a numeric primary key (legacy), a UUID primary key, or a
     * Role instance. Anything else (a non-numeric, non-UUID string) is
     * treated as a *name* by the higher-level methods.
     */
    private static function isRoleIdString(mixed $value): bool
    {
        return is_numeric($value) || (is_string($value) && Str::isUuid($value));
    }

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
        )->using(config('roles.models.role_member', \Blax\Roles\Models\RoleMember::class))
            ->withPivot('expires_at', 'context', 'created_at', 'updated_at')
            ->withTimestamps()
            ->where(function ($q) use ($pivotTable) {
                $q->where($pivotTable . '.expires_at', '>', now())
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
        if (self::isRoleIdString($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif (is_string($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                'name' => $role,
                'slug' => str()->slug($role)
            ]);
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
        if (self::isRoleIdString($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif (is_string($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::where('slug', $role)->first();
        } elseif (!$role instanceof Role) {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        if ($role) {
            $this->roles()->detach($role);
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
            if (self::isRoleIdString($role)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
            } elseif (is_string($role)) {
                $roleModel = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                    'name' => $role,
                ], [
                    'slug' => str()->slug($role)
                ]);
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
    public function extendOrAddRole(int|string|Role $role, int $hours)
    {
        $hours = (int) $hours;
        if ($hours <= 0) {
            return $this;
        }

        // Resolve role
        if (self::isRoleIdString($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif (is_string($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                'name' => $role,
            ], [
                'slug' => str()->slug($role)
            ]);
        } elseif (!$role instanceof Role) {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        if (!$role) {
            return $this;
        }

        $roleMemberModel = config('roles.models.role_member', \Blax\Roles\Models\RoleMember::class);

        $existing = $roleMemberModel::where('role_id', $role->id)
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
     * Extend or create a role membership, scoped by an origin identifier stored in `context`.
     *
     * This allows multiple independent role_member records for the same role+user (e.g.,
     * one from a subscription and one from a day-pass purchase). Each origin tracks its
     * own expiry independently.
     *
     * - If an active (non-expired) record with the same origin exists → extend it
     * - If only expired records exist for this origin, or no record exists → create new
     *
     * @param int|string|Role $role         The role to assign/extend
     * @param int $hours                    Duration in hours
     * @param string $originName            Human-readable label (e.g., product name)
     * @param string $originValue           Lookup key (e.g., "ProductPrice:uuid")
     * @param bool $forceExpiry             If true, set expiration even on records with null expires_at
     * @return $this
     */
    public function extendOrAddRoleByOrigin(int|string|Role $role, int $hours, string $originName, string $originValue, bool $forceExpiry = false)
    {
        $hours = (int) $hours;
        if ($hours <= 0) {
            return $this;
        }

        // Resolve role
        if (self::isRoleIdString($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::find($role);
        } elseif (is_string($role)) {
            $role = config('roles.models.role', \Blax\Roles\Models\Role::class)::firstOrCreate([
                'name' => $role,
            ], [
                'slug' => str()->slug($role)
            ]);
        } elseif (!$role instanceof Role) {
            throw new \InvalidArgumentException('Role must be a string, numeric ID, or an instance of Role.');
        }

        if (!$role) {
            return $this;
        }

        $roleMemberModel = config('roles.models.role_member', \Blax\Roles\Models\RoleMember::class);

        // Look for an active (non-expired) record from the same origin
        $existing = $roleMemberModel::where('role_id', $role->id)
            ->where('member_id', $this->getKey())
            ->where('member_type', $this->getMorphClass())
            ->whereJsonContains('context->origin_value', $originValue)
            ->first();

        if ($existing) {
            $existing->extendByHours($hours, $forceExpiry);
        } else {
            // Pass the context as an array — the RoleMember pivot has a
            // 'context' => 'array' cast that JSON-encodes it on save.
            // json_encode()-ing it here would double-encode the value.
            $this->roles()->attach($role->id, [
                'expires_at' => now()->addHours($hours),
                'context' => [
                    'origin_name' => $originName,
                    'origin_value' => $originValue,
                ],
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

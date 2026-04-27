<?php

namespace Blax\Roles\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait HasAccess
{
    /**
     * Get all access entries directly owned by this entity.
     */
    public function accesses()
    {
        return $this->morphMany(config('roles.models.access'), 'entity');
    }

    /**
     * Check if this entity has access to a specific model.
     *
     * Resolves through:
     *  1. Direct access (entity = this model)
     *  2. Role-based access (entity = any role this model has) — if HasRoles is used
     *  3. Permission-based access (entity = any permission this model has) — if HasPermissions is used
     *
     * @param  string|Model  $accessible  Model instance or class name
     * @param  int|string|null  $id  Required when $accessible is a class name
     */
    public function hasAccess(string|Model $accessible, int|string|null $id = null): bool
    {
        [$accessibleType, $accessibleId] = $this->resolveAccessibleArguments($accessible, $id);

        $accessModel = config('roles.models.access');
        $table = (new $accessModel)->getTable();

        $query = DB::table($table)
            ->where('accessible_type', $accessibleType)
            ->where('accessible_id', $accessibleId)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });

        // Build OR conditions for all entity sources
        $query->where(function ($q) {
            // 1. Direct access
            $q->where(function ($sub) {
                $sub->where('entity_type', $this->getMorphClass())
                    ->where('entity_id', $this->getKey());
            });

            // 2. Via roles (if this model uses HasRoles)
            $roleIds = $this->resolveAccessRoleIds();
            if ($roleIds !== null && $roleIds->isNotEmpty()) {
                $roleMorphClass = (new (config('roles.models.role')))->getMorphClass();
                $q->orWhere(function ($sub) use ($roleMorphClass, $roleIds) {
                    $sub->where('entity_type', $roleMorphClass)
                        ->whereIn('entity_id', $roleIds);
                });
            }

            // 3. Via permissions (if this model uses HasPermissions)
            $permissionIds = $this->resolveAccessPermissionIds();
            if ($permissionIds !== null && $permissionIds->isNotEmpty()) {
                $permMorphClass = (new (config('roles.models.permission')))->getMorphClass();
                $q->orWhere(function ($sub) use ($permMorphClass, $permissionIds) {
                    $sub->where('entity_type', $permMorphClass)
                        ->whereIn('entity_id', $permissionIds);
                });
            }
        });

        return $query->exists();
    }

    /**
     * Grant this entity access to a specific model.
     *
     * Idempotent per (entity, accessible, source) tuple — re-granting with the
     * same source refreshes `expires_at` and `context`. Different sources for
     * the same accessible coexist as separate rows, so the holder doesn't lose
     * access when one source goes away (e.g. a subscription ends but a lifetime
     * purchase remains).
     *
     * @param  Model  $accessible  The target model
     * @param  array|null  $context  Optional JSON context
     * @param  Carbon|null  $expiresAt  Optional expiration
     * @param  Model|null  $source  Optional source model (Subscription, Order, …)
     *                              that conferred this access. When the source
     *                              is removed (see RevokesAccessOnDelete or
     *                              Access::revokeBySource), this row is cleaned up.
     * @return Model  The created or updated Access entry
     */
    public function grantAccess(
        Model $accessible,
        ?array $context = null,
        ?Carbon $expiresAt = null,
        ?Model $source = null,
    ): Model {
        $accessModel = config('roles.models.access');

        return $accessModel::updateOrCreate([
            'entity_type' => $this->getMorphClass(),
            'entity_id' => $this->getKey(),
            'accessible_type' => $accessible->getMorphClass(),
            'accessible_id' => $accessible->getKey(),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
        ], [
            'context' => $context,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Revoke this entity's access to a specific model.
     *
     * If $source is provided, only the row matching that exact source is
     * removed; other source-bound or null-source grants for the same
     * accessible are left intact. Pass $source = null (default) to revoke
     * every grant for this (entity, accessible).
     *
     * @param  string|Model  $accessible  Model instance or class name
     * @param  int|string|null  $id  Required when $accessible is a class name
     * @param  Model|null  $source  Optional source model to scope the revoke to
     * @return int  Number of deleted access entries
     */
    public function revokeAccess(string|Model $accessible, int|string|null $id = null, ?Model $source = null): int
    {
        [$accessibleType, $accessibleId] = $this->resolveAccessibleArguments($accessible, $id);

        $query = $this->accesses()
            ->where('accessible_type', $accessibleType)
            ->where('accessible_id', $accessibleId);

        if ($source !== null) {
            $query->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey());
        }

        return $query->delete();
    }

    /**
     * Revoke all direct accesses for this entity, optionally filtered by
     * accessible type and/or source.
     *
     * @param  string|null  $accessibleType  Optional model class to filter by
     * @param  Model|null  $source  Optional source model to filter by
     * @return int  Number of deleted access entries
     */
    public function revokeAllAccess(?string $accessibleType = null, ?Model $source = null): int
    {
        $query = $this->accesses();

        if ($accessibleType) {
            $morphClass = (new $accessibleType)->getMorphClass();
            $query->where('accessible_type', $morphClass);
        }

        if ($source !== null) {
            $query->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey());
        }

        return $query->delete();
    }

    /**
     * Revoke every direct access on this entity that was conferred by the
     * given source. Useful when a single subscription should be torn down
     * while leaving lifetime / manual grants alone.
     */
    public function revokeAccessBySource(Model $source): int
    {
        return $this->accesses()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /**
     * Get all active Access entries this entity can access (direct + roles + permissions).
     *
     * @param  string|null  $accessibleType  Optional model class to filter by
     * @return Collection  Collection of Access model instances
     */
    public function allAccess(?string $accessibleType = null): Collection
    {
        $accessModel = config('roles.models.access');

        $query = $accessModel::query()
            ->active()
            ->where(function ($q) {
                // Direct
                $q->where(function ($sub) {
                    $sub->where('entity_type', $this->getMorphClass())
                        ->where('entity_id', $this->getKey());
                });

                // Via roles
                $roleIds = $this->resolveAccessRoleIds();
                if ($roleIds !== null && $roleIds->isNotEmpty()) {
                    $roleMorphClass = (new (config('roles.models.role')))->getMorphClass();
                    $q->orWhere(function ($sub) use ($roleMorphClass, $roleIds) {
                        $sub->where('entity_type', $roleMorphClass)
                            ->whereIn('entity_id', $roleIds);
                    });
                }

                // Via permissions
                $permissionIds = $this->resolveAccessPermissionIds();
                if ($permissionIds !== null && $permissionIds->isNotEmpty()) {
                    $permMorphClass = (new (config('roles.models.permission')))->getMorphClass();
                    $q->orWhere(function ($sub) use ($permMorphClass, $permissionIds) {
                        $sub->where('entity_type', $permMorphClass)
                            ->whereIn('entity_id', $permissionIds);
                    });
                }
            });

        if ($accessibleType) {
            $morphClass = (new $accessibleType)->getMorphClass();
            $query->where('accessible_type', $morphClass);
        }

        return $query->get();
    }

    /**
     * Get all accessible IDs of a specific model type.
     *
     * @param  string  $modelClass  The model class to get IDs for
     * @return Collection  Collection of accessible IDs
     */
    public function accessibleIds(string $modelClass): Collection
    {
        return $this->allAccess($modelClass)
            ->pluck('accessible_id')
            ->unique()
            ->values();
    }

    /**
     * Sync accesses for a specific accessible type.
     *
     * Replaces all direct accesses for the given type with the new set.
     * Only affects accesses owned by THIS entity (not role/permission inherited ones).
     *
     * If $source is provided, the sync is scoped to that source — only rows
     * with matching source_type/source_id are removed/replaced, leaving rows
     * from other sources (or null source) untouched. This lets a subscription
     * refresh its grants without clobbering a user's lifetime purchases.
     *
     * @param  string  $accessibleType  The model class
     * @param  array  $ids  Array of model IDs to sync
     * @param  array|null  $context  Optional context for new entries
     * @param  Carbon|null  $expiresAt  Optional expiration for new entries
     * @param  Model|null  $source  Optional source scoping
     */
    public function syncAccess(
        string $accessibleType,
        array $ids,
        ?array $context = null,
        ?Carbon $expiresAt = null,
        ?Model $source = null,
    ): void {
        $morphClass = (new $accessibleType)->getMorphClass();

        $scoped = fn($q) => $source
            ? $q->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
            : $q->whereNull('source_type')->whereNull('source_id');

        // Remove accesses not in the new set (within this source scope)
        $scoped($this->accesses()
            ->where('accessible_type', $morphClass)
            ->whereNotIn('accessible_id', $ids))
            ->delete();

        // Add missing accesses (within this source scope)
        $existing = $scoped($this->accesses()
            ->where('accessible_type', $morphClass))
            ->pluck('accessible_id')
            ->toArray();

        $toCreate = array_diff($ids, $existing);

        foreach ($toCreate as $id) {
            $this->accesses()->create([
                'accessible_type' => $morphClass,
                'accessible_id' => $id,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'context' => $context,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /**
     * Resolve the accessible type and ID from flexible arguments.
     *
     * @return array{0: string, 1: int|string}
     */
    protected function resolveAccessibleArguments(string|Model $accessible, int|string|null $id = null): array
    {
        if ($accessible instanceof Model) {
            return [$accessible->getMorphClass(), $accessible->getKey()];
        }

        // $accessible is a class name string
        if ($id === null) {
            throw new \InvalidArgumentException('An ID must be provided when $accessible is a class name.');
        }

        return [(new $accessible)->getMorphClass(), $id];
    }

    /**
     * Get role IDs for resolving access through roles.
     * Returns null if this model doesn't use roles.
     *
     * Public so other access-resolution code (e.g. HasRequiredAccess) can
     * reuse the same logic when building cross-table queries.
     */
    public function resolveAccessRoleIds(): ?Collection
    {
        if (! method_exists($this, 'roles')) {
            return null;
        }

        $roleMemberTable = config('roles.table_names.role_member', 'role_members');

        return DB::table($roleMemberTable)
            ->where('member_id', $this->getKey())
            ->where('member_type', $this->getMorphClass())
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->pluck('role_id');
    }

    /**
     * Get permission IDs for resolving access through permissions.
     * Returns null if this model doesn't use permissions.
     *
     * Public so other access-resolution code (e.g. HasRequiredAccess) can
     * reuse the same logic when building cross-table queries.
     */
    public function resolveAccessPermissionIds(): ?Collection
    {
        if (! method_exists($this, 'permissions')) {
            return null;
        }

        return $this->permissions()->pluck('id');
    }
}

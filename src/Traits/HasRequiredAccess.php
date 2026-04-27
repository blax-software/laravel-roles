<?php

namespace Blax\Roles\Traits;

use Blax\Roles\Models\RequiredAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "Required Access" — declarative dependency between gated entities.
 *
 * A holder lists one or more required targets. At check-time, if the
 * requesting entity (typically a User) has any active Access entry to
 * ANY of those targets — direct, role-based, or permission-based — the
 * holder is considered unlocked.
 *
 * This sits alongside Required Roles and Required Permissions and is
 * evaluated with OR semantics: holder is unlocked if any of {required
 * roles, required permissions, required access} resolves true.
 *
 * Performance: hasUnlockedRequiredAccessFor() resolves the question in a
 * single SQL statement that joins required_accesses with accesses, instead
 * of N+1 lookups across linked targets.
 */
trait HasRequiredAccess
{
    /**
     * Pivot rows declaring this entity's required-access targets.
     */
    public function requiredAccessLinks()
    {
        return $this->morphMany(
            config('roles.models.required_access', RequiredAccess::class),
            'holder',
        );
    }

    /**
     * Resolve the actual target Models (loaded polymorphically).
     *
     * Convenience for UIs that need to render the linked entities. For the
     * lock check, prefer hasUnlockedRequiredAccessFor() — it never loads
     * targets and resolves in a single SQL statement.
     */
    public function requiredAccessTargets(): EloquentCollection
    {
        $links = $this->requiredAccessLinks()->with('required')->get();

        return $links->map(fn(Model $link) => $link->required)
            ->filter()
            ->values()
            ->pipe(fn($items) => new EloquentCollection($items->all()));
    }

    /**
     * Add a required-access target. Idempotent on (holder, required).
     */
    public function addRequiredAccess(Model $target): Model
    {
        $modelClass = config('roles.models.required_access', RequiredAccess::class);

        return $modelClass::firstOrCreate([
            'holder_type' => $this->getMorphClass(),
            'holder_id' => $this->getKey(),
            'required_type' => $target->getMorphClass(),
            'required_id' => $target->getKey(),
        ]);
    }

    /**
     * Remove a required-access target.
     *
     * @return int  Number of pivot rows deleted (0 or 1).
     */
    public function removeRequiredAccess(Model $target): int
    {
        return $this->requiredAccessLinks()
            ->where('required_type', $target->getMorphClass())
            ->where('required_id', $target->getKey())
            ->delete();
    }

    /**
     * Replace this entity's required-access set with the given targets.
     * Adds missing links, removes ones not in the new set.
     *
     * @param  iterable<Model>  $targets
     */
    public function syncRequiredAccess(iterable $targets): void
    {
        $modelClass = config('roles.models.required_access', RequiredAccess::class);
        $holderType = $this->getMorphClass();
        $holderId = $this->getKey();

        // Build the desired set keyed by "type|id" for cheap diffing.
        $desired = [];
        foreach ($targets as $target) {
            $key = $target->getMorphClass() . '|' . $target->getKey();
            $desired[$key] = [
                'type' => $target->getMorphClass(),
                'id' => $target->getKey(),
            ];
        }

        $existing = $this->requiredAccessLinks()->get();
        $existingKeys = [];
        foreach ($existing as $link) {
            $key = $link->required_type . '|' . $link->required_id;
            $existingKeys[$key] = $link;
        }

        // Delete the ones not in desired set.
        foreach ($existingKeys as $key => $link) {
            if (! array_key_exists($key, $desired)) {
                $link->delete();
            }
        }

        // Insert the ones not yet present.
        foreach ($desired as $key => $entry) {
            if (! array_key_exists($key, $existingKeys)) {
                $modelClass::create([
                    'holder_type' => $holderType,
                    'holder_id' => $holderId,
                    'required_type' => $entry['type'],
                    'required_id' => $entry['id'],
                ]);
            }
        }
    }

    /**
     * Does $entity have access to ANY of this holder's required-access
     * targets? Direct, role-based, and permission-based access entries
     * all count, mirroring HasAccess::hasAccess().
     *
     * Resolves the question in a single SQL statement (no per-target
     * round-trips). Returns false if the entity is null, has no
     * required-access links, or none of them are unlocked.
     */
    public function hasUnlockedRequiredAccessFor(?Model $entity): bool
    {
        if (! $entity) {
            return false;
        }

        $accessTable = (new (config('roles.models.access')))->getTable();
        $requiredTable = config('roles.table_names.required_accesses', 'required_accesses');

        $query = DB::table($requiredTable)
            ->where($requiredTable . '.holder_type', $this->getMorphClass())
            ->where($requiredTable . '.holder_id', $this->getKey())
            ->whereExists(function ($sub) use ($entity, $accessTable, $requiredTable) {
                $sub->select(DB::raw(1))
                    ->from($accessTable)
                    ->whereColumn($accessTable . '.accessible_type', $requiredTable . '.required_type')
                    ->whereColumn($accessTable . '.accessible_id', $requiredTable . '.required_id')
                    ->where(function ($q) use ($accessTable) {
                        $q->whereNull($accessTable . '.expires_at')
                            ->orWhere($accessTable . '.expires_at', '>', now());
                    })
                    ->where(function ($q) use ($entity, $accessTable) {
                        // 1. Direct access from the entity itself.
                        $q->where(function ($s) use ($entity, $accessTable) {
                            $s->where($accessTable . '.entity_type', $entity->getMorphClass())
                                ->where($accessTable . '.entity_id', $entity->getKey());
                        });

                        // 2. Access conferred by any of the entity's roles.
                        if (method_exists($entity, 'resolveAccessRoleIds')) {
                            $roleIds = $entity->resolveAccessRoleIds();
                            if ($roleIds !== null && $roleIds->isNotEmpty()) {
                                $roleMorphClass = (new (config('roles.models.role')))->getMorphClass();
                                $q->orWhere(function ($s) use ($accessTable, $roleMorphClass, $roleIds) {
                                    $s->where($accessTable . '.entity_type', $roleMorphClass)
                                        ->whereIn($accessTable . '.entity_id', $roleIds);
                                });
                            }
                        }

                        // 3. Access conferred by any of the entity's permissions.
                        if (method_exists($entity, 'resolveAccessPermissionIds')) {
                            $permissionIds = $entity->resolveAccessPermissionIds();
                            if ($permissionIds !== null && $permissionIds->isNotEmpty()) {
                                $permMorphClass = (new (config('roles.models.permission')))->getMorphClass();
                                $q->orWhere(function ($s) use ($accessTable, $permMorphClass, $permissionIds) {
                                    $s->where($accessTable . '.entity_type', $permMorphClass)
                                        ->whereIn($accessTable . '.entity_id', $permissionIds);
                                });
                            }
                        }
                    });
            });

        return $query->exists();
    }
}

<?php

namespace Blax\Roles\Traits;

/**
 * Apply this trait to any model that can be the source of an access grant
 * (Subscription, Order, RoleMember, etc.) and all access rows referencing it
 * via the polymorphic `source` columns will be deleted automatically when
 * the source model is deleted.
 *
 * If the model uses Laravel's SoftDeletes you'll get cleanup on hard delete
 * by default. Override `revokesAccessOnSoftDelete()` to also clean up on
 * soft delete.
 *
 * Prefer this trait when you want the cleanup wired up implicitly. If you'd
 * rather wire it through a Laravel observer or listen for a domain event,
 * use `Access::revokeBySource($model)` directly — same effect.
 */
trait RevokesAccessOnDelete
{
    public static function bootRevokesAccessOnDelete(): void
    {
        static::deleted(function ($model) {
            if (
                method_exists($model, 'isForceDeleting')
                && ! $model->isForceDeleting()
                && ! $model->revokesAccessOnSoftDelete()
            ) {
                return;
            }

            config('roles.models.access', \Blax\Roles\Models\Access::class)::revokeBySource($model);
        });
    }

    /**
     * Whether revoking should also fire on soft-delete.
     * Defaults to false (only hard deletes cascade) — soft-deleted sources
     * may legitimately come back.
     */
    public function revokesAccessOnSoftDelete(): bool
    {
        return false;
    }
}

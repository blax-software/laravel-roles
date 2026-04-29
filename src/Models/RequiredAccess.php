<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pivot row for the "Required Access" feature.
 *
 * A holder model (anything using HasRequiredAccess) lists one or more
 * required targets. At access-check time, if the requesting entity has
 * an active Access entry to ANY of those targets, the holder is unlocked.
 * This sits alongside Required Roles / Required Permissions and is
 * evaluated with OR semantics.
 */
class RequiredAccess extends Model
{
    use HasUuids;

    protected $fillable = [
        'holder_id',
        'holder_type',
        'required_id',
        'required_type',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.required_accesses') ?: parent::getTable();
    }

    /**
     * The gated entity that owns this requirement.
     */
    public function holder()
    {
        return $this->morphTo();
    }

    /**
     * The entity whose access unlocks the holder.
     */
    public function required()
    {
        return $this->morphTo();
    }

    /**
     * Render this link as an admin-friendly payload, using the
     * MorphAliasRegistry for the type alias and human-readable name.
     *
     * Returns null for orphaned rows (target deleted) so callers can
     * `->filter()` them out without explicit guards. Hosts that need
     * extra fields can compose this with their own merge:
     *
     *   $link->toAdminArray() + ['custom' => $link->required->custom]
     */
    public function toAdminArray(): ?array
    {
        $target = $this->required;
        if (! $target) {
            return null;
        }

        /** @var \Blax\Roles\Support\MorphAliasRegistry $registry */
        $registry = app(\Blax\Roles\Support\MorphAliasRegistry::class);

        return [
            'id'           => $this->id,
            'target_type'  => $registry->aliasFor($this->required_type, $target),
            'target_id'    => (string) $this->required_id,
            'name'         => $registry->nameFor($target),
            'is_published' => ($target->published_at ?? null) !== null,
        ];
    }
}

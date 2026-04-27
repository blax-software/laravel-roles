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
}

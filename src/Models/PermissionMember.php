<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\WillExpire;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class PermissionMember extends MorphPivot
{
    use HasUuids, WillExpire;

    protected $fillable = [
        'permission_id',
        'member_id',
        'member_type',
        'context',
        'expires_at',
    ];

    protected $casts = [
        // member_id is a polymorphic varchar(36) that holds either a UUID
        // (HasUuids host models) or a stringified bigint (auto-increment
        // host models, e.g. a default User). Casting to string forces the
        // value to be bound as a string in every query Eloquent builds for
        // this pivot (attach / detach / where). Without it a bigint member
        // key is bound as an INTEGER, and MySQL then coerces the WHOLE
        // varchar column to DOUBLE to compare — which throws
        // "Truncated incorrect DOUBLE value: '<uuid>'" (error 1292) the
        // moment a UUID-keyed member shares the same permission.
        'member_id' => 'string',
        'context' => 'array',
        'expires_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.permission_member') ?: parent::getTable();
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function member()
    {
        return $this->morphTo();
    }
}

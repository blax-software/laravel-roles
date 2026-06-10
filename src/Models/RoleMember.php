<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\WillExpire;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class RoleMember extends MorphPivot
{
    use HasUuids, WillExpire;

    protected $fillable = [
        'role_id',
        'member',
        'context',
        'expires_at',
        'created_at',
        'updated_at',
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
        // moment a UUID-keyed member shares the same role.
        'member_id' => 'string',
        'context' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Config uses the singular key 'role_member' (mapped to 'role_members'
        // in the default config). Looking up the plural key returned null and
        // fell through to parent::getTable() — which on a MorphPivot returns
        // a non-pluralised "role_member", pointing direct queries at a table
        // that doesn't exist.
        $this->table = config('roles.table_names.role_member') ?: parent::getTable();
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function member()
    {
        return $this->morphTo();
    }
}

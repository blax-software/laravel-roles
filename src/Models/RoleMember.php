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

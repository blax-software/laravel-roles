<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\WillExpire;
use Illuminate\Database\Eloquent\Model;

class PermissionMember extends Model
{
    use WillExpire;

    protected $fillable = [
        'permission_id',
        'member_id',
        'member_type',
        'context',
        'expires_at',
    ];

    protected $casts = [
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

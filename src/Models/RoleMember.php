<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\WillExpire;
use Illuminate\Database\Eloquent\Model;

class RoleMember extends Model
{
    use WillExpire;

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

        $this->table = config('roles.table_names.role_members') ?: parent::getTable();
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

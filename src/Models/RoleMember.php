<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class RoleMember extends Model {
    protected $fillable = [
        'role_id',
        'member',
        'context',
        'expires_at',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('permissions.table_names.role_members') ?: parent::getTable();
    }

    public function role() {
        return $this->belongsTo(Role::class);
    }
    
    public function member() {
        return $this->morphTo();
    }
}

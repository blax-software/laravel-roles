<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model {
    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('permissions.table_names.role_permission') ?: parent::getTable();
    }

    public function role() {
        return $this->belongsTo(Role::class);
    }

    public function permission() {
        return $this->belongsTo(Permission::class);
    }
}

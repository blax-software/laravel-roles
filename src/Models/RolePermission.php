<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\WillExpire;
use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    use WillExpire;

    protected $fillable = [
        'role_id',
        'permission_id',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.role_permissions') ?: parent::getTable();
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}

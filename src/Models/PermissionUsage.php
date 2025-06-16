<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionUsage extends Model {
    protected $fillable = [
        'permission_id',
        'usage_count'
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.permission_usages') ?: parent::getTable();
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}

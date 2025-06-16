<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'slug',
        'description',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.permissions') ?: parent::getTable();
    }

    public function usages()
    {
        return $this->hasMany(PermissionUsage::class);
    }

    public function roles()
    {
        return $this->belongsToMany(RolePermission::class);
    }

    public function members()
    {
        return $this->hasMany(PermissionMember::class);
    }
}

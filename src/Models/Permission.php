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
        return $this->hasMany(config('roles.table_names.permission_usage'));
    }

    public function roles()
    {
        return $this->morphToMany(
            config('roles.table_names.role'),
            'member',
            config('roles.table_names.permission_member'),
            'permission_id',
            'member_id'
        )->where('member_type', config('roles.table_names.role'));
    }

    public function members()
    {
        return $this->hasMany(config('roles.table_names.permission_member'));
    }
}

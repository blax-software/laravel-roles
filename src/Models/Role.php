<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.roles') ?: parent::getTable();
    }

    public function members()
    {
        return $this->hasMany(RoleMember::class, 'role_id', 'id');
    }

    public function permissions()
    {
        return $this->hasMany(Permission::class, 'role_id', 'id');
    }

    public function parent()
    {
        return $this->hasOne(Role::class, 'id', 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Role::class, 'parent_id');
    }
}

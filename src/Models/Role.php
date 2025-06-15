<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model {
    protected $fillable = [
        'parent_id',
        'name', 
        'slug',
        'description',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('permissions.table_names.roles') ?: parent::getTable();
    }

    public function members() {
        return $this->belongsToMany(RoleMember::class);
    }

    public function permissions() {
        return $this->belongsToMany(RolePermission::class);
    }

    public function parent() {
        return $this->belongsTo(Role::class, 'parent_id');
    }
    
    public function children() {
        return $this->hasMany(Role::class, 'parent_id');
    }
}

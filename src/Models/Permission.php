<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\HasAccess;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasAccess;
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
        return $this->hasMany(config('roles.models.permission_usage'));
    }

    /**
     * Get all roles that have this permission (via permission_members where member_type is Role).
     */
    public function roles()
    {
        return $this->morphedByMany(
            config('roles.models.role'),
            'member',
            config('roles.table_names.permission_member', 'permission_members'),
            'permission_id',
            'member_id'
        );
    }

    public function members()
    {
        return $this->hasMany(config('roles.models.permission_member'), 'permission_id');
    }
}

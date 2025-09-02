<?php

namespace Blax\Roles\Models;

use Blax\Roles\Traits\HasPermissions;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasPermissions;

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

        static::creating(function ($model) {
            $baseSlug = str()->slug($model->slug ?: $model->name);
            $slug = $baseSlug;
            $suffix = 1;

            while (static::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix++;
            }

            $model->slug = $slug;
        });
    }

    public function members()
    {
        return $this->hasMany(RoleMember::class, 'role_id', 'id');
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

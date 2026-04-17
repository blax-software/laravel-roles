<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PermissionUsage extends Model
{
    use HasUuids;
    protected $fillable = [
        'permission_id',
        'usage',
        'context',
        'user_type',
        'user_id',
    ];

    protected $casts = [
        'context' => 'array',
        'usage' => 'float',
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

    public function user()
    {
        return $this->morphTo();
    }
}

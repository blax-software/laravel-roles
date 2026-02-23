<?php

namespace Blax\Roles\Models;

use Illuminate\Database\Eloquent\Model;

class Access extends Model
{
    protected $fillable = [
        'entity_id',
        'entity_type',
        'accessible_id',
        'accessible_type',
        'context',
        'expires_at',
    ];

    protected $casts = [
        'context' => 'array',
        'expires_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('roles.table_names.accesses') ?: parent::getTable();
    }

    /**
     * The entity that owns this access (User, Role, or Permission).
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * The target model this access grants access to (e.g. Lection, Scenario).
     */
    public function accessible()
    {
        return $this->morphTo();
    }

    /**
     * Scope to only active (non-expired) access entries.
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to only expired access entries.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }
}

<?php

namespace Blax\Roles\Models;

use Blax\Roles\Events\SourceAccessesRevoked;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Access extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'entity_type',
        'accessible_id',
        'accessible_type',
        'source_id',
        'source_type',
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
     * The model that conferred this access (e.g. Subscription, Order, ProductPrice).
     * Null for manual / lifetime grants.
     */
    public function source()
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

    /**
     * Scope to entries conferred by a specific source model.
     */
    public function scopeFromSource($query, Model $source)
    {
        return $query
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey());
    }

    /**
     * Delete every access conferred by the given source model.
     *
     * Use this from an observer / trait / queued listener when the source
     * (subscription, order, role membership, …) goes away. Fires the
     * SourceAccessesRevoked event so other listeners can react.
     *
     * @return int  Number of deleted access entries
     */
    public static function revokeBySource(Model $source): int
    {
        $count = static::query()->fromSource($source)->delete();

        if ($count > 0) {
            event(new SourceAccessesRevoked($source, $count));
        }

        return $count;
    }
}

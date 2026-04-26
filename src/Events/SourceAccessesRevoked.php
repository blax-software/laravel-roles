<?php

namespace Blax\Roles\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a cascade cleanup of access rows tied to a source model
 * (e.g. when a subscription is canceled and all its grants are revoked).
 *
 * Listen to this if you need to log, audit, or notify when grants are
 * removed in bulk because their source disappeared.
 */
class SourceAccessesRevoked
{
    use Dispatchable;

    public function __construct(
        public Model $source,
        public int $count,
    ) {
    }
}

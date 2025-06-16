<?php

namespace Blax\Roles\Traits;

trait WillExpire
{
    public static function bootWillExpire()
    {
        static::addGlobalScope('willExpire', function ($builder) {
            $builder->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        });
    }


    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}

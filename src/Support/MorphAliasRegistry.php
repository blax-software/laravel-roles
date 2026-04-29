<?php

namespace Blax\Roles\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Registry that maps short type aliases (e.g. "course", "product",
 * "feature") to fully-qualified Eloquent model classes used by the
 * Required-Access / Access system.
 *
 * Hosting apps register their aliases at boot time. The package then
 * uses the registry to:
 *   - resolve an alias coming from an admin UI back to a model class
 *     (so a (alias, id) pair coming over a websocket can be looked up)
 *   - reverse-look-up an alias for a stored morph class (so admin
 *     payloads can render a friendly type tag instead of the raw FQCN)
 *   - resolve a human-readable name for any model the registry knows
 *
 * Why a registry instead of hard-coded maps? Because the map is
 * inherently application-specific (the package knows nothing about
 * "Course" or "Lection") but every app that uses Required-Access has
 * the same need. Centralising it here means the package can ship a
 * default link serializer, alias-based add/remove helpers, and admin
 * payloads without each app duplicating the same plumbing.
 */
class MorphAliasRegistry
{
    /**
     * Forward map: alias => fully-qualified class name.
     *
     * @var array<string, class-string>
     */
    protected array $map = [];

    /**
     * Custom alias resolvers for cases where one class maps to multiple
     * aliases depending on the instance (e.g. App\Models\Blog → "course"
     * or "lection" depending on the URL prefix). Keyed by class name; the
     * resolver receives the model and returns an alias string or null
     * (null = fall through to the static map / class basename).
     *
     * @var array<class-string, callable(Model): ?string>
     */
    protected array $aliasResolvers = [];

    /**
     * Per-class human-name resolvers. The resolver receives the model
     * and returns the display name. If null is returned, the registry
     * falls through to its default chain (getLocalized('title'/'name'),
     * title, name, slug, …).
     *
     * @var array<class-string, callable(Model): ?string>
     */
    protected array $nameResolvers = [];

    /**
     * Register an alias → class mapping, optionally with a custom name
     * resolver for that class. The name resolver is the place to handle
     * compound names (e.g. ProductPrice → "Product Name — Tier").
     *
     * Idempotent: re-registering the same alias overrides the previous
     * mapping, which makes test setup convenient.
     *
     * @param  callable(Model): ?string|null  $nameResolver
     */
    public function register(string $alias, string $class, ?callable $nameResolver = null): void
    {
        $this->map[$alias] = $class;
        if ($nameResolver) {
            $this->nameResolvers[$class] = $nameResolver;
        }
    }

    /**
     * Register a custom alias resolver for cases where one class maps to
     * multiple aliases per-instance. The resolver should return null to
     * let the static map take over for instances it doesn't recognise.
     *
     * @param  callable(Model): ?string  $resolver
     */
    public function registerAliasResolver(string $class, callable $resolver): void
    {
        $this->aliasResolvers[$class] = $resolver;
    }

    /**
     * Resolve an alias (or already-FQCN string) to a class name, or null
     * if neither matches.
     */
    public function resolveClass(string $alias): ?string
    {
        if (isset($this->map[$alias])) {
            return $this->map[$alias];
        }

        // Backwards compatibility: callers sometimes pass a FQCN directly.
        if (class_exists($alias)) {
            return $alias;
        }

        return null;
    }

    /**
     * Reverse-look-up: given a stored morph class (and optionally the
     * actual instance), return a short alias for UI badges. Falls back
     * to a lower-cased class basename when nothing is registered, so
     * the result is always a non-empty string.
     */
    public function aliasFor(string $morphClass, ?Model $instance = null): string
    {
        // Per-instance custom resolver wins (e.g. Blog → course/lection).
        if ($instance) {
            foreach ($this->aliasResolvers as $class => $resolver) {
                if ($morphClass === $class || is_subclass_of($morphClass, $class)) {
                    $alias = $resolver($instance);
                    if ($alias) {
                        return $alias;
                    }
                }
            }
        }

        // Static reverse lookup (exact match first, then parents).
        $alias = array_search($morphClass, $this->map, true);
        if ($alias !== false) {
            return $alias;
        }
        foreach ($this->map as $a => $c) {
            if (is_subclass_of($morphClass, $c)) {
                return $a;
            }
        }

        return strtolower(class_basename($morphClass));
    }

    /**
     * Best-effort human-readable name for any model the registry knows.
     * Hosts can supply per-class resolvers via register()/registerNameResolver
     * for compound names; otherwise we walk the usual attribute chain.
     */
    public function nameFor(Model $model): string
    {
        // Walk class hierarchy so a resolver registered for the parent
        // (e.g. ProductPrice) still applies to subclasses.
        foreach ($this->nameResolvers as $class => $resolver) {
            if ($model instanceof $class) {
                $name = $resolver($model);
                if ($name !== null && $name !== '') {
                    return $name;
                }
            }
        }

        // Default chain: localised title/name → bare title/name → slug.
        if (method_exists($model, 'getLocalized')) {
            $localized = $model->getLocalized('title') ?? $model->getLocalized('name');
            if ($localized) {
                return $localized;
            }
        }

        return $model->title
            ?? $model->name
            ?? $model->slug
            ?? 'Untitled';
    }

    /**
     * Register a standalone name resolver without (re-)registering the alias.
     *
     * @param  callable(Model): ?string  $resolver
     */
    public function registerNameResolver(string $class, callable $resolver): void
    {
        $this->nameResolvers[$class] = $resolver;
    }

    /**
     * @return array<string, class-string>  alias => class
     */
    public function all(): array
    {
        return $this->map;
    }
}

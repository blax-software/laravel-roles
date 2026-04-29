<?php

namespace Blax\Roles\Facades;

use Blax\Roles\Support\MorphAliasRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $alias, string $class, ?callable $nameResolver = null)
 * @method static void registerAliasResolver(string $class, callable $resolver)
 * @method static void registerNameResolver(string $class, callable $resolver)
 * @method static ?string resolveClass(string $alias)
 * @method static string aliasFor(string $morphClass, ?\Illuminate\Database\Eloquent\Model $instance = null)
 * @method static string nameFor(\Illuminate\Database\Eloquent\Model $model)
 * @method static array<string, class-string> all()
 *
 * @see MorphAliasRegistry
 */
class MorphAliases extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MorphAliasRegistry::class;
    }
}

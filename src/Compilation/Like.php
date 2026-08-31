<?php

declare(strict_types=1);

namespace Datawell\Compilation;

/**
 * LIKE wildcard escaping, owned centrally (D16). Pairs with Raw::like(), which declares
 * the escape character on every LIKE the package emits.
 */
final class Like
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function contains(string $value): string
    {
        return '%'.self::escape($value).'%';
    }

    public static function startsWith(string $value): string
    {
        return self::escape($value).'%';
    }

    public static function endsWith(string $value): string
    {
        return '%'.self::escape($value);
    }
}

<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * LIKE wildcard escaping, owned centrally (D16). `\` is the escape character on
 * every supported driver when declared via ESCAPE; MySQL/Postgres/SQLite all accept it.
 */
final class Like
{
    /** The escape character declared on every LIKE the package emits. */
    public const string ESCAPE = '\\';

    /**
     * `<column> LIKE ? ESCAPE ?` as a raw expression — the one place the package writes
     * raw SQL, because no driver-neutral builder API carries ESCAPE and SQLite has no
     * default escape character. The identifier comes from the definition (never from the
     * request) and is quoted by the connection's grammar; the pattern and escape character
     * are bindings. Larastan's literal-string rule cannot express "grammar-wrapped
     * identifier", hence the single targeted ignore.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function clause(EloquentBuilder|QueryBuilder $query, string $column): Expression
    {
        $sql = $query->getGrammar()->wrap($column).' LIKE ? ESCAPE ?';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier, values are bindings)
    }

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

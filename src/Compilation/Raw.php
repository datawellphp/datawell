<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The package's only raw-SQL site. Every expression here interpolates exactly one thing:
 * a column identifier that comes from the definition (never from a request), quoted by
 * the connection's grammar; anything request-derived travels as a binding. Larastan's
 * literal-string rule cannot express "grammar-wrapped identifier", hence the targeted
 * ignores — kept in one file so the surface stays auditable.
 */
final class Raw
{
    /** The escape character declared on every LIKE the package emits. */
    public const string ESCAPE = '\\';

    /**
     * `<column> LIKE ? ESCAPE ?` — no driver-neutral builder API carries ESCAPE, and
     * SQLite has no default escape character.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function like(EloquentBuilder|QueryBuilder $query, string $column): Expression
    {
        $sql = $query->getGrammar()->wrap($column).' LIKE ? ESCAPE ?';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier, values are bindings)
    }

    /**
     * `<column> IS NULL` as an ORDER BY term: makes null placement deterministic across
     * drivers (nulls last), which keyset pagination depends on (D53).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function isNull(EloquentBuilder|QueryBuilder $query, string $column): Expression
    {
        $sql = $query->getGrammar()->wrap($column).' IS NULL';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier)
    }
}

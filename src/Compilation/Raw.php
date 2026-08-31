<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
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
    /**
     * The driver name behind a query (`sqlite`, `mysql`, `mariadb`, `pgsql`, …).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function driver(EloquentBuilder|QueryBuilder $query): string
    {
        $connection = $query->getConnection();

        return $connection instanceof Connection ? $connection->getDriverName() : 'unknown';
    }

    /** The escape character declared on every LIKE the package emits. */
    public const string ESCAPE = '\\';

    /**
     * `<column> LIKE ? ESCAPE ?` — no driver-neutral builder API carries ESCAPE, and
     * SQLite has no default escape character. Contains semantics are case-insensitive
     * everywhere (D16): MySQL and SQLite compare LIKE that way by default, Postgres needs ILIKE.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function like(EloquentBuilder|QueryBuilder $query, string|Expression $column): Expression
    {
        $operator = self::driver($query) === 'pgsql' ? 'ILIKE' : 'LIKE';
        $sql = $query->getGrammar()->wrap($column).' '.$operator.' ? ESCAPE ?';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier, values are bindings)
    }

    /**
     * `<fn>(<column>)` for a sum/avg/min/max measure.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function aggregate(EloquentBuilder|QueryBuilder $query, string $fn, string|Expression $column): Expression
    {
        $sql = strtoupper($fn).'('.$query->getGrammar()->wrap($column).')';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier, function name from the AggregateType enum)
    }

    /**
     * The start date of the calendar bucket a date/date-time column falls in — day, week
     * (Monday), month, quarter or year — as a `YYYY-MM-DD` string, computed database-side
     * per driver (D15). Instants are converted to the effective timezone first via the
     * driver's own conversion (`CONVERT_TZ`, `AT TIME ZONE`); the timezone name is passed
     * as a binding, appended by the caller in select order.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @param  string|null  $timezone  null for wall dates, or when no conversion is needed
     * @return array{Expression, list<string>} the expression and its bindings
     */
    public static function grain(EloquentBuilder|QueryBuilder $query, string|Expression $column, string $grain, ?string $timezone): array
    {
        $driver = self::driver($query);
        $col = $query->getGrammar()->wrap($column);

        $converts = $timezone !== null && in_array($driver, ['mysql', 'mariadb', 'pgsql'], true);

        if ($converts) {
            $col = $driver === 'pgsql'
                ? "(({$col} AT TIME ZONE 'UTC') AT TIME ZONE ?)"
                : "CONVERT_TZ({$col}, '+00:00', ?)";
        }

        $sql = match ($driver) {
            'mysql', 'mariadb' => match ($grain) {
                'day' => "DATE_FORMAT({$col}, '%Y-%m-%d')",
                'week' => "DATE_FORMAT(DATE_SUB({$col}, INTERVAL WEEKDAY({$col}) DAY), '%Y-%m-%d')",
                'month' => "DATE_FORMAT({$col}, '%Y-%m-01')",
                'quarter' => "CONCAT(YEAR({$col}), '-', LPAD((QUARTER({$col}) - 1) * 3 + 1, 2, '0'), '-01')",
                default => "DATE_FORMAT({$col}, '%Y-01-01')",
            },
            'pgsql' => "to_char(date_trunc('{$grain}', {$col}), 'YYYY-MM-DD')",
            default => match ($grain) {
                'day' => "strftime('%Y-%m-%d', {$col})",
                'week' => "date({$col}, '-6 days', 'weekday 1')",
                'month' => "strftime('%Y-%m-01', {$col})",
                'quarter' => "strftime('%Y-', {$col}) || substr('01040710', ((CAST(strftime('%m', {$col}) AS INTEGER) + 2) / 3 - 1) * 2 + 1, 2) || '-01'",
                default => "strftime('%Y-01-01', {$col})",
            },
        };

        // The converted column may appear more than once (MySQL week/quarter); bind the
        // timezone once per placeholder actually emitted.
        $bindings = $converts ? array_fill(0, substr_count($sql, '?'), (string) $timezone) : [];

        return [$query->getConnection()->raw($sql), $bindings]; // @phpstan-ignore argument.type (grammar-wrapped identifier; grain and driver are package enums; timezone is a binding)
    }

    /**
     * A correlated subselect as a parenthesised expression, usable wherever a column is —
     * the compiled form of an aggregate field (D55). The subquery's bindings are the
     * caller's to place, in the binding group of the clause the expression lands in.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function subquery(EloquentBuilder|QueryBuilder $query, QueryBuilder $subquery): Expression
    {
        return $query->getConnection()->raw('('.$subquery->toSql().')'); // @phpstan-ignore argument.type (SQL compiled by the grammar from a relation-existence query; its values travel as bindings)
    }

    /**
     * `<column> IS NULL` as an ORDER BY term: makes null placement deterministic across
     * drivers (nulls last), which keyset pagination depends on (D53).
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function isNull(EloquentBuilder|QueryBuilder $query, string|Expression $column): Expression
    {
        $sql = $query->getGrammar()->wrap($column).' IS NULL';

        return $query->getConnection()->raw($sql); // @phpstan-ignore argument.type (grammar-wrapped identifier)
    }
}

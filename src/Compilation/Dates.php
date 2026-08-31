<?php

declare(strict_types=1);

namespace Datawell\Compilation;

use Carbon\CarbonImmutable;
use Datawell\Exceptions\UnsupportedException;
use Datawell\Execution\Context;
use Datawell\Operators\Operator;
use DateTimeImmutable;

/**
 * Resolves date values — absolute or relative (D12) — in the effective timezone (D13)
 * and expands operators into half-open boundaries. `DateTime` boundaries are converted
 * to UTC instants; `Date` boundaries stay wall dates (D14). `between` is inclusive at
 * both ends (D10), expressed as [start of from, end of to).
 */
final class Dates
{
    /**
     * A calendar period [start, end) in the effective timezone for a value that denotes
     * a day or a span: an absolute date, `today`, a relative point (its day), or a
     * relative period (D52: complete units).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function period(mixed $value, Context $context): array
    {
        $now = self::now($context);

        if (is_array($value) && isset($value['relative'])) {
            return match ($value['relative']) {
                'this' => self::unitSpan($now, self::unit($value['unit'] ?? null), 0, 1),
                'last' => self::unitSpan($now, self::unit($value['unit'] ?? null), -self::count($value), 0),
                'next' => self::unitSpan($now, self::unit($value['unit'] ?? null), 1, 1 + self::count($value)),
                default => self::day(self::point($value, $context)),
            };
        }

        if (is_array($value) && array_key_exists('from', $value) && array_key_exists('to', $value)) {
            [$start] = self::period($value['from'], $context);
            [, $end] = self::period($value['to'], $context);

            return [$start, $end];
        }

        return self::day(self::point($value, $context));
    }

    /**
     * An instant in the effective timezone for a value that denotes one: an absolute
     * date-time, `now`, `ago`/`fromNow`, or (at start of day) an absolute date / `today`.
     */
    public static function point(mixed $value, Context $context): CarbonImmutable
    {
        $now = self::now($context);

        if (is_array($value) && isset($value['relative'])) {
            return match ($value['relative']) {
                'now' => $now,
                'today' => $now->startOfDay(),
                'ago' => $now->sub(self::unit($value['unit'] ?? null), self::count($value)),
                'fromNow' => $now->add(self::unit($value['unit'] ?? null), self::count($value)),
                default => throw new UnsupportedException(sprintf('Relative form "%s" is not a point in time.', (string) $value['relative'])),
            };
        }

        if (! is_string($value)) {
            throw new UnsupportedException('A date value must be a string or a relative object.');
        }

        return CarbonImmutable::parse($value, $context->zone())->setTimezone($context->zone());
    }

    /**
     * Whether a value names a whole day (or span of days) rather than an instant.
     */
    public static function isDayLike(mixed $value): bool
    {
        if (is_array($value)) {
            return isset($value['relative']) ? $value['relative'] === 'today' : true;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * The comparisons an operator expands to, as [operator, value] pairs against the
     * column, for a `DateTime` (UTC instant strings) or a `Date` (wall date strings).
     *
     * @return list<array{string, string}>
     */
    public static function comparisons(Operator $operator, mixed $value, Context $context, bool $instant): array
    {
        $fmt = fn (CarbonImmutable $moment): string => $instant
            ? $moment->setTimezone('UTC')->format('Y-m-d H:i:s')
            : $moment->format('Y-m-d');

        return match ($operator) {
            Operator::On => (function () use ($value, $context, $fmt): array {
                [$start, $end] = self::period($value, $context);

                return [['>=', $fmt($start)], ['<', $fmt($end)]];
            })(),
            Operator::Between => (function () use ($value, $context, $fmt): array {
                [$start, $end] = self::period($value, $context);

                return [['>=', $fmt($start)], ['<', $fmt($end)]];
            })(),
            Operator::Before => $instant && ! self::isDayLike($value)
                ? [['<', $fmt(self::point($value, $context))]]
                : [['<', $fmt(self::period($value, $context)[0])]],
            Operator::After => $instant && ! self::isDayLike($value)
                ? [['>', $fmt(self::point($value, $context))]]
                : [['>=', $fmt(self::period($value, $context)[1])]],
            default => throw new UnsupportedException(sprintf('Operator "%s" is not a date operator.', $operator->value)),
        };
    }

    private static function now(Context $context): CarbonImmutable
    {
        return CarbonImmutable::instance(DateTimeImmutable::createFromInterface($context->now))->setTimezone($context->zone());
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function day(CarbonImmutable $moment): array
    {
        return [$moment->startOfDay(), $moment->startOfDay()->addDay()];
    }

    /**
     * [start of unit + $from units, start of unit + $to units) relative to the current unit.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function unitSpan(CarbonImmutable $now, string $unit, int $from, int $to): array
    {
        $start = self::startOf($now, $unit);

        return [$start->add($unit, $from), $start->add($unit, $to)];
    }

    private static function startOf(CarbonImmutable $now, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'month' => $now->startOfMonth(),
            'quarter' => $now->startOfQuarter(),
            default => $now->startOfYear(),
        };
    }

    private static function unit(mixed $unit): string
    {
        $singular = is_string($unit) ? rtrim($unit, 's') : '';

        return in_array($singular, ['day', 'week', 'month', 'quarter', 'year'], true)
            ? $singular
            : throw new UnsupportedException('Unknown date unit.');
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function count(array $value): int
    {
        return is_int($value['n'] ?? null) ? $value['n'] : 1;
    }
}

<?php

declare(strict_types=1);

namespace Datawell\Validation;

use Datawell\Enums\ValueShape;
use Datawell\Operators\Operator;

/**
 * Every operator implies a value shape (D09); date values also accept relative forms (D12).
 * Returns a wire-ready message, or null when the value fits.
 */
final class ValueShapes
{
    /** @var list<string> */
    public const array DATE_TYPES = ['date', 'dateTime'];

    /** @var list<string> */
    public const array UNITS = ['days', 'weeks', 'months', 'quarters', 'years'];

    public static function check(Operator $operator, string $type, mixed $value, bool $hasValue): ?string
    {
        return match ($operator->shape()) {
            ValueShape::None => $hasValue && $value !== null
                ? sprintf('Operator "%s" takes no value.', $operator->value)
                : null,
            ValueShape::List => self::checkList($operator, $type, $value),
            ValueShape::Range => self::checkRange($operator, $type, $value),
            ValueShape::Scalar => self::checkScalar($operator, $type, $value),
        };
    }

    private static function checkScalar(Operator $operator, string $type, mixed $value): ?string
    {
        if (in_array($type, self::DATE_TYPES, true)) {
            return self::isDate($value) || self::isRelativePoint($value)
                ? null
                : sprintf('Operator "%s" expects a date or a relative date such as { "relative": "ago", "n": 30, "unit": "days" }.', $operator->value);
        }

        return match ($type) {
            'number', 'money' => is_int($value) || is_float($value) ? null : sprintf('Operator "%s" expects a number.', $operator->value),
            'boolean' => is_bool($value) ? null : sprintf('Operator "%s" expects true or false.', $operator->value),
            default => is_scalar($value) ? null : sprintf('Operator "%s" expects a single value.', $operator->value),
        };
    }

    private static function checkList(Operator $operator, string $type, mixed $value): ?string
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return sprintf('Operator "%s" expects a non-empty list of values.', $operator->value);
        }

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                return sprintf('Operator "%s" expects a list of plain values.', $operator->value);
            }
        }

        return null;
    }

    private static function checkRange(Operator $operator, string $type, mixed $value): ?string
    {
        $dated = in_array($type, self::DATE_TYPES, true);

        if ($dated && self::isRelativePeriod($value)) {
            return null;
        }

        if (! is_array($value) || ! array_key_exists('from', $value) || ! array_key_exists('to', $value)) {
            return sprintf('Operator "%s" expects { from, to }.', $operator->value);
        }

        foreach (['from', 'to'] as $end) {
            $ok = $dated ? self::isDate($value[$end]) : (is_int($value[$end]) || is_float($value[$end]));

            if (! $ok) {
                return sprintf('Operator "%s" expects { from, to } %s.', $operator->value, $dated ? 'as dates' : 'as numbers');
            }
        }

        return null;
    }

    public static function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1;
    }

    /**
     * `{ "relative": "ago" | "fromNow", "n": 30, "unit": "days" }` or `{ "relative": "today" | "now" }`.
     */
    public static function isRelativePoint(mixed $value): bool
    {
        if (! is_array($value) || ! isset($value['relative'])) {
            return false;
        }

        return match ($value['relative']) {
            'today', 'now' => count($value) === 1,
            'ago', 'fromNow' => self::hasCountAndUnit($value),
            default => false,
        };
    }

    /**
     * `{ "relative": "last" | "next", "n": 30, "unit": "days" }` or `{ "relative": "this", "unit": "month" }`.
     */
    public static function isRelativePeriod(mixed $value): bool
    {
        if (! is_array($value) || ! isset($value['relative'])) {
            return false;
        }

        return match ($value['relative']) {
            'this' => count($value) === 2 && self::isUnit($value['unit'] ?? null, singular: true),
            'last', 'next' => self::hasCountAndUnit($value),
            default => false,
        };
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function hasCountAndUnit(array $value): bool
    {
        return count($value) === 3
            && is_int($value['n'] ?? null) && $value['n'] > 0
            && self::isUnit($value['unit'] ?? null);
    }

    private static function isUnit(mixed $unit, bool $singular = false): bool
    {
        if (! is_string($unit)) {
            return false;
        }

        return in_array($singular ? $unit.'s' : $unit, self::UNITS, true);
    }
}

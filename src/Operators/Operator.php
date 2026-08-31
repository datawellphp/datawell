<?php

declare(strict_types=1);

namespace Datawell\Operators;

use Datawell\Enums\ValueShape;

/**
 * The closed operator vocabulary of the query grammar (D48). Field types
 * compose their operator sets from these; nothing else may mint one.
 */
enum Operator: string
{
    case Equals = 'equals';
    case NotEquals = 'notEquals';
    case Contains = 'contains';
    case StartsWith = 'startsWith';
    case EndsWith = 'endsWith';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case Between = 'between';
    case On = 'on';
    case Before = 'before';
    case After = 'after';
    case Is = 'is';
    case In = 'in';
    case NotIn = 'notIn';
    case HasAny = 'hasAny';
    case HasAll = 'hasAll';
    case HasNone = 'hasNone';
    case IsEmpty = 'isEmpty';
    case IsNotEmpty = 'isNotEmpty';

    public function shape(): ValueShape
    {
        return match ($this) {
            self::IsEmpty, self::IsNotEmpty => ValueShape::None,
            self::In, self::NotIn, self::HasAny, self::HasAll, self::HasNone => ValueShape::List,
            self::Between => ValueShape::Range,
            default => ValueShape::Scalar,
        };
    }

    /**
     * The universal null operators (D11).
     *
     * @return list<self>
     */
    public static function nullOperators(): array
    {
        return [self::IsEmpty, self::IsNotEmpty];
    }

    /**
     * The operators every many-cardinality field carries (D09, D49).
     *
     * @return list<self>
     */
    public static function manyOperators(): array
    {
        return [self::HasAny, self::HasAll, self::HasNone, self::IsEmpty, self::IsNotEmpty];
    }
}

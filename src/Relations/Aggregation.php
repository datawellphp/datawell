<?php

declare(strict_types=1);

namespace Datawell\Relations;

use Datawell\Enums\AggregateType;

/**
 * An aggregate field's declaration (design doc §6, D55): a function over one relation
 * of the source's model — `count` of `reminders`, `max` of `reminders.sent_at` — that
 * compiles to a correlated subselect and then behaves as an ordinary scalar field.
 */
final class Aggregation
{
    public function __construct(
        public readonly AggregateType $fn,
        public readonly string $relation,
        public readonly ?string $column = null,
    ) {}

    public function describe(): string
    {
        return $this->column === null
            ? sprintf('%s of %s', $this->fn->value, $this->relation)
            : sprintf('%s of %s.%s', $this->fn->value, $this->relation, $this->column);
    }
}

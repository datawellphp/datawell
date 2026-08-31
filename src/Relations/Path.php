<?php

declare(strict_types=1);

namespace Datawell\Relations;

/**
 * A field's data path split into the relations it crosses and the column it lands on.
 * `signer.email` is relation `signer`, column `email`; a relation field's path
 * (`signer`, `document.owner`) is all relation and no column; a plain column has no
 * relation. Segment classification comes from the model (see RelationIntrospector),
 * so a path is only meaningful next to the model it was resolved against.
 */
final class Path
{
    /**
     * @param  list<string>  $relations  the relation segments, in order
     * @param  string|null  $column  the terminal column, null when the path ends on a relation
     */
    private function __construct(
        public readonly array $relations,
        public readonly ?string $column,
    ) {}

    /**
     * @param  list<string>  $relations
     */
    public static function make(array $relations, ?string $column): self
    {
        return new self($relations, $column);
    }

    public static function column(string $column): self
    {
        return new self([], $column);
    }

    public function crossesRelation(): bool
    {
        return $this->relations !== [];
    }

    public function endsOnRelation(): bool
    {
        return $this->column === null;
    }

    /**
     * The dot path of the relations crossed (`document.owner`), for with()/whereHas()/joins.
     */
    public function relation(): string
    {
        return implode('.', $this->relations);
    }

    /**
     * Continue this path through a further path declared relative to its end.
     */
    public function then(Path $inner): self
    {
        return new self([...$this->relations, ...$inner->relations], $inner->column);
    }

    public function parent(): self
    {
        return new self(array_slice($this->relations, 0, -1), null);
    }

    public function last(): string
    {
        return $this->relations[array_key_last($this->relations) ?? 0] ?? '';
    }
}

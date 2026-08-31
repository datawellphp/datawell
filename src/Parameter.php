<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;

/**
 * A named, typed, validated input (D04) — used for source parameters and action inputs alike.
 * Rules are published as instructions and enforced against what arrives.
 */
class Parameter
{
    use HasDescription;
    use HasKey;
    use HasLabel;

    protected string $type = 'text';

    protected bool $required = false;

    /** @var list<string|object> */
    protected array $rules = [];

    protected ?ValueReference $from = null;

    protected mixed $default = null;

    protected bool $hasDefault = false;

    final public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    public function required(bool $required = true): static
    {
        $this->required = $required;

        return $this;
    }

    /**
     * The wire type of the value (`text`, `number`, `boolean`, `date`, `dateTime`, `enum`, `relation`).
     */
    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Provenance (D23): values come from another source, resolved through the caller's scoped view of it.
     *
     * @param  array<string, string>  $bindings
     */
    public function from(string $sourceKey, array $bindings = []): static
    {
        $this->from = new ValueReference($sourceKey, $bindings);
        $this->type = 'relation';

        return $this;
    }

    /**
     * @param  list<string|object>  $rules  Laravel validation rules
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    public function default(mixed $default): static
    {
        $this->default = $default;
        $this->hasDefault = true;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * @return list<string|object>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function getReference(): ?ValueReference
    {
        return $this->from;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'type' => $this->type,
            'required' => $this->required,
            'rules' => array_map(
                static fn (string|object $rule): string => is_string($rule) ? $rule : $rule::class,
                $this->rules,
            ),
        ];

        if ($this->from !== null) {
            $description['from'] = $this->from->describe();
        }

        if ($this->hasDefault) {
            $description['default'] = $this->default;
        }

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        return $description;
    }
}

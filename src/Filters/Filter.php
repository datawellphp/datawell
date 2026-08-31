<?php

declare(strict_types=1);

namespace Datawell\Filters;

use Closure;
use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;
use Datawell\Concerns\HasVisibility;
use Datawell\Enums\ValueShape;
use Datawell\Fields\Field;
use Datawell\Operators\Operator;
use Datawell\Support\Key;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A declared, named filter (D06). Usually backed by a field, inheriting its type and
 * operator set (which the filter may narrow, never widen — D09). A custom filter with
 * no backing field declares its own type and operators, and applies itself however it likes.
 */
class Filter
{
    use HasDescription;
    use HasKey;
    use HasLabel;
    use HasVisibility;

    protected ?string $fieldKey = null;

    protected ?Field $field = null;

    protected ?string $type = null;

    /** @var list<Operator>|null */
    protected ?array $operators = null;

    protected ?Operator $defaultOperator = null;

    protected mixed $defaultValue = null;

    /** @var list<string|object> */
    protected array $rules = [];

    /** @var (Closure(mixed, Operator, mixed): void)|null */
    protected ?Closure $apply = null;

    final public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    /**
     * Back this filter by a declared field (defaults to the field sharing this key).
     */
    public function field(string $fieldKey): static
    {
        $this->fieldKey = $fieldKey;

        return $this;
    }

    /**
     * The value type of a custom filter (ignored when backed by a field).
     */
    public function type(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Narrow the operator set. Widening beyond the backing field's set is a lint error.
     *
     * @param  list<Operator>  $operators
     */
    public function operators(array $operators): static
    {
        $this->operators = $operators;

        return $this;
    }

    /**
     * The resting posture (D35): applied when the request omits this filter, published in the schema.
     */
    public function default(Operator $operator, mixed $value = null): static
    {
        $this->defaultOperator = $operator;
        $this->defaultValue = $value;

        return $this;
    }

    /**
     * @param  list<string|object>  $rules  extra Laravel rules for the value
     */
    public function rules(array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * How a custom filter applies itself to the query (executed in Phase 2).
     *
     * @param  Closure(mixed, Operator, mixed): void  $apply  (query, operator, value)
     */
    public function apply(Closure $apply): static
    {
        $this->apply = $apply;

        return $this;
    }

    /**
     * @internal Bound by the definition once fields are resolved.
     */
    public function backedBy(?Field $field): static
    {
        $this->field = $field;

        return $this;
    }

    /**
     * Labels inherit the backing field's (including its override), then derive from the key (D32).
     */
    public function getLabel(): string
    {
        return $this->label ?? $this->field?->getLabel() ?? Key::label($this->key);
    }

    public function getFieldKey(): string
    {
        return $this->fieldKey ?? $this->key;
    }

    public function getField(): ?Field
    {
        return $this->field;
    }

    public function hasApply(): bool
    {
        return $this->apply !== null;
    }

    public function getType(): ?string
    {
        return $this->field?->type() ?? $this->type;
    }

    /**
     * @return list<Operator>|null
     */
    public function getDeclaredOperators(): ?array
    {
        return $this->operators;
    }

    /**
     * @return list<Operator>
     */
    public function getOperators(): array
    {
        return $this->operators ?? $this->field?->operators() ?? [];
    }

    public function hasDefault(): bool
    {
        return $this->defaultOperator !== null;
    }

    /**
     * @return list<string|object>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Effective visibility: own check AND the backing field's (D17).
     */
    public function isVisibleTo(Authenticatable $user): bool
    {
        $own = is_bool($this->visible) ? $this->visible : ($this->visible)($user);

        return $own && ($this->field === null || $this->field->isVisibleTo($user));
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'type' => $this->getType(),
            'operators' => array_map(static fn (Operator $operator): string => $operator->value, $this->getOperators()),
        ];

        if ($this->defaultOperator !== null) {
            $description['default'] = ['operator' => $this->defaultOperator->value];

            if ($this->defaultOperator->shape() !== ValueShape::None) {
                $description['default']['value'] = $this->defaultValue;
            }
        }

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        return $description;
    }
}

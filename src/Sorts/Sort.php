<?php

declare(strict_types=1);

namespace Datawell\Sorts;

use Closure;
use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;
use Datawell\Concerns\HasVisibility;
use Datawell\Fields\Field;
use Datawell\Support\Key;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A declared, named sort. Backed by a sortable field, or custom with its own apply (D06).
 */
class Sort
{
    use HasDescription;
    use HasKey;
    use HasLabel;
    use HasVisibility;

    protected ?string $fieldKey = null;

    protected ?Field $field = null;

    /** @var (Closure(mixed, string): void)|null */
    protected ?Closure $apply = null;

    final public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    public function field(string $fieldKey): static
    {
        $this->fieldKey = $fieldKey;

        return $this;
    }

    /**
     * @param  Closure(mixed, string): void  $apply  (query, direction)
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

    /**
     * @return (Closure(mixed, string): void)|null
     */
    public function getApply(): ?Closure
    {
        return $this->apply;
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
        $description = ['key' => $this->key, 'label' => $this->getLabel()];

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        return $description;
    }
}

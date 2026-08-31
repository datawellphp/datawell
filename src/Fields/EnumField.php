<?php

declare(strict_types=1);

namespace Datawell\Fields;

use BackedEnum;
use Datawell\Execution\Context;
use Datawell\Fields\Concerns\HasOptions;
use Datawell\Operators\Operator;
use Datawell\Options;
use Datawell\Support\Key;
use UnitEnum;

/**
 * A closed set of values backed by a PHP enum. Values label themselves (D32):
 * a `label()` method on the enum, else the headline-cased case name.
 */
class EnumField extends Field
{
    use HasOptions;

    /** @var class-string<UnitEnum> */
    protected string $enum;

    /**
     * @param  class-string<UnitEnum>  $enum
     * @param  string|null  $from  the data path, when it differs from the key
     */
    public static function make(string $key, string $enum, ?string $from = null): static
    {
        $field = new static($key, $from);
        $field->enum = $enum;

        return $field;
    }

    /**
     * @return class-string<UnitEnum>
     */
    public function getEnum(): string
    {
        return $this->enum;
    }

    public function type(): string
    {
        return 'enum';
    }

    protected function singleOperators(): array
    {
        return [Operator::In, Operator::NotIn];
    }

    protected function defaultOptions(): ?Options
    {
        $values = [];

        foreach ($this->enum::cases() as $case) {
            $values[] = ['id' => self::idOf($case), 'label' => self::labelOf($case)];
        }

        return Options::inline($values);
    }

    public function castValue(mixed $value): mixed
    {
        return $value instanceof UnitEnum ? self::idOf($value) : $value;
    }

    /**
     * Enum values serialize as references: `{ id, label }` (D21, D32).
     */
    public function serialize(object $row, Context $context): mixed
    {
        $raw = $this->valueOf($row);

        if ($raw === null) {
            return null;
        }

        $case = $raw instanceof UnitEnum ? $raw : $this->caseFor($raw);

        return $case === null
            ? ['id' => $raw, 'label' => is_scalar($raw) ? (string) $raw : '']
            : ['id' => self::idOf($case), 'label' => self::labelOf($case)];
    }

    protected function caseFor(mixed $raw): ?UnitEnum
    {
        foreach ($this->enum::cases() as $case) {
            if (self::idOf($case) === $raw) {
                return $case;
            }
        }

        return null;
    }

    public static function idOf(UnitEnum $case): int|string
    {
        return $case instanceof BackedEnum ? $case->value : $case->name;
    }

    public static function labelOf(UnitEnum $case): string
    {
        $label = [$case, 'label'];

        if (is_callable($label)) {
            $value = $label();

            if (is_string($value)) {
                return $value;
            }
        }

        return Key::label(self::snake($case->name));
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}

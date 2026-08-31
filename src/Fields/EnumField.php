<?php

declare(strict_types=1);

namespace Datawell\Fields;

use BackedEnum;
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
            $values[] = [
                'id' => $case instanceof BackedEnum ? $case->value : $case->name,
                'label' => self::labelOf($case),
            ];
        }

        return Options::inline($values);
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

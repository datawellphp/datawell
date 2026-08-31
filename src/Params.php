<?php

declare(strict_types=1);

namespace Datawell;

/**
 * The validated parameter bag handed to authorize() and query().
 * Values are typed at validation time (Phase 2); Params::empty() serves the lint.
 */
final class Params
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(private readonly array $values) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function make(array $values): self
    {
        return new self($values);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}

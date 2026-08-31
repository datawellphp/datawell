<?php

declare(strict_types=1);

namespace Datawell\Schema;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * The per-user contract, resolved and ready to ship: every label present, hidden things absent.
 *
 * @implements Arrayable<string, mixed>
 */
final class Schema implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function toJson($options = 0): string
    {
        return (string) json_encode($this->data, $options | JSON_THROW_ON_ERROR);
    }
}

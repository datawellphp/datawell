<?php

declare(strict_types=1);

namespace Datawell\Concerns;

trait HasKey
{
    protected string $key;

    public function getKey(): string
    {
        return $this->key;
    }
}

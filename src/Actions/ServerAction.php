<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Closure;
use Datawell\Enums\Confirmation;
use Datawell\Parameter;

/**
 * An operation: handler + parameters + rules (D37). Maps one-to-one onto an AI tool
 * unless humanOnly. Executed by the runner (Phase 2+) with handle(rows, input, context) (D41).
 */
class ServerAction extends Action
{
    /** @var list<Parameter> */
    protected array $parameters = [];

    protected bool $destructive = false;

    protected bool $queued = false;

    protected ?int $chunkSize = null;

    /** @var class-string|Closure|null */
    protected string|Closure|null $handler = null;

    /**
     * @param  list<Parameter>  $parameters
     */
    public function parameters(array $parameters): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * A semantic fact imposing a confirmation floor of Always on every channel (D37).
     */
    public function destructive(bool $destructive = true): static
    {
        $this->destructive = $destructive;

        return $this;
    }

    /**
     * Always dispatch as a bus batch rather than running inline (D41).
     */
    public function queued(bool $queued = true): static
    {
        $this->queued = $queued;

        return $this;
    }

    public function chunkSize(int $size): static
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * @param  class-string|Closure  $handler  an invokable/handle() class, or a closure (rows, input, context)
     */
    public function handle(string|Closure $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * @return list<Parameter>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Aggregated Laravel rules keyed by parameter (D04).
     *
     * @return array<string, list<string|object>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->parameters as $parameter) {
            $rules[$parameter->getKey()] = $parameter->getRules();
        }

        return $rules;
    }

    public function isDestructive(): bool
    {
        return $this->destructive;
    }

    public function isQueued(): bool
    {
        return $this->queued;
    }

    public function getChunkSize(): ?int
    {
        return $this->chunkSize;
    }

    /**
     * @return class-string|Closure|null
     */
    public function getHandler(): string|Closure|null
    {
        return $this->handler;
    }

    public function kind(): string
    {
        return 'server';
    }

    protected function confirmationFloor(): Confirmation
    {
        return $this->destructive ? Confirmation::Always : Confirmation::Never;
    }

    protected function describeExtra(): array
    {
        $extra = [
            'destructive' => $this->destructive,
            'confirmation' => $this->effectiveConfirmation()->value,
        ];

        if ($this->humanOnly) {
            $extra['humanOnly'] = true;
        }

        if ($this->parameters !== []) {
            $extra['parameters'] = array_map(
                static fn (Parameter $parameter): array => $parameter->describe(),
                $this->parameters,
            );
        }

        return $extra;
    }
}

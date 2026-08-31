<?php

declare(strict_types=1);

namespace Datawell\Fields\Concerns;

use Datawell\Options;

/**
 * A field whose values come from a declared options strategy (D22): enum + relation.
 */
trait HasOptions
{
    protected ?Options $options = null;

    public function options(Options $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): ?Options
    {
        return $this->options ?? $this->defaultOptions();
    }

    protected function defaultOptions(): ?Options
    {
        return null;
    }

    /**
     * Options are published only where a picker can use them: filterable fields.
     *
     * @return array<string, mixed>
     */
    protected function describeExtra(): array
    {
        $options = $this->getOptions();

        return $this->filterable && $options !== null ? ['options' => $options->describe()] : [];
    }
}

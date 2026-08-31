<?php

declare(strict_types=1);

namespace Datawell;

use Datawell\Exceptions\DefinitionException;
use Datawell\Exceptions\SourceNotFoundException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * The front door for discovery (D02, D18): sources by key, class references resolved
 * to keys immediately, and a per-user view in which gated sources simply do not exist.
 */
class Registry
{
    /** @var array<string, DataSource> */
    protected array $sources = [];

    /** @var array<class-string<DataSource>, string> */
    protected array $keysByClass = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<DataSource>|DataSource  ...$sources
     */
    public function register(string|DataSource ...$sources): static
    {
        foreach ($sources as $source) {
            $instance = $source instanceof DataSource ? $source : $this->container->make($source);
            $key = $instance->key();

            if (isset($this->sources[$key]) && $instance::class !== $this->sources[$key]::class) {
                throw new DefinitionException(sprintf(
                    'Data source key "%s" is declared by both %s and %s.',
                    $key,
                    $this->sources[$key]::class,
                    $instance::class,
                ));
            }

            $this->sources[$key] = $instance;
            $this->keysByClass[$instance::class] = $key;
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * @throws SourceNotFoundException
     */
    public function find(string $key): DataSource
    {
        return $this->sources[$key] ?? throw SourceNotFoundException::forKey($key);
    }

    /**
     * Find a source as one user: hidden sources are indistinguishable from unknown ones (D18).
     *
     * @throws SourceNotFoundException
     */
    public function findFor(string $key, Authenticatable $user): DataSource
    {
        $source = $this->find($key);

        return $source->visible($user) ? $source : throw SourceNotFoundException::forKey($key);
    }

    /**
     * @return list<DataSource>
     */
    public function all(): array
    {
        return array_values($this->sources);
    }

    /**
     * The AI enumeration point: every source this user may know exists.
     *
     * @return list<DataSource>
     */
    public function availableFor(Authenticatable $user): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn (DataSource $source): bool => $source->visible($user),
        ));
    }

    /**
     * The sources that declare a given model (D46) — how a relation field finds its
     * target when it does not name one (D54).
     *
     * @param  class-string<Model>  $model
     * @return list<DataSource>
     */
    public function withModel(string $model): array
    {
        return array_values(array_filter(
            $this->sources,
            static fn (DataSource $source): bool => $source->model() === $model,
        ));
    }

    /**
     * Class-constant authoring sugar → key (D02).
     *
     * @param  class-string<DataSource>  $class
     */
    public function keyOf(string $class): string
    {
        return $this->keysByClass[$class] ?? throw new DefinitionException(
            sprintf('%s is not a registered data source.', $class),
        );
    }
}

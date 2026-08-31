<?php

declare(strict_types=1);

namespace Datawell\Actions;

use Closure;
use Datawell\Concerns\HasDescription;
use Datawell\Concerns\HasKey;
use Datawell\Concerns\HasLabel;
use Datawell\Concerns\HasVisibility;
use Datawell\Enums\ActionTarget;
use Datawell\Enums\Confirmation;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Three kinds, one contract (D37): every action declares targets (D40), a channel policy,
 * and two-part authorization — visible(user) for existence, authorize(user, row) per row,
 * policy-delegated via ->can() (D43).
 */
abstract class Action
{
    use HasDescription;
    use HasKey;
    use HasLabel;
    use HasVisibility;

    /** @var list<ActionTarget> */
    protected array $targets = [ActionTarget::Single];

    protected Confirmation $confirmation = Confirmation::WhenDelegated;

    protected bool $humanOnly = false;

    protected ?string $ability = null;

    /** @var (Closure(Authenticatable, mixed): bool)|null */
    protected ?Closure $authorize = null;

    final public function __construct(string $key)
    {
        $this->key = $key;
    }

    public static function make(string $key): static
    {
        return new static($key);
    }

    /**
     * The closed protocol kind published on the wire: `server`, `link`, or `client`.
     */
    abstract public function kind(): string;

    public function targets(ActionTarget ...$targets): static
    {
        $this->targets = array_values($targets);

        return $this;
    }

    /**
     * Declared confirmation policy; floors (destructive, queryScope) can raise it, never lower it.
     */
    public function confirmation(Confirmation $confirmation): static
    {
        $this->confirmation = $confirmation;

        return $this;
    }

    /**
     * Never becomes an AI tool (D37): hidden-means-absent applied to a channel.
     */
    public function humanOnly(bool $humanOnly = true): static
    {
        $this->humanOnly = $humanOnly;

        return $this;
    }

    /**
     * Per-row authorization delegated to the policy: $user->can($ability, $row) (D43).
     */
    public function can(string $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * Per-row authorization as a closure, for the rare case a policy does not fit.
     *
     * @param  Closure(Authenticatable, mixed): bool  $authorize
     */
    public function authorize(Closure $authorize): static
    {
        $this->authorize = $authorize;

        return $this;
    }

    /**
     * @return list<ActionTarget>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    public function hasTarget(ActionTarget $target): bool
    {
        return in_array($target, $this->targets, true);
    }

    public function isStandalone(): bool
    {
        return $this->targets === [ActionTarget::Standalone];
    }

    public function isHumanOnly(): bool
    {
        return $this->humanOnly;
    }

    public function getAbility(): ?string
    {
        return $this->ability;
    }

    public function getDeclaredConfirmation(): Confirmation
    {
        return $this->confirmation;
    }

    /**
     * Declared policy raised to every applicable floor (D37, D40).
     */
    public function effectiveConfirmation(): Confirmation
    {
        $confirmation = $this->confirmation;

        if ($this->hasTarget(ActionTarget::QueryScope)) {
            $confirmation = $confirmation->atLeast(Confirmation::Always);
        }

        return $confirmation->atLeast($this->confirmationFloor());
    }

    protected function confirmationFloor(): Confirmation
    {
        return Confirmation::Never;
    }

    /**
     * "May this user do this to this row" — the single per-row predicate (D43).
     */
    public function authorizes(Authenticatable $user, mixed $row): bool
    {
        if ($this->ability !== null) {
            return $user instanceof Authorizable && $user->can($this->ability, $row);
        }

        if ($this->authorize !== null) {
            return ($this->authorize)($user, $row);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $description = [
            'key' => $this->key,
            'label' => $this->getLabel(),
            'kind' => $this->kind(),
            'targets' => array_map(static fn (ActionTarget $target): string => $target->value, $this->targets),
        ];

        $description += $this->describeExtra();

        if ($this->description !== null) {
            $description['description'] = $this->description;
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    protected function describeExtra(): array
    {
        return [];
    }
}

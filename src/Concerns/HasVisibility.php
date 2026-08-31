<?php

declare(strict_types=1);

namespace Datawell\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "May this user know it exists" (D18, D31). Hidden means absent.
 */
trait HasVisibility
{
    /** @var (Closure(Authenticatable): bool)|bool */
    protected Closure|bool $visible = true;

    /**
     * @param  (Closure(Authenticatable): bool)|bool  $visible
     */
    public function visible(Closure|bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Visible only to users granted the given ability (via the Gate).
     */
    public function visibleWhen(string $ability, mixed ...$arguments): static
    {
        $this->visible = static fn (Authenticatable $user): bool => $user instanceof Authorizable
            && $user->can($ability, $arguments);

        return $this;
    }

    public function isVisibleTo(Authenticatable $user): bool
    {
        return is_bool($this->visible) ? $this->visible : ($this->visible)($user);
    }
}

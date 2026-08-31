<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Policies;

use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Models\User;

class SignaturePolicy
{
    public function remind(User $user, Signature $signature): bool
    {
        return $user->hasAbility('view-signatures');
    }

    public function void(User $user, Signature $signature): bool
    {
        return $signature->document?->owner_id === $user->id;
    }
}

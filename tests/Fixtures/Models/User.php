<?php

declare(strict_types=1);

namespace Datawell\Tests\Fixtures\Models;

use Datawell\Timezone\Concerns\ReadsTimezoneColumn;
use Datawell\Timezone\Contracts\HasTimezone;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string $role
 * @property int|null $age
 * @property bool $active
 * @property string|null $notes
 * @property string|null $timezone
 * @property list<string> $abilities
 */
class User extends Authenticatable implements HasTimezone
{
    use ReadsTimezoneColumn;

    protected $guarded = [];

    protected $casts = ['abilities' => 'array'];

    /**
     * @param  list<string>  $abilities
     */
    public static function fake(int $id, array $abilities = [], ?string $timezone = null): self
    {
        $user = new self(['name' => "User {$id}", 'abilities' => $abilities, 'timezone' => $timezone]);
        $user->id = $id;

        return $user;
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}

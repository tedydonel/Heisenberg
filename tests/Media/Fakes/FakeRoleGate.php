<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Media\Fakes;

use Heisenberg\Contracts\RoleGate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A trivially controllable RoleGate for the media-library test suite: every
 * ability defaults to allowed, and a test can flip individual abilities off to
 * exercise the authorization-failure paths (PublicFilePolicy delegates every
 * check to RoleGate::is(), never to a hardcoded role name).
 */
class FakeRoleGate implements RoleGate
{
    /** @var array<string, bool> */
    public array $abilities = [
        'media.viewAny' => true,
        'media.create' => true,
        'media.updateAny' => true,
        'media.deleteAny' => true,
    ];

    public function is(Authenticatable $user, string $tier): bool
    {
        return $this->abilities[$tier] ?? false;
    }

    public function isAny(Authenticatable $user, array $tiers): bool
    {
        foreach ($tiers as $tier) {
            if ($this->is($user, $tier)) {
                return true;
            }
        }

        return false;
    }

    public function rolesOf(Authenticatable $user): array
    {
        return [];
    }

    public function systemActor(): ?Authenticatable
    {
        return null;
    }
}

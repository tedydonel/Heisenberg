<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Answers authorization questions about a user without Heisenberg knowing how
 * roles are stored (Spatie, an enum column, Gate abilities, …). Policies and the
 * transition validator depend on this contract, never on `$user->hasRole(...)`.
 * (Blueprint §10.3, §13.3)
 */
interface RoleGate
{
    /**
     * Is the user a member of the named tier from config('heisenberg.roles')?
     */
    public function is(Authenticatable $user, string $tier): bool;

    /**
     * Is the user a member of any of the named tiers?
     *
     * @param string[] $tiers
     */
    public function isAny(Authenticatable $user, array $tiers): bool;

    /**
     * The user's raw role names — required by the transition validator (§7.3),
     * the one consumer that needs literal role strings rather than tiers.
     *
     * @return string[]
     */
    public function rolesOf(Authenticatable $user): array;

    /**
     * A user with publish authority, for unattended scheduled publication (§7.5).
     * Returns null when none can be resolved (callers fail loudly).
     */
    public function systemActor(): ?Authenticatable;
}

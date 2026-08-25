<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\RoleGate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default role gate: resolves named tiers from config('heisenberg.roles') and
 * answers membership against the host's role mechanism — Spatie's getRoleNames()
 * when present, a simple `role` property otherwise. (Blueprint §10.3, §13.3)
 */
class ConfigRoleGate implements RoleGate
{
    /**
     * @param array<string, string[]> $tiers tier name => underlying role strings
     */
    public function __construct(protected array $tiers = [])
    {
    }

    public function is(Authenticatable $user, string $tier): bool
    {
        return $this->isAny($user, [$tier]);
    }

    public function isAny(Authenticatable $user, array $tiers): bool
    {
        $allowed = $this->resolveTierRoles($tiers);

        if ($allowed === []) {
            return false;
        }

        return array_intersect($allowed, $this->rolesOf($user)) !== [];
    }

    public function rolesOf(Authenticatable $user): array
    {
        if (method_exists($user, 'getRoleNames')) {
            $names = $user->getRoleNames();

            return method_exists($names, 'all') ? array_values($names->all()) : (array) $names;
        }

        // A plain `role` string column.
        if (isset($user->role) && is_string($user->role)) {
            return [$user->role];
        }

        return [];
    }

    public function systemActor(): ?Authenticatable
    {
        // The bundled default cannot guess the host's user model. A host binds a
        // resolver (or its own RoleGate) returning a publish-authority actor (§7.5).
        return null;
    }

    /**
     * Expand tier names to the flat, de-duplicated set of underlying role strings.
     *
     * @param string[] $tiers
     * @return string[]
     */
    protected function resolveTierRoles(array $tiers): array
    {
        $roles = [];

        foreach ($tiers as $tier) {
            foreach ($this->tiers[$tier] ?? [] as $role) {
                $roles[] = $role;
            }
        }

        return array_values(array_unique($roles));
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Tag;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * view/create/update/delete authorization for the tag taxonomy (blueprint
 * §10.1 `BlogTagPolicy` — "identical" to `BlogCategoryPolicy`). See
 * CategoryPolicy's docblock for the full role-tier and LocalDevRoleGate
 * reasoning; it applies verbatim here.
 */
class TagPolicy
{
    private RoleGate $roleGate;

    public function __construct(RoleGate $roleGate)
    {
        $this->roleGate = new LocalDevRoleGate($roleGate);
    }

    public function viewAny(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'authors');
    }

    public function view(Authenticatable $user, Tag $tag): bool
    {
        return $this->roleGate->is($user, 'authors');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'authors');
    }

    public function update(Authenticatable $user, Tag $tag): bool
    {
        return $this->roleGate->is($user, 'admins');
    }

    public function delete(Authenticatable $user, Tag $tag): bool
    {
        return $this->roleGate->is($user, 'admins');
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Category;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * view/create/update/delete authorization for the category taxonomy
 * (blueprint §10.1 `BlogCategoryPolicy`; §10.2 role table row
 * "Category/Tag.viewAny/view/create" = any content role, "Category/Tag.
 * update/delete" = admin only — "identical" to BlogTagPolicy, see TagPolicy).
 *
 * Heisenberg's `authors` tier (config('heisenberg.roles')) already IS "any
 * content role" — it's a superset containing admin, editor, and author — so
 * `is($user, 'authors')` alone covers the whole first row; `admins` (admin
 * only) covers the second. Same LocalDevRoleGate wrapping as PostPolicy, for
 * the identical reason: these routes sit behind
 * config('heisenberg.middleware.editor') (unauthenticated by default), so a
 * GuestActor stand-in needs the same environment-gated local-dev bypass
 * PostPolicy's docblock documents in full.
 */
class CategoryPolicy
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

    public function view(Authenticatable $user, Category $category): bool
    {
        return $this->roleGate->is($user, 'authors');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'authors');
    }

    public function update(Authenticatable $user, Category $category): bool
    {
        return $this->roleGate->is($user, 'admins');
    }

    public function delete(Authenticatable $user, Category $category): bool
    {
        return $this->roleGate->is($user, 'admins');
    }
}

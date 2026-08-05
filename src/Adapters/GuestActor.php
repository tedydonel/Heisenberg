<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An inert, identity-less stand-in for "no authenticated user" — used ONLY so
 * a caller can hand a real (non-null) Authenticatable to code that requires
 * one, when the actual request has no logged-in user at all.
 *
 * Why this exists: `PublicFilePolicy` (and the local-dev bypass added
 * alongside {@see LocalDevRoleGate}) intentionally keep a non-nullable
 * `Authenticatable $user` parameter — that is correct, restrictive-by-default
 * behavior for a real guest. But `/editor` (the Livewire media library) is
 * unauthenticated by default (`config('heisenberg.middleware.editor')`), so a
 * normal dev session has no logged-in user to pass in at all. Rather than
 * loosening any policy/RoleGate method to accept null (which would weaken it
 * for every caller, including the real, authenticated HTTP paths),
 * call sites that need to ask "is *anonymous* access allowed right now?"
 * pass a GuestActor instead of null.
 *
 * GuestActor carries no identity and no roles: `getAuthIdentifier()` is null,
 * and it exposes neither `getRoleNames()` nor a `role` property, so
 * `ConfigRoleGate::rolesOf()` resolves it to `[]` — against the REAL RoleGate
 * it is denied exactly like any other guest. Only {@see LocalDevRoleGate}'s
 * environment-gated bypass can turn a GuestActor's check into an allow, and
 * only when `app()->environment('local')` is true.
 */
final class GuestActor implements Authenticatable
{
    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return null;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        // No-op — a guest stand-in never persists a remember-me token.
    }

    public function getRememberTokenName()
    {
        return '';
    }
}

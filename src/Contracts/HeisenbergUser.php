<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

/**
 * Marker the host's user model implements so Heisenberg services type-hint a
 * contract rather than a concrete `App\Models\User`. Heisenberg only ever needs
 * the identifier; role questions go through {@see RoleGate}, never a method on
 * the model. (Blueprint §13.1)
 */
interface HeisenbergUser
{
    /**
     * Already provided by Illuminate\Contracts\Auth\Authenticatable — declared
     * here so a type-hint of HeisenbergUser guarantees it.
     *
     * @return mixed
     */
    public function getAuthIdentifier();
}

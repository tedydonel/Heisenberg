<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\PublicFile;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * viewAny / create / update / delete authorization for public media (blueprint
 * §9). No hardcoded role names — every check delegates to the package's
 * RoleGate contract with an ability string; the host decides which of its
 * roles/permissions map to `media.viewAny|create|updateAny|deleteAny` (via
 * config('heisenberg.roles') for the bundled ConfigRoleGate, or a custom
 * RoleGate binding entirely). If a host has a global privileged-user bypass,
 * that stays outside this policy and is audited there (blueprint §9).
 */
class PublicFilePolicy
{
    public function __construct(private RoleGate $roleGate)
    {
    }

    public function viewAny(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'media.viewAny');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'media.create');
    }

    /** The original uploader OR any actor with media.updateAny. */
    public function update(Authenticatable $user, PublicFile $file): bool
    {
        if ($file->uploaded_by !== null && (int) $file->uploaded_by === (int) $user->getAuthIdentifier()) {
            return true;
        }

        return $this->roleGate->is($user, 'media.updateAny');
    }

    public function delete(Authenticatable $user, PublicFile $file): bool
    {
        return $this->roleGate->is($user, 'media.deleteAny');
    }
}

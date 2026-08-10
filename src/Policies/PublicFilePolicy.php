<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
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
 *
 * The gate is wrapped in {@see LocalDevRoleGate} (2026-08-10): the HTTP media
 * API (MediaLibraryController — what the editor's own media dialog calls) had
 * no path to the local-dev bypass the Livewire library and ThemeController
 * already used, so a fresh local install 403'd the moment the editor tried to
 * pick or upload an image. Call sites pass a {@see GuestActor} for "no user";
 * a real Authenticatable still goes straight to the configured gate in every
 * environment — the wrapper only ever decides GuestActor checks.
 */
class PublicFilePolicy
{
    private RoleGate $roleGate;

    public function __construct(RoleGate $roleGate)
    {
        $this->roleGate = new LocalDevRoleGate($roleGate);
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
        // Ownership never matches an anonymous stand-in — a GuestActor has no
        // identity to own anything with.
        if (! $user instanceof GuestActor
            && $file->uploaded_by !== null
            && (int) $file->uploaded_by === (int) $user->getAuthIdentifier()) {
            return true;
        }

        return $this->roleGate->is($user, 'media.updateAny');
    }

    public function delete(Authenticatable $user, PublicFile $file): bool
    {
        return $this->roleGate->is($user, 'media.deleteAny');
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\RoleGate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * !!! LOCAL-DEV-ONLY AUTHORIZATION BYPASS — READ BEFORE TOUCHING !!!
 *
 * Wraps another RoleGate. For a real, logged-in Authenticatable it changes
 * NOTHING — every `is()`/`isAny()` call goes straight through to the
 * wrapped/inner gate's real answer, in every environment, always. The ONLY
 * thing this class ever grants is a check made with a {@see GuestActor} — the
 * call sites' explicit stand-in for "no logged-in user at all" — and even
 * then ONLY while BOTH of the following are true, re-checked on EVERY call
 * (not just when this object is constructed):
 *
 *   1. `app()->environment('local')` is true.
 *   2. `config('heisenberg.allow_anonymous_in_local')` is true (default:
 *      true — a host sets it to false to disable the bypass even locally).
 *
 * Any other environment (testing, staging, production, or anything a host
 * forgot to name and therefore isn't literally "local") denies a GuestActor
 * outright. There is no allow-list of "safe-enough" environments and no way
 * to widen the check beyond the literal string 'local' — fail closed if
 * unsure.
 *
 * WHY THIS EXISTS: `/editor` (Livewire media library) is unauthenticated by
 * default (`config('heisenberg.middleware.editor')`, default `['web']`), and
 * `ThemeController::update()` (currently unrouted — see that class's
 * docblock) is built the same way for whenever it's remounted. Without this,
 * a fresh local install 403s the moment you try to upload media or edit the
 * site theme, because `PublicFilePolicy` (correctly) type-hints a non-nullable
 * Authenticatable and there is no logged-in user in that default setup. This
 * class is the ONE seam that turns "no user, but we're on a developer's own
 * machine" into "allowed", so the package is usable out of the box in dev —
 * while being structurally incapable of doing so anywhere else.
 *
 * USAGE: constructed directly at the two call sites that need it
 * (`Heisenberg\Livewire\MediaLibrary`, `Heisenberg\Http\Controllers\
 * ThemeController`), wrapping whatever RoleGate the host has configured
 * (`app(RoleGate::class)`). It is deliberately NOT bound as the app's
 * `RoleGate::class` singleton in `HeisenbergServiceProvider` — that binding
 * stays the bare configured adapter (see `tests/M0/PackageSkeletonTest.php`,
 * which asserts `app(RoleGate::class)` resolves to the real default adapter),
 * so nothing outside those two call sites is ever affected by this bypass.
 */
final class LocalDevRoleGate implements RoleGate
{
    public function __construct(private RoleGate $inner)
    {
    }

    /**
     * A GuestActor is the call sites' explicit marker for "there is no real
     * logged-in user" (see GuestActor's docblock) — it is NEVER a real
     * identity, so it is decided HERE, by environment + config ALONE, and
     * never forwarded to the inner/real RoleGate. This matters: a real
     * RoleGate implementation is only ever asked "does this identity have
     * this role/ability", and cannot be trusted to also correctly answer
     * "is this a real identity at all" — a naive or test-fake gate that
     * answers ability questions from config alone (ignoring $user entirely)
     * would otherwise happily "authorize" an anonymous stand-in outside any
     * local bypass. Any OTHER (real) Authenticatable always goes straight to
     * the inner gate, completely unaffected by this class, in every
     * environment.
     */
    public function is(Authenticatable $user, string $tier): bool
    {
        if ($user instanceof GuestActor) {
            return $this->bypassActive();
        }

        return $this->inner->is($user, $tier);
    }

    public function isAny(Authenticatable $user, array $tiers): bool
    {
        if ($user instanceof GuestActor) {
            return $this->bypassActive();
        }

        return $this->inner->isAny($user, $tiers);
    }

    public function rolesOf(Authenticatable $user): array
    {
        return $this->inner->rolesOf($user);
    }

    public function systemActor(): ?Authenticatable
    {
        return $this->inner->systemActor();
    }

    /**
     * Re-checked on every call — see the class docblock. Never cached, never
     * decided once at construction time, so a long-lived container binding
     * (there isn't one today, but nothing here assumes that stays true)
     * could never "lock in" a stale, permissive answer.
     */
    private function bypassActive(): bool
    {
        return app()->environment('local')
            && (bool) config('heisenberg.allow_anonymous_in_local', true);
    }
}

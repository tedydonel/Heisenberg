<?php

declare(strict_types=1);

namespace Heisenberg\Support;

use Heisenberg\Contracts\RoleGate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Where the editor's home button sends someone BACK to.
 *
 * Deliberately separate from {@see SiteUrl}, which answers a different question. `site_url` is
 * IDENTITY — the address readers see, the one that belongs in a canonical tag or a social card.
 * This is NAVIGATION — where the person editing came from — and on a real platform those differ:
 * an admin arrived from the admin dashboard, staff from theirs, and each expects the house icon
 * to take them back to their own. Tying the button to `site_url` sent everyone to the public
 * homepage instead, which is nobody's dashboard.
 *
 * `heisenberg.dashboard_url` is therefore either:
 *
 *  - a STRING, when everyone returns to the same place; or
 *  - a MAP keyed by the host's own role names, with an optional `default` for anyone unmatched.
 *
 * Resolution, first hit wins: the signed-in user's own role (their roles are read through the
 * host's {@see RoleGate}, so this works whatever their roles are stored in), then `default`,
 * then {@see SiteUrl::base()} — so an install that configures nothing keeps exactly the
 * behaviour it had, down to the inert button when no public site is configured either.
 */
final class DashboardUrl
{
    /** The URL the home button should carry for $user (the current user when omitted), or ''. */
    public static function forUser(?Authenticatable $user = null): string
    {
        $configured = config('heisenberg.dashboard_url');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        if (is_array($configured) && $configured !== []) {
            $resolved = self::fromMap($configured, $user ?? auth()->user());
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return SiteUrl::base();
    }

    /**
     * The first entry matching one of the user's roles, else `default`.
     *
     * Config order decides, not the user's role order: a host writes the map most-privileged
     * first, and someone holding both `admin` and `editor` should land on the admin dashboard
     * rather than on whichever role their user record happens to list first.
     *
     * @param array<string, mixed> $map
     */
    private static function fromMap(array $map, ?Authenticatable $user): string
    {
        if ($user !== null) {
            $roles = array_map(
                static fn ($role): string => mb_strtolower(trim((string) $role)),
                app(RoleGate::class)->rolesOf($user),
            );

            foreach ($map as $role => $url) {
                if ($role === 'default' || ! is_string($url)) {
                    continue;
                }
                if (in_array(mb_strtolower(trim((string) $role)), $roles, true) && trim($url) !== '') {
                    return rtrim(trim($url), '/');
                }
            }
        }

        $fallback = $map['default'] ?? null;

        return is_string($fallback) && trim($fallback) !== '' ? rtrim(trim($fallback), '/') : '';
    }
}

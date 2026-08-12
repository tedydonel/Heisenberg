<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Post;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * view / update / publish / delete authorization for the post aggregate
 * (blueprint §2.3.1, §7). Same house pattern as PublicFilePolicy — no
 * hardcoded roles; every check delegates to the package's RoleGate contract.
 *
 * Two RoleGate vocabularies coexist here, deliberately:
 *  - `view`/`update`/`delete` use the two broad tiers config('heisenberg.roles')
 *    ships out of the box (`authors`, `admins`), the same vocabulary an
 *    ownership check needs to fall back to when a post has no matching
 *    author. Unlike PublicFilePolicy's bespoke `media.*` ability strings
 *    (which a host must explicitly map), these already exist in the default
 *    config, so post save/load works for a host that's wired up real
 *    `authors`/`admins` roles with zero extra config.
 *  - `publish` (and the general-purpose `transitionAllowed`, used by
 *    PostController's lifecycle guard for every OTHER target status) reads
 *    the tier straight out of config('heisenberg.lifecycle.role_permissions')
 *    instead of a fixed tier name, so a host that reconfigures which tier may
 *    reach a given status never has to touch this class.
 *
 * LOCAL-DEV BYPASS: `/editor`'s save endpoints sit behind
 * config('heisenberg.middleware.editor') (default ['web']) — unauthenticated
 * by default, exactly like `/editor/media` and `PUT /builder/theme`. This
 * policy wraps its RoleGate in {@see LocalDevRoleGate} for the SAME reason
 * ThemeController does (see that class's docblock): so a `GuestActor` stand-in
 * (never a real null — Laravel's Gate refuses to even invoke a policy method
 * whose first parameter is a non-nullable Authenticatable when the actual
 * user is null) is granted every ability ONLY while
 * app()->environment('local') AND config('heisenberg.allow_anonymous_in_local')
 * are both true. A real Authenticatable is completely unaffected, in every
 * environment — LocalDevRoleGate forwards it straight to the inner gate.
 */
class PostPolicy
{
    private RoleGate $roleGate;

    public function __construct(RoleGate $roleGate)
    {
        $this->roleGate = new LocalDevRoleGate($roleGate);
    }

    /**
     * Owner OR anyone holding the `authors`/`admins` editorial tiers may load a post into the
     * editor. A `published` post is ALSO publicly viewable by anyone, including a real
     * (non-bypassed) guest — this is what makes the public comments endpoints (CommentController,
     * routes/comments.php) work at all: a blog visitor reading a published post's thread is not
     * an owner and holds no editorial tier, but must still pass this check. A `draft`/
     * `pending_review`/`scheduled`/`archived` post stays invisible to that same visitor (no
     * status-based exception below applies), so this addition never widens access to unpublished
     * content.
     */
    public function view(Authenticatable $user, Post $post): bool
    {
        if ($this->isOwner($user, $post)) {
            return true;
        }

        if ($post->status === 'published') {
            return true;
        }

        return $this->roleGate->isAny($user, ['authors', 'admins']);
    }

    /** The post's own author OR `admins` may save content changes (blocks/title/excerpt/…). */
    public function update(Authenticatable $user, Post $post): bool
    {
        if ($this->isOwner($user, $post)) {
            return true;
        }

        return $this->roleGate->is($user, 'admins');
    }

    /** Ability: may $user ever move a post into `published`? Mirrors {@see transitionAllowed()}. */
    public function publish(Authenticatable $user, Post $post): bool
    {
        return $this->transitionAllowed($user, 'published');
    }

    /** Destructive — `admins` only, no ownership exception (mirrors PublicFilePolicy::delete()). */
    public function delete(Authenticatable $user, Post $post): bool
    {
        return $this->roleGate->is($user, 'admins');
    }

    /**
     * General-purpose lifecycle gate used by PostController's transition guard
     * for EVERY target status (not only `published`): may $user move a post
     * into $status, per config('heisenberg.lifecycle.role_permissions')?
     * Fails closed when a target status has no configured tier.
     */
    public function transitionAllowed(Authenticatable $user, string $status): bool
    {
        $tier = config('heisenberg.lifecycle.role_permissions')[$status] ?? null;

        if (! is_string($tier) || $tier === '') {
            return false;
        }

        return $this->roleGate->is($user, $tier);
    }

    /** Never treats a null author_id as a match against a guest's null identifier (see PublicFilePolicy::update()). */
    private function isOwner(Authenticatable $user, Post $post): bool
    {
        return $post->author_id !== null && (int) $post->author_id === (int) $user->getAuthIdentifier();
    }
}

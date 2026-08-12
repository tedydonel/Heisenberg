<?php

declare(strict_types=1);

namespace Heisenberg\Policies;

use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Contracts\RoleGate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Moderation authorization for the native comments system (routes/comments.php's
 * `heisenberg.comments.moderation.*` group, CommentModerationController — approve/spam/trash/
 * delete/reply on ANY comment). Same house pattern as PublicFilePolicy/PostPolicy — no hardcoded
 * roles; the check delegates to the package's RoleGate contract for the `comments.moderate`
 * ability (config('heisenberg.roles'), admin/editor tiers by default).
 *
 * A single blanket ability rather than per-instance checks: unlike PublicFilePolicy::update()'s
 * "the uploader OR media.updateAny" ownership exception, a comment's author has no elevated
 * rights over their own comment once submitted — only a moderator may transition/delete/reply to
 * ANY comment, so there is nothing an instance parameter would add here.
 *
 * LOCAL-DEV BYPASS: the moderation group sits under config('heisenberg.middleware.editor')
 * (default ['web']) — unauthenticated by default, exactly like the media library and PostPolicy's
 * own /editor surfaces. This policy wraps its RoleGate in {@see LocalDevRoleGate} for the SAME
 * reason those two document in full: a `GuestActor` stand-in (never a real null — Laravel's Gate
 * refuses to even invoke a policy method whose first parameter is a non-nullable Authenticatable
 * when the actual user is null) is granted `moderate` ONLY while app()->environment('local') AND
 * config('heisenberg.allow_anonymous_in_local') are both true. A real Authenticatable is
 * completely unaffected, in every environment.
 */
class CommentPolicy
{
    private RoleGate $roleGate;

    public function __construct(RoleGate $roleGate)
    {
        $this->roleGate = new LocalDevRoleGate($roleGate);
    }

    /** Approve/spam/trash/delete/reply on any comment. */
    public function moderate(Authenticatable $user): bool
    {
        return $this->roleGate->is($user, 'comments.moderate');
    }
}

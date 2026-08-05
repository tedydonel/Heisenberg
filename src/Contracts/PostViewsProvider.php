<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Records and reports a post's public view count, decoupling the "post views"
 * post-template capability from any concrete storage. Heisenberg's own `Post`
 * row carries no view counter (no migration ships one — see
 * docs/post-template-schema.md "Post views" for why) so, unlike
 * {@see MediaResolver} or {@see AuditSink}, there is no meaningful non-trivial
 * default: the bundled adapter is a pure no-op. A host wires its own analytics
 * table, Redis counter, or third-party service behind this contract.
 */
interface PostViewsProvider
{
    /** Record one view of the given post (fire-and-forget; may no-op/dedupe/queue). */
    public function record(Post $post): void;

    /** The post's current view count, or 0 when unknown. */
    public function count(Post $post): int;
}

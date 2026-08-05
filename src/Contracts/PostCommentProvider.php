<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Supplies a post's discussion thread, decoupling the "comments" post-template
 * capability from any concrete comment system. `config('heisenberg.tables.comments')`
 * reserves a table name for a future native Heisenberg `Comment` model
 * (docs/BLUEPRINT.md §2.3.6, planned M3) — until that ships, this contract lets a
 * host plug in its own moderation/storage today, and a native adapter can be
 * bound at the same config key without any template ever changing.
 * (docs/post-template-schema.md "Comments/discussion")
 */
interface PostCommentProvider
{
    /**
     * The thread for a post, newest-or-oldest first per `$sortOrder`.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function thread(Post $post, string $sortOrder = 'newest'): array;

    /** The post's total comment count, or 0 when unknown. */
    public function count(Post $post): int;
}

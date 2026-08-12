<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Post;

/**
 * Comments-disabled adapter: an always-empty thread, and a `submit()` that never stores
 * anything. {@see NativeCommentProvider} is the default binding at
 * `heisenberg.post_template.comments_provider` since 2026-08-11; a host binds THIS class
 * instead to turn comments off entirely (same "opt out via config" pattern as
 * {@see NullMediaResolver} et al.), or its own class to integrate an external system.
 */
class NullPostCommentProvider implements PostCommentProvider
{
    public function thread(Post $post, string $sortOrder = 'newest'): array
    {
        return ['count' => 0, 'items' => []];
    }

    public function count(Post $post): int
    {
        return 0;
    }

    public function submit(Post $post, array $input): array
    {
        return ['ok' => false, 'status' => 'disabled'];
    }
}

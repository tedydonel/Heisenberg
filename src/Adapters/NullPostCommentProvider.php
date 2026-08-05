<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Post;

/**
 * Default comments adapter: an always-empty thread. A host binds a real
 * adapter at `config('heisenberg.post_template.comments_provider')` once it
 * has a comment system to read from (blueprint §13, same pattern as
 * {@see NullMediaResolver}).
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
}

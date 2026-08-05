<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\PostViewsProvider;
use Heisenberg\Models\Post;

/**
 * Default post-views adapter: records nothing, always reports 0. A host binds
 * a real adapter at `config('heisenberg.post_template.post_views_provider')`
 * once it has somewhere to count views (blueprint §13, same pattern as
 * {@see NullMediaResolver}).
 */
class NullPostViewsProvider implements PostViewsProvider
{
    public function record(Post $post): void
    {
        // intentionally empty
    }

    public function count(Post $post): int
    {
        return 0;
    }
}

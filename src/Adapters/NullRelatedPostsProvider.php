<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Contracts\RelatedPostsProvider;
use Heisenberg\Models\Post;

/**
 * Default related-posts adapter: always empty. A host binds a real adapter at
 * `config('heisenberg.post_template.related_posts_provider')` — curated (once
 * a `post_related` model ships) or computed (e.g. shared taxonomy) — see
 * docs/post-template-schema.md "Related posts".
 */
class NullRelatedPostsProvider implements RelatedPostsProvider
{
    public function related(Post $post, int $limit): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Contracts;

use Heisenberg\Models\Post;

/**
 * Supplies a post's "related posts" list, decoupling the "relatedPosts"
 * post-template capability from any concrete selection strategy. GTC's
 * `blog_post_related` self-pivot (reserved as `config('heisenberg.tables.post_related')`,
 * docs/BLUEPRINT.md §2.4) modeled hand-curated relations with no model built yet
 * (planned M3); a taxonomy-based "shared category/tags" strategy is equally
 * plausible once that ships. Rather than bake in either choice now, the
 * capability is adapter-backed — a host returns whatever it considers
 * "related" today, and a native curated-or-computed adapter can replace it
 * later at the same config key. (docs/post-template-schema.md "Related posts")
 */
interface RelatedPostsProvider
{
    /**
     * Up to `$limit` posts related to `$post`, most relevant first.
     *
     * @return list<array<string, mixed>> summaries, e.g. {id, title, url, excerpt, image}
     */
    public function related(Post $post, int $limit): array;
}

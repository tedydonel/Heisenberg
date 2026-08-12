<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo\Fixtures;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;

/**
 * A trivial custom {@see PostUrlResolver} implementation, bound via
 * `heisenberg.seo.url_resolver` in `SeoUrlResolverTest` to prove the override seam actually wins
 * everywhere (SitemapController + PreviewController's hreflang alternates) — a shape no
 * `url_template` string/map could produce (a query string, no slug at all).
 */
class StubPostUrlResolver implements PostUrlResolver
{
    public function url(Post $post): string
    {
        return 'https://stub.example/p?id=' . $post->getKey() . '&locale=' . $post->locale;
    }
}

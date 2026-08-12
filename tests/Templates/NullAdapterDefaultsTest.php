<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Templates;

use Heisenberg\Adapters\NullPostCommentProvider;
use Heisenberg\Adapters\NullPostSeoMetaProvider;
use Heisenberg\Adapters\NullPostViewsProvider;
use Heisenberg\Adapters\NullRelatedPostsProvider;
use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;

/**
 * The four adapter-backed post-template capabilities (postViews, comments,
 * relatedPosts, seoMeta — docs/post-template-schema.md) each get a bundled
 * null-object default, the same pattern as {@see \Heisenberg\Adapters\NullMediaResolver}
 * et al. These are plain classes — not yet bound in the container (see the
 * schema doc's "Wiring" section) — so they're exercised here by direct
 * instantiation, same as the rest of this delivery.
 */
class NullAdapterDefaultsTest extends TestCase
{
    private function makePost(): Post
    {
        return new Post(['title_en' => 'A post', 'locale' => 'en']);
    }

    public function test_null_post_views_provider_records_nothing_and_reports_zero(): void
    {
        $provider = new NullPostViewsProvider();
        $post = $this->makePost();

        $provider->record($post); // must not throw
        $this->assertSame(0, $provider->count($post));
    }

    public function test_null_post_comment_provider_returns_an_empty_thread(): void
    {
        $provider = new NullPostCommentProvider();
        $post = $this->makePost();

        $this->assertSame(['count' => 0, 'items' => []], $provider->thread($post));
        $this->assertSame(0, $provider->count($post));
    }

    public function test_null_post_comment_provider_refuses_to_store_a_submission(): void
    {
        $provider = new NullPostCommentProvider();
        $post = $this->makePost();

        $result = $provider->submit($post, ['author_name' => 'A', 'body' => 'Hi']);

        $this->assertSame(['ok' => false, 'status' => 'disabled'], $result);
    }

    public function test_null_related_posts_provider_returns_nothing(): void
    {
        $provider = new NullRelatedPostsProvider();

        $this->assertSame([], $provider->related($this->makePost(), 3));
    }

    public function test_null_seo_meta_provider_returns_nothing(): void
    {
        $provider = new NullPostSeoMetaProvider();

        $this->assertSame([], $provider->meta($this->makePost(), 'en'));
    }
}

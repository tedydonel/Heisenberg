<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Adapters\NativeSeoMetaProvider;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * {@see NativeSeoMetaProvider}'s meta() behavior: no row -> [], a row -> the full grown
 * return shape ({title, description, canonical, ogImage, robots, ogTitle, ogDescription,
 * jsonLd}), and locale resolution through {@see SeoMeta}'s own accessors. Mirrors
 * NativeCommentProviderTest's style for this namespace (docs/seo-system.md Wave S1).
 */
class NativeSeoMetaProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(): Post
    {
        return Post::create(['title_en' => 'A post', 'status' => 'draft']);
    }

    private function provider(): NativeSeoMetaProvider
    {
        return new NativeSeoMetaProvider();
    }

    public function test_returns_empty_array_when_the_post_has_no_seo_meta_row(): void
    {
        $post = $this->makePost();

        $this->assertSame([], $this->provider()->meta($post, 'en'));
    }

    public function test_returns_the_full_shape_when_a_row_exists(): void
    {
        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'English title',
            'meta_description_en' => 'English description',
            'canonical_url' => 'https://example.com/canonical',
            'og_image' => 'https://cdn.example/hero.jpg',
            'robots' => 'noindex, nofollow',
            'og_title_en' => 'English OG title',
            'og_description_en' => 'English OG description',
        ]);

        $meta = $this->provider()->meta($post, 'en');

        $this->assertSame([
            'title' => 'English title',
            'description' => 'English description',
            'canonical' => 'https://example.com/canonical',
            'ogImage' => 'https://cdn.example/hero.jpg',
            'robots' => 'noindex, nofollow',
            'ogTitle' => 'English OG title',
            'ogDescription' => 'English OG description',
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => 'English title',
                'description' => 'English description',
                'image' => 'https://cdn.example/hero.jpg',
            ],
        ], $meta);
    }

    public function test_resolves_the_requested_locale_with_cross_locale_fallback(): void
    {
        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'English title',
            'meta_title_fr' => 'Titre français',
            // no _fr description at all -> fr lookup falls back to the en value
            'meta_description_en' => 'English description',
        ]);

        $en = $this->provider()->meta($post, 'en');
        $fr = $this->provider()->meta($post, 'fr');

        $this->assertSame('English title', $en['title']);
        $this->assertSame('Titre français', $fr['title']);
        $this->assertSame('English description', $en['description']);
        $this->assertSame('English description', $fr['description']); // fallback
    }

    public function test_robots_defaults_to_index_follow_when_the_stored_value_is_empty(): void
    {
        $post = $this->makePost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey()]);

        // The migration's own default already fills this in, but the provider is defensive
        // about it independently -- confirm the value survives the round trip.
        $this->assertSame('index, follow', $this->provider()->meta($post, 'en')['robots']);
    }

    public function test_only_matches_the_row_belonging_to_this_post(): void
    {
        $post = $this->makePost();
        $otherPost = $this->makePost();
        SeoMeta::create([
            'able_type' => $otherPost->getMorphClass(),
            'able_id' => $otherPost->getKey(),
            'meta_title_en' => 'Belongs to the other post',
        ]);

        $this->assertSame([], $this->provider()->meta($post, 'en'));
    }
}

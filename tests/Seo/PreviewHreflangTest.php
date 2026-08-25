<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for `PreviewController::showPost()`'s `title_en`-only read fix — replaced by
 * {@see \Heisenberg\Models\Post::title()} (own-locale first, cross-locale fallback) — and for
 * `alternatesPayload()`'s hreflang emission, rewritten for the single-row translation model
 * (docs/content-translation.md §0): a translation is `_<locale>` attribute variants on the SAME
 * row now, not a published sibling row, so alternates are built from
 * {@see \Heisenberg\Services\TranslationStatusService}'s per-locale completeness signal instead
 * of a `Post::siblings()` query, with every alternate URL resolved for the SAME post (an
 * in-memory clone with only `locale` swapped) through {@see \Heisenberg\Contracts\PostUrlResolver}.
 */
class PreviewHreflangTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_fr_only_post_previews_with_its_fr_title_instead_of_untitled(): void
    {
        $post = Post::create([
            'locale' => 'fr',
            'title_fr' => 'Un titre en français',
            'title_en' => '',
            'status' => 'published',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('Un titre en français', $html);
        $this->assertStringNotContainsString('Untitled post', $html);
    }

    public function test_a_post_with_content_in_only_its_home_locale_emits_no_hreflang_links(): void
    {
        $post = Post::create(['title_en' => 'Solo post', 'status' => 'published', 'locale' => 'en']);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="alternate"', $html);
    }

    public function test_a_translated_post_emits_one_alternate_per_locale_with_content_plus_x_default(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');
        $this->app['config']->set('heisenberg.default_locale', 'en');

        $post = Post::create([
            'title_en' => 'Hello', 'title_fr' => 'Bonjour', 'slug' => 'hello', 'status' => 'published', 'locale' => 'en',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="https://example.com/en/blog/hello" />',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="fr" href="https://example.com/fr/blog/hello" />',
            $html
        );
        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="x-default" href="https://example.com/en/blog/hello" />',
            $html
        );
    }

    public function test_a_translated_posts_own_locale_title_only_still_counts_as_having_content(): void
    {
        // The post's OWN locale is fr (no explicit title_en) — Post::title() falls back for
        // DISPLAY, but TranslationStatusService's per-locale `title` flag (which alternates are
        // gated on) reads each locale's OWN column directly, so an fr-authored, en-translated
        // post still emits both alternates.
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');

        $post = Post::create([
            'title_fr' => 'Bonjour', 'title_en' => 'Hello', 'slug' => 'bonjour', 'status' => 'published', 'locale' => 'fr',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('hreflang="fr"', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
    }
}

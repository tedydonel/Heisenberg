<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for the two `PreviewController::showPost()` fixes docs/seo-system.md §5 /
 * docs/content-translation.md §7 call for: the `title_en`-only read is replaced by
 * {@see \Heisenberg\Models\Post::title()} (own-locale first, cross-locale fallback), and the page
 * now emits `<link rel="alternate" hreflang>` for its published translation siblings (+
 * `x-default`), via the SAME {@see \Heisenberg\Contracts\PostUrlResolver} the sitemap uses.
 */
class PreviewHreflangTest extends TestCase
{
    use RefreshDatabase;

    // ── title() fallback fix ────────────────────────────────────────────

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

    // ── hreflang alternates ──────────────────────────────────────────────

    public function test_a_solo_post_emits_no_hreflang_links(): void
    {
        $post = Post::create(['title_en' => 'Solo post', 'status' => 'published', 'locale' => 'en']);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="alternate"', $html);
    }

    public function test_published_siblings_emit_hreflang_links_and_x_default(): void
    {
        $en = Post::create(['title_en' => 'English title', 'status' => 'published', 'locale' => 'en']);
        Post::create([
            'translation_group_id' => $en->translation_group_id,
            'locale' => 'fr',
            'title_en' => '',
            'title_fr' => 'Titre français',
            'status' => 'published',
        ]);

        $html = (string) $this->get("/editor/{$en->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="fr"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
    }

    public function test_hreflang_urls_use_the_configured_template_when_set(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');

        $en = Post::create(['title_en' => 'English title', 'status' => 'published', 'locale' => 'en', 'slug' => 'english-title']);
        Post::create([
            'translation_group_id' => $en->translation_group_id,
            'locale' => 'fr',
            'title_en' => '',
            'title_fr' => 'Titre français',
            'status' => 'published',
            'slug' => 'titre-francais',
        ]);

        $html = (string) $this->get("/editor/{$en->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('href="https://example.com/en/blog/english-title"', $html);
        $this->assertStringContainsString('href="https://example.com/fr/blog/titre-francais"', $html);
    }

    public function test_a_draft_sibling_does_not_produce_a_hreflang_link(): void
    {
        $en = Post::create(['title_en' => 'English title', 'status' => 'published', 'locale' => 'en']);
        Post::create([
            'translation_group_id' => $en->translation_group_id,
            'locale' => 'fr',
            'title_en' => '',
            'title_fr' => 'Brouillon',
            'status' => 'draft',
        ]);

        $html = (string) $this->get("/editor/{$en->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="alternate"', $html);
    }
}

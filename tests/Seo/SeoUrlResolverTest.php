<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Services\SeoUrlResolver;
use Heisenberg\Tests\Seo\Fixtures\StubPostUrlResolver;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;

/**
 * Coverage for the owner's per-locale-URL request (docs/seo-system.md §5): `url_template` as
 * either a string (back-compat) or a per-locale map, the map's fallback chain, and the
 * `PostUrlResolver` override seam (`heisenberg.seo.url_resolver`) winning on both the sitemap and
 * the preview page's hreflang alternates. {@see SeoUrlResolver} is exercised directly here (unit
 * level); {@see SitemapTest} / {@see PreviewHreflangTest} already pin the string-template shape at
 * the HTTP layer and are left untouched.
 */
class SeoUrlResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge(
            ['title_en' => 'A post', 'status' => 'published', 'locale' => 'en'],
            $overrides
        ));
    }

    // ── string template: back-compat ────────────────────────────────────

    public function test_string_template_still_substitutes_locale_and_slug_for_every_locale(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');
        $en = $this->makePost(['locale' => 'en', 'slug' => 'hello']);
        $fr = $this->makePost(['locale' => 'fr', 'slug' => 'bonjour']);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame('https://example.com/en/blog/hello', $resolver->url($en));
        $this->assertSame('https://example.com/fr/blog/bonjour', $resolver->url($fr));
    }

    // ── map form ──────────────────────────────────────────────────────────

    public function test_map_template_picks_the_entry_for_the_posts_own_locale(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', [
            'en' => 'https://example.com/blog/{slug}',
            'fr' => 'https://example.com/fr/blog/{slug}',
        ]);
        $en = $this->makePost(['locale' => 'en', 'slug' => 'hello-world']);
        $fr = $this->makePost(['locale' => 'fr', 'slug' => 'hello-world']);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame('https://example.com/blog/hello-world', $resolver->url($en));
        $this->assertSame('https://example.com/fr/blog/hello-world', $resolver->url($fr));
    }

    public function test_unprefixed_default_locale_and_prefixed_other_locale_the_owners_exact_case(): void
    {
        $this->app['config']->set('heisenberg.default_locale', 'en');
        $this->app['config']->set('heisenberg.seo.url_template', [
            'en' => 'https://example.com/blog/{slug}',
            'fr' => 'https://example.com/fr/blog/{slug}',
        ]);
        $en = $this->makePost(['locale' => 'en', 'slug' => 'my-post']);
        $fr = $this->makePost([
            'locale' => 'fr',
            'slug' => 'my-post',
            'translation_group_id' => $en->translation_group_id,
        ]);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame('https://example.com/blog/my-post', $resolver->url($en));
        $this->assertSame('https://example.com/fr/blog/my-post', $resolver->url($fr));
        // Exactly those two URLs -- no accidental third shape (e.g. a stray {locale} left
        // unsubstituted, or the fallback preview route leaking through).
        $this->assertNotSame($resolver->url($en), $resolver->url($fr));
    }

    public function test_a_map_entry_may_itself_still_use_the_locale_placeholder(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', [
            'de' => 'https://example.de/{locale}/blog/{slug}',
        ]);
        $post = $this->makePost(['locale' => 'de', 'slug' => 'hallo']);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame('https://example.de/de/blog/hallo', $resolver->url($post));
    }

    // ── map fallback chain: locale -> '*' -> default_locale -> preview route ───

    public function test_missing_locale_entry_falls_back_to_the_star_catch_all(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', [
            'en' => 'https://example.com/blog/{slug}',
            '*' => 'https://example.com/{locale}/blog/{slug}',
        ]);
        $post = $this->makePost(['locale' => 'de', 'slug' => 'hallo']);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame('https://example.com/de/blog/hallo', $resolver->url($post));
    }

    public function test_missing_locale_and_star_falls_back_to_the_default_locale_entry(): void
    {
        $this->app['config']->set('heisenberg.default_locale', 'en');
        $this->app['config']->set('heisenberg.seo.url_template', [
            'en' => 'https://example.com/blog/{slug}',
        ]);
        $post = $this->makePost(['locale' => 'de', 'slug' => 'hallo']);

        $resolver = $this->app->make(PostUrlResolver::class);

        // No 'de' entry, no '*' entry -- falls back to the 'en' (default_locale) entry, still
        // substituting {locale} with the post's OWN locale ('de'), not 'en'.
        $this->assertSame('https://example.com/blog/hallo', $resolver->url($post));
    }

    public function test_no_matching_entry_at_all_falls_back_to_the_preview_route(): void
    {
        $this->app['config']->set('heisenberg.default_locale', 'en');
        $this->app['config']->set('heisenberg.seo.url_template', [
            'fr' => 'https://example.com/fr/blog/{slug}',
        ]);
        $post = $this->makePost(['locale' => 'de', 'slug' => 'hallo']);

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame(
            route('heisenberg.editor.preview.post', ['post' => $post->getKey()]),
            $resolver->url($post)
        );
    }

    // ── dev-default preview-route fallback when nothing is configured ──────

    public function test_nothing_configured_falls_back_to_the_dev_default_preview_route(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', null);
        $post = $this->makePost();

        $resolver = $this->app->make(PostUrlResolver::class);

        $this->assertSame(
            route('heisenberg.editor.preview.post', ['post' => $post->getKey()]),
            $resolver->url($post)
        );
    }

    // ── the override seam: a custom bound PostUrlResolver wins EVERYWHERE ──

    public function test_a_custom_bound_resolver_wins_on_the_sitemap(): void
    {
        $this->app['config']->set('heisenberg.seo.url_resolver', StubPostUrlResolver::class);
        // A string url_template is also set, to prove the resolver binding takes priority over
        // the template entirely rather than merely being consulted as a fallback.
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');
        $post = $this->makePost(['locale' => 'en']);

        $content = $this->get('/sitemap.xml')->assertOk()->getContent();
        $xml = simplexml_load_string((string) $content);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locs = $xml->xpath('//s:url/s:loc');

        $this->assertCount(1, $locs);
        $this->assertSame('https://stub.example/p?id=' . $post->getKey() . '&locale=en', (string) $locs[0]);
    }

    // `test_a_custom_bound_resolver_wins_on_the_preview_pages_hreflang_alternates` was removed
    // here (docs/content-translation.md §0.1): it asserted hreflang links built from published
    // SIBLING ROWS, which `PreviewController::alternatesPayload()` no longer produces at all
    // under the single-row model (`Post::siblings()` is gone) — see that method's own docblock.
    // The resolver-override behavior itself is still pinned by
    // `test_a_custom_bound_resolver_wins_on_the_sitemap` above.
}

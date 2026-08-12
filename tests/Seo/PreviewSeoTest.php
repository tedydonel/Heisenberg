<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for the preview page's SEO head emission (PreviewController::showPost() ->
 * seoPayload(), preview.blade.php's head @php block) now that NativeSeoMetaProvider is the
 * default binding (docs/seo-system.md Wave S1). Mirrors PreviewCommentsTest's style for this
 * namespace: pins what actually reaches the rendered `<head>`, not the provider/model directly
 * (that's SeoMetaModelTest / NativeSeoMetaProviderTest's job).
 */
class PreviewSeoTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(): Post
    {
        return Post::create(['title_en' => 'A Post With SEO', 'status' => 'published', 'locale' => 'en']);
    }

    public function test_a_post_with_seo_meta_renders_description_canonical_robots_and_og_tags(): void
    {
        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'Custom SEO title',
            'meta_description_en' => 'Custom SEO description',
            'canonical_url' => 'https://example.com/a-post-with-seo',
            'robots' => 'noindex, nofollow',
            'og_title_en' => 'Custom OG title',
            'og_description_en' => 'Custom OG description',
            'og_image' => 'https://cdn.example/hero.jpg',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('<title>Custom SEO title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Custom SEO description" />', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow" />', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/a-post-with-seo" />', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Custom OG title" />', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Custom OG description" />', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example/hero.jpg" />', $html);
    }

    public function test_a_post_with_seo_meta_emits_a_json_ld_script_block(): void
    {
        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'Custom SEO title',
            'meta_description_en' => 'Custom SEO description',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        // JSON_UNESCAPED_SLASHES -- no backslash-escaping of the URL.
        $this->assertStringContainsString('"@context":"https://schema.org"', $html);
        $this->assertStringContainsString('"headline":"Custom SEO title"', $html);
    }

    public function test_a_post_without_seo_meta_falls_back_cleanly_with_no_empty_tags(): void
    {
        $post = $this->makePost();
        // Deliberately no SeoMeta row -- NativeSeoMetaProvider::meta() returns [].

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        // Title falls back to the post's own title (unchanged head logic).
        $this->assertStringContainsString('<title>A Post With SEO</title>', $html);
        // No empty/placeholder meta tags for fields the provider had nothing for.
        $this->assertStringNotContainsString('<meta name="description" content="" />', $html);
        $this->assertStringNotContainsString('<meta name="robots"', $html);
        $this->assertStringNotContainsString('<link rel="canonical"', $html);
        $this->assertStringNotContainsString('<script type="application/ld+json">', $html);
        // og:title still emits, falling back to the resolved page title (existing behavior).
        $this->assertStringContainsString('<meta property="og:title" content="A Post With SEO" />', $html);
    }

    public function test_the_null_seo_meta_provider_binding_also_falls_back_cleanly(): void
    {
        $this->app['config']->set('heisenberg.post_template.seo_meta_provider', \Heisenberg\Adapters\NullPostSeoMetaProvider::class);
        $this->app->forgetInstance(\Heisenberg\Contracts\PostSeoMetaProvider::class);

        $post = $this->makePost();
        SeoMeta::create([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
            'meta_title_en' => 'Should never surface',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('Should never surface', $html);
        $this->assertStringContainsString('<title>A Post With SEO</title>', $html);
    }
}

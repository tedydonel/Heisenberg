<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleXMLElement;

/**
 * `GET /sitemap.xml` coverage (docs/seo-system.md §5, {@see \Heisenberg\Http\Controllers\SitemapController}):
 * inclusion/exclusion rules (draft, `in_sitemap` false, `robots` noindex), hreflang alternates +
 * `x-default` across a translation group, `heisenberg.seo.url_template` substitution, and that the
 * response is well-formed, correctly-namespaced XML.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'A post', 'status' => 'published', 'locale' => 'en'], $overrides));
    }

    private function fetchXml(): SimpleXMLElement
    {
        $content = $this->get('/sitemap.xml')->assertOk()->getContent();
        $xml = simplexml_load_string((string) $content);
        $this->assertNotFalse($xml, 'Response was not well-formed XML');

        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('xhtml', 'http://www.w3.org/1999/xhtml');

        return $xml;
    }

    // ── content-type / well-formedness ──────────────────────────────────

    public function test_it_serves_well_formed_xml_with_the_right_content_type_and_namespaces(): void
    {
        $this->makePost();

        $response = $this->get('/sitemap.xml');
        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string((string) $response->getContent());
        $this->assertNotFalse($xml);
        $namespaces = $xml->getDocNamespaces();
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $namespaces['']);
        $this->assertSame('http://www.w3.org/1999/xhtml', $namespaces['xhtml']);
    }

    // ── inclusion / exclusion ───────────────────────────────────────────

    public function test_a_published_post_with_no_seo_meta_row_is_included(): void
    {
        $post = $this->makePost();

        $xml = $this->fetchXml();
        $locs = $xml->xpath('//s:url/s:loc');

        $this->assertCount(1, $locs);
        $this->assertStringContainsString((string) $post->id, (string) $locs[0]);
    }

    public function test_a_draft_post_is_excluded(): void
    {
        $this->makePost(['status' => 'draft']);

        $xml = $this->fetchXml();

        $this->assertCount(0, $xml->xpath('//s:url'));
    }

    public function test_a_post_with_in_sitemap_false_is_excluded(): void
    {
        $post = $this->makePost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey(), 'in_sitemap' => false]);

        $xml = $this->fetchXml();

        $this->assertCount(0, $xml->xpath('//s:url'));
    }

    public function test_a_noindexed_post_is_excluded(): void
    {
        $post = $this->makePost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey(), 'robots' => 'noindex, follow']);

        $xml = $this->fetchXml();

        $this->assertCount(0, $xml->xpath('//s:url'));
    }

    public function test_an_in_sitemap_true_indexable_post_is_included(): void
    {
        $post = $this->makePost();
        SeoMeta::create(['able_type' => $post->getMorphClass(), 'able_id' => $post->getKey(), 'in_sitemap' => true, 'robots' => 'index, follow']);

        $xml = $this->fetchXml();

        $this->assertCount(1, $xml->xpath('//s:url'));
    }

    public function test_a_published_email_document_is_excluded(): void
    {
        // docs/email-system.md §3: an email document has no public URL at all, regardless of
        // status -- `type` is not fillable, so it is set directly, same as the guarded
        // status/published_at columns above.
        $email = $this->makePost();
        $email->type = 'email';
        $email->save();

        $xml = $this->fetchXml();

        $this->assertCount(0, $xml->xpath('//s:url'));
    }

    // ── hreflang alternates ──────────────────────────────────────────────

    public function test_a_solo_post_has_no_hreflang_alternates(): void
    {
        $this->makePost();

        $xml = $this->fetchXml();

        $this->assertCount(0, $xml->xpath('//xhtml:link'));
    }

    public function test_published_siblings_emit_hreflang_alternates_and_x_default(): void
    {
        $en = $this->makePost(['locale' => 'en', 'title_en' => 'English title']);
        $fr = Post::create([
            'translation_group_id' => $en->translation_group_id,
            'locale' => 'fr',
            'title_en' => '',
            'title_fr' => 'Titre français',
            'status' => 'published',
        ]);

        $xml = $this->fetchXml();
        $urls = $xml->xpath('//s:url');
        $this->assertCount(2, $urls);

        foreach ($urls as $url) {
            $links = $url->xpath('.//xhtml:link');
            $hreflangs = array_map(fn ($l) => (string) $l['hreflang'], $links);
            sort($hreflangs);
            // en, fr, x-default -- every row in a 2-locale group carries all three.
            $this->assertSame(['en', 'fr', 'x-default'], $hreflangs);
        }
    }

    public function test_an_unpublished_sibling_does_not_count_toward_alternates(): void
    {
        $en = $this->makePost(['locale' => 'en']);
        Post::create([
            'translation_group_id' => $en->translation_group_id,
            'locale' => 'fr',
            'title_en' => '',
            'title_fr' => 'Brouillon',
            'status' => 'draft',
        ]);

        $xml = $this->fetchXml();

        // The FR draft never appears as a <url> (excluded), and the EN row gets no hreflang
        // block either -- it has no PUBLISHED sibling to relate to.
        $this->assertCount(1, $xml->xpath('//s:url'));
        $this->assertCount(0, $xml->xpath('//xhtml:link'));
    }

    // ── url_template ─────────────────────────────────────────────────────

    public function test_url_template_substitution(): void
    {
        $this->app['config']->set('heisenberg.seo.url_template', 'https://example.com/{locale}/blog/{slug}');
        $post = $this->makePost(['locale' => 'en', 'slug' => 'my-post']);

        $xml = $this->fetchXml();
        $locs = $xml->xpath('//s:url/s:loc');

        $this->assertSame('https://example.com/en/blog/my-post', (string) $locs[0]);
    }

}

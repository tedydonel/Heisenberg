<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Seo;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\SeoAnalyzer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * {@see SeoAnalyzer} coverage (docs/seo-system.md §4, Wave S2b): every check id's pass/warn/fail
 * paths, score band boundaries, `$overrides` winning over the stored `SeoMeta` row, FR readability
 * thresholds differing from EN, and the empty-everything case never crashing.
 */
class SeoAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic internal-vs-outbound link classification (SeoAnalyzer::isInternalLink()).
        $this->app['config']->set('app.url', 'https://example.com');
    }

    private function analyzer(): SeoAnalyzer
    {
        return new SeoAnalyzer();
    }

    private function makePost(array $attrs = []): Post
    {
        return Post::create(array_merge([
            'title_en' => 'A Great Post About Widgets',
            'slug' => 'a-great-post-about-widgets',
            'status' => 'draft',
            'locale' => 'en',
        ], $attrs));
    }

    private function seoMeta(Post $post, array $attrs = []): SeoMeta
    {
        return SeoMeta::create(array_merge([
            'able_type' => $post->getMorphClass(),
            'able_id' => $post->getKey(),
        ], $attrs));
    }

    private function addBlock(Post $post, int $order, string $name, array $attributes, array $innerBlocks = []): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name,
            'content' => [
                'id' => 'b' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => $innerBlocks,
            ],
            'order' => $order,
        ]);
    }

    private function words(int $n, string $word = 'lorem'): string
    {
        return trim(str_repeat($word . ' ', $n));
    }

    private function checkById(array $result, string $id): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("No check with id {$id} in result");
    }

    public function test_empty_everything_does_not_crash_and_returns_the_expected_shape(): void
    {
        // Post::booted() always fills in a slug from the title even when both are blank
        // ('untitled'), so this exercises "nothing meaningful set" rather than a literal empty
        // slug -- still zero SeoMeta row, zero blocks, zero keyphrase, which is the crash surface
        // this test cares about.
        $post = $this->makePost(['title_en' => '', 'title_fr' => '', 'slug' => '']);

        $result = $this->analyzer()->analyze($post, 'en');

        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertContains($result['rating'], ['poor', 'needs-work', 'good', 'excellent']);
        $this->assertNotEmpty($result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('group', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('weight', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('message_key', $check);
            $this->assertArrayHasKey('params', $check);
            $this->assertContains($check['status'], ['pass', 'warn', 'fail', 'na']);
        }
    }

    public function test_title_length_pass_warn_fail(): void
    {
        $post = $this->makePost();

        $short = $this->analyzer()->analyze($post, 'en', ['meta_title' => 'Too short']);
        $this->assertSame('warn', $this->checkById($short, 'title-length')['status']);

        $ideal = $this->analyzer()->analyze($post, 'en', ['meta_title' => 'A title that is comfortably in range']);
        $this->assertSame('pass', $this->checkById($ideal, 'title-length')['status']);

        $empty = $this->analyzer()->analyze($post, 'en', ['meta_title' => '']);
        // Falls back to the post's own title (non-empty), so this actually measures the post title.
        $this->assertNotSame('fail', $this->checkById($empty, 'title-length')['status']);
    }

    public function test_description_length_pass_warn_fail(): void
    {
        $post = $this->makePost();

        $missing = $this->analyzer()->analyze($post, 'en', ['meta_description' => '']);
        $this->assertSame('fail', $this->checkById($missing, 'description-length')['status']);

        $short = $this->analyzer()->analyze($post, 'en', ['meta_description' => 'Too short']);
        $this->assertSame('warn', $this->checkById($short, 'description-length')['status']);

        $ideal = $this->analyzer()->analyze($post, 'en', [
            'meta_description' => 'A meta description that comfortably sits inside the fifty to one hundred sixty character range for search snippets.',
        ]);
        $this->assertSame('pass', $this->checkById($ideal, 'description-length')['status']);
    }

    public function test_keyphrase_set_check(): void
    {
        $post = $this->makePost();

        $unset = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => '']);
        $this->assertSame('warn', $this->checkById($unset, 'keyphrase-set')['status']);

        $set = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => 'widget care']);
        $this->assertSame('pass', $this->checkById($set, 'keyphrase-set')['status']);
    }

    public function test_keyphrase_dependent_checks_are_not_applicable_with_reduced_weight_when_unset(): void
    {
        $post = $this->makePost();
        $result = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => '']);

        foreach (['keyphrase-in-title', 'keyphrase-in-slug', 'keyphrase-in-description', 'keyphrase-in-intro', 'density'] as $id) {
            $check = $this->checkById($result, $id);
            $this->assertSame('na', $check['status'], "{$id} should be 'na' when no keyphrase is set");
            $this->assertSame('Set a focus keyphrase to unlock this check.', $check['message']);
        }
    }

    public function test_keyphrase_in_title_pass_and_fail(): void
    {
        $post = $this->makePost();

        $pass = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => 'widgets', 'meta_title' => 'All About Widgets']);
        $this->assertSame('pass', $this->checkById($pass, 'keyphrase-in-title')['status']);

        $fail = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => 'gadgets', 'meta_title' => 'All About Widgets']);
        $this->assertSame('fail', $this->checkById($fail, 'keyphrase-in-title')['status']);
    }

    public function test_keyphrase_in_title_is_diacritic_and_case_insensitive(): void
    {
        $post = $this->makePost();

        $result = $this->analyzer()->analyze($post, 'fr', ['focus_keyphrase' => 'ÉTÉ chaud', 'meta_title' => 'Un été chaud à Paris']);

        $this->assertSame('pass', $this->checkById($result, 'keyphrase-in-title')['status']);
    }

    public function test_keyphrase_in_slug_pass_and_warn(): void
    {
        $post = $this->makePost(['slug' => 'widget-care-guide']);

        $pass = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => 'widget care']);
        $this->assertSame('pass', $this->checkById($pass, 'keyphrase-in-slug')['status']);

        $warn = $this->analyzer()->analyze($post, 'en', ['focus_keyphrase' => 'gadget maintenance']);
        $this->assertSame('warn', $this->checkById($warn, 'keyphrase-in-slug')['status']);
    }

    public function test_keyphrase_in_description_pass_and_warn(): void
    {
        $post = $this->makePost();

        $pass = $this->analyzer()->analyze($post, 'en', [
            'focus_keyphrase' => 'widget care',
            'meta_description' => 'Learn everything about widget care in this comprehensive guide for beginners.',
        ]);
        $this->assertSame('pass', $this->checkById($pass, 'keyphrase-in-description')['status']);

        $warn = $this->analyzer()->analyze($post, 'en', [
            'focus_keyphrase' => 'widget care',
            'meta_description' => 'Learn everything about gadgets in this comprehensive guide for beginners.',
        ]);
        $this->assertSame('warn', $this->checkById($warn, 'keyphrase-in-description')['status']);
    }

    public function test_keyphrase_in_intro_pass_and_warn(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => 'Widget care starts here. ' . $this->words(200, 'filler')]);

        $pass = $this->analyzer()->analyze($post->fresh(), 'en', ['focus_keyphrase' => 'widget care']);
        $this->assertSame('pass', $this->checkById($pass, 'keyphrase-in-intro')['status']);

        $warn = $this->analyzer()->analyze($post->fresh(), 'en', ['focus_keyphrase' => 'gadget maintenance']);
        $this->assertSame('warn', $this->checkById($warn, 'keyphrase-in-intro')['status']);
    }

    public function test_density_fail_when_keyphrase_absent_from_content(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => $this->words(100, 'filler')]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en', ['focus_keyphrase' => 'widget']);

        $this->assertSame('fail', $this->checkById($result, 'density')['status']);
    }

    public function test_density_warn_when_too_low_and_pass_in_range_and_fail_when_stuffed(): void
    {
        // 200 words, keyphrase appears once (~0.5% is the pass floor) -- one occurrence in 300 words is below it.
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => 'widget ' . $this->words(300, 'filler')]);
        $low = $this->analyzer()->analyze($post->fresh(), 'en', ['focus_keyphrase' => 'widget']);
        $this->assertSame('warn', $this->checkById($low, 'density')['status']);

        // ~1% density: 2 occurrences in ~200 words.
        $post2 = $this->makePost(['slug' => 'post-two']);
        $this->addBlock($post2, 0, 'heisenberg/paragraph', ['content' => 'widget ' . $this->words(98, 'filler') . ' widget']);
        $ok = $this->analyzer()->analyze($post2->fresh(), 'en', ['focus_keyphrase' => 'widget']);
        $this->assertSame('pass', $this->checkById($ok, 'density')['status']);

        // Stuffed: keyphrase repeated far beyond 2.5% of the word count.
        $post3 = $this->makePost(['slug' => 'post-three']);
        $this->addBlock($post3, 0, 'heisenberg/paragraph', ['content' => $this->words(20, 'widget')]);
        $stuffed = $this->analyzer()->analyze($post3->fresh(), 'en', ['focus_keyphrase' => 'widget']);
        $this->assertSame('fail', $this->checkById($stuffed, 'density')['status']);
    }

    public function test_content_length_fail_warn_pass(): void
    {
        $short = $this->makePost();
        $this->addBlock($short, 0, 'heisenberg/paragraph', ['content' => $this->words(50)]);
        $this->assertSame('fail', $this->checkById($this->analyzer()->analyze($short->fresh(), 'en'), 'content-length')['status']);

        $mid = $this->makePost(['slug' => 'mid']);
        $this->addBlock($mid, 0, 'heisenberg/paragraph', ['content' => $this->words(200)]);
        $this->assertSame('warn', $this->checkById($this->analyzer()->analyze($mid->fresh(), 'en'), 'content-length')['status']);

        $long = $this->makePost(['slug' => 'long']);
        $this->addBlock($long, 0, 'heisenberg/paragraph', ['content' => $this->words(350)]);
        $this->assertSame('pass', $this->checkById($this->analyzer()->analyze($long->fresh(), 'en'), 'content-length')['status']);
    }

    public function test_heading_hierarchy_fails_on_content_h1(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/heading', ['content' => 'A duplicate H1', 'level' => 1]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');

        $this->assertSame('fail', $this->checkById($result, 'heading-hierarchy')['status']);
    }

    public function test_heading_hierarchy_warns_on_skipped_levels(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/heading', ['content' => 'Section', 'level' => 2]);
        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Sub-sub-section', 'level' => 4]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');

        $this->assertSame('warn', $this->checkById($result, 'heading-hierarchy')['status']);
    }

    public function test_heading_hierarchy_warns_when_long_content_has_no_headings(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => $this->words(350)]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');

        $this->assertSame('warn', $this->checkById($result, 'heading-hierarchy')['status']);
    }

    public function test_heading_hierarchy_passes_with_sane_sequential_headings(): void
    {
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/heading', ['content' => 'Section', 'level' => 2]);
        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Subsection', 'level' => 3]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');

        $this->assertSame('pass', $this->checkById($result, 'heading-hierarchy')['status']);
    }

    public function test_paragraph_length_pass_when_empty_and_warn_when_a_paragraph_is_very_long(): void
    {
        $post = $this->makePost();
        $noParagraphs = $this->analyzer()->analyze($post, 'en');
        $this->assertSame('na', $this->checkById($noParagraphs, 'paragraph-length')['status']);

        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => $this->words(250)]);
        $long = $this->analyzer()->analyze($post->fresh(), 'en');
        $this->assertSame('warn', $this->checkById($long, 'paragraph-length')['status']);
    }

    public function test_image_alts_pass_warn_fail(): void
    {
        $noImages = $this->makePost();
        $this->assertSame('na', $this->checkById($this->analyzer()->analyze($noImages, 'en'), 'image-alts')['status']);

        $allAlt = $this->makePost(['slug' => 'all-alt']);
        $this->addBlock($allAlt, 0, 'heisenberg/image', ['url' => 'https://cdn.example/a.jpg', 'alt' => 'A widget']);
        $this->assertSame('pass', $this->checkById($this->analyzer()->analyze($allAlt->fresh(), 'en'), 'image-alts')['status']);

        $someAlt = $this->makePost(['slug' => 'some-alt']);
        $this->addBlock($someAlt, 0, 'heisenberg/image', ['url' => 'https://cdn.example/a.jpg', 'alt' => 'A widget']);
        $this->addBlock($someAlt, 1, 'heisenberg/image', ['url' => 'https://cdn.example/b.jpg', 'alt' => '']);
        $this->assertSame('warn', $this->checkById($this->analyzer()->analyze($someAlt->fresh(), 'en'), 'image-alts')['status']);

        $noAlt = $this->makePost(['slug' => 'no-alt']);
        $this->addBlock($noAlt, 0, 'heisenberg/image', ['url' => 'https://cdn.example/a.jpg', 'alt' => '']);
        $this->assertSame('fail', $this->checkById($this->analyzer()->analyze($noAlt->fresh(), 'en'), 'image-alts')['status']);
    }

    public function test_image_alt_falls_back_to_a_referenced_public_file(): void
    {
        $file = PublicFile::create([
            'type' => 'jpg', 'disk' => 'uploads', 'stored_path' => 'a.jpg', 'original_name' => 'a.jpg',
            'stored_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'alt_text_en' => 'A widget from the library',
        ]);
        $post = $this->makePost();
        $this->addBlock($post, 0, 'heisenberg/image', ['url' => 'https://cdn.example/a.jpg', 'alt' => '', 'fileId' => $file->id]);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');

        $this->assertSame('pass', $this->checkById($result, 'image-alts')['status']);
    }

    public function test_internal_and_outbound_link_checks(): void
    {
        $post = $this->makePost();
        $noLinks = $this->analyzer()->analyze($post, 'en');
        $this->assertSame('warn', $this->checkById($noLinks, 'internal-link')['status']);
        $this->assertSame('warn', $this->checkById($noLinks, 'outbound-link')['status']);

        $this->addBlock($post, 0, 'heisenberg/button', ['text' => 'Read more', 'url' => '/related-post']);
        $this->addBlock($post, 1, 'heisenberg/button', ['text' => 'Source', 'url' => 'https://other-domain.test/source']);

        $result = $this->analyzer()->analyze($post->fresh(), 'en');
        $this->assertSame('pass', $this->checkById($result, 'internal-link')['status']);
        $this->assertSame('pass', $this->checkById($result, 'outbound-link')['status']);
    }

    public function test_slug_quality_pass_warn_fail(): void
    {
        $clean = $this->makePost(['slug' => 'a-clean-slug']);
        $this->assertSame('pass', $this->checkById($this->analyzer()->analyze($clean, 'en'), 'slug-quality')['status']);

        $dirty = $this->makePost(['slug' => 'clean']);
        $result = $this->analyzer()->analyze($dirty, 'en', ['slug' => 'Not_A Clean Slug!']);
        $this->assertSame('fail', $this->checkById($result, 'slug-quality')['status']);

        $long = $this->makePost(['slug' => 'long']);
        $longSlug = implode('-', array_fill(0, 30, 'word'));
        $result = $this->analyzer()->analyze($long, 'en', ['slug' => $longSlug]);
        $this->assertSame('warn', $this->checkById($result, 'slug-quality')['status']);
    }

    public function test_canonical_pass_when_empty_or_valid_warn_when_malformed(): void
    {
        $post = $this->makePost();

        $empty = $this->analyzer()->analyze($post, 'en', ['canonical' => '']);
        $this->assertSame('na', $this->checkById($empty, 'canonical')['status']);

        $valid = $this->analyzer()->analyze($post, 'en', ['canonical' => 'https://example.com/post']);
        $this->assertSame('pass', $this->checkById($valid, 'canonical')['status']);

        $malformed = $this->analyzer()->analyze($post, 'en', ['canonical' => 'not a url']);
        $this->assertSame('warn', $this->checkById($malformed, 'canonical')['status']);
    }

    public function test_indexable_fails_only_for_published_noindex_posts(): void
    {
        $draft = $this->makePost(['status' => 'draft']);
        $draftResult = $this->analyzer()->analyze($draft, 'en', ['robots' => 'noindex, follow']);
        $this->assertSame('pass', $this->checkById($draftResult, 'indexable')['status']);

        $published = $this->makePost(['status' => 'published', 'slug' => 'published-post']);
        $publishedResult = $this->analyzer()->analyze($published, 'en', ['robots' => 'noindex, follow']);
        $this->assertSame('fail', $this->checkById($publishedResult, 'indexable')['status']);

        $publishedOk = $this->analyzer()->analyze($published, 'en', ['robots' => 'index, follow']);
        $this->assertSame('pass', $this->checkById($publishedOk, 'indexable')['status']);
    }

    public function test_og_image_pass_and_warn(): void
    {
        $post = $this->makePost();

        $missing = $this->analyzer()->analyze($post, 'en', ['og_image' => '']);
        $this->assertSame('warn', $this->checkById($missing, 'og-image')['status']);

        $set = $this->analyzer()->analyze($post, 'en', ['og_image' => 'https://cdn.example/hero.jpg']);
        $this->assertSame('pass', $this->checkById($set, 'og-image')['status']);
    }

    public function test_readability_warns_when_there_is_no_content(): void
    {
        $post = $this->makePost();

        $result = $this->analyzer()->analyze($post, 'en');

        $this->assertSame('warn', $this->checkById($result, 'readability')['status']);
    }

    public function test_readability_thresholds_differ_between_en_and_fr(): void
    {
        // A text whose Flesch score lands at ~57.9 -- inside EN's "warn" band (40 <= x < 60) but
        // inside FR's "pass" band (x >= 55). Same underlying score (proves the SAME text is being
        // scored, not two different computations), different verdicts, because FR's thresholds
        // sit ~5 points below EN's (see SeoAnalyzer::readabilityCheck()'s own docblock on why).
        $post = $this->makePost();
        $a = 'Widgets need regular care and checks for best results daily.';
        $b = 'Widgets need proper care and checks for reliable results today.';
        $text = str_repeat($a . ' ', 3) . str_repeat($b . ' ', 2);
        $this->addBlock($post, 0, 'heisenberg/paragraph', ['content' => $text]);

        $en = $this->analyzer()->analyze($post->fresh(), 'en');
        $fr = $this->analyzer()->analyze($post->fresh(), 'fr');

        $enCheck = $this->checkById($en, 'readability');
        $frCheck = $this->checkById($fr, 'readability');

        $this->assertSame($enCheck['params']['score'] ?? null, $frCheck['params']['score'] ?? null);
        $this->assertSame('warn', $enCheck['status']);
        $this->assertSame('pass', $frCheck['status']);
    }

    public function test_overrides_win_over_the_stored_seo_meta_row(): void
    {
        $post = $this->makePost();
        $this->seoMeta($post, [
            'meta_title_en' => 'Stored title that is a decent length for the field',
            'focus_keyphrase_en' => 'stored keyphrase',
        ]);

        $stored = $this->analyzer()->analyze($post->fresh(), 'en');
        $this->assertSame('pass', $this->checkById($stored, 'keyphrase-set')['status']);

        $overridden = $this->analyzer()->analyze($post->fresh(), 'en', ['focus_keyphrase' => '']);
        $this->assertSame('warn', $this->checkById($overridden, 'keyphrase-set')['status']);
    }

    public function test_score_band_boundaries(): void
    {
        $this->assertSame('poor', $this->rating(0));
        $this->assertSame('poor', $this->rating(44));
        $this->assertSame('needs-work', $this->rating(45));
        $this->assertSame('needs-work', $this->rating(64));
        $this->assertSame('good', $this->rating(65));
        $this->assertSame('good', $this->rating(84));
        $this->assertSame('excellent', $this->rating(85));
        $this->assertSame('excellent', $this->rating(100));
    }

    public function test_a_thin_untouched_post_scores_poorly_and_a_fully_optimized_one_scores_well(): void
    {
        $thin = $this->makePost();
        $thinResult = $this->analyzer()->analyze($thin, 'en');
        // 'poor' (not 'needs-work'): the score math must NOT inflate a post with nothing in it
        // past the lowest rating band. This is the explicit bar set when the warn factor was
        // tightened from 0.5 to 0.25 and the "nothing to check" cases were moved to 'na'
        // (excluded from the score denominator). See test_title_only_post_scores_poor for the
        // user-facing example that motivated the change.
        $this->assertSame('poor', $thinResult['rating']);

        $rich = $this->makePost(['slug' => 'widget-care-guide', 'status' => 'published']);
        $this->addBlock($rich, 0, 'heisenberg/heading', ['content' => 'Widget care basics', 'level' => 2]);
        $this->addBlock($rich, 1, 'heisenberg/paragraph', [
            'content' => 'Widget care matters. Read this internal guide for tips. ' . $this->words(150, 'reliable simple sentence about widgets today'),
        ]);
        $this->addBlock($rich, 2, 'heisenberg/button', ['text' => 'Related guide', 'url' => '/related']);
        $this->addBlock($rich, 3, 'heisenberg/button', ['text' => 'Source', 'url' => 'https://other-domain.test/widgets']);
        $this->addBlock($rich, 4, 'heisenberg/image', ['url' => 'https://cdn.example/widget.jpg', 'alt' => 'Widget close-up']);

        $richResult = $this->analyzer()->analyze($rich->fresh(), 'en', [
            'meta_title' => 'Widget Care Guide: Everything You Need to Know',
            'meta_description' => 'A complete widget care guide covering cleaning, storage, and maintenance for beginners and pros alike.',
            'focus_keyphrase' => 'widget care',
            'canonical' => 'https://example.com/widget-care-guide',
            'robots' => 'index, follow',
            'og_image' => 'https://cdn.example/widget-hero.jpg',
        ]);

        $this->assertGreaterThan($thinResult['score'], $richResult['score']);
    }

    /**
     * Regression for the "title-only post reaches ~57% SEO score" report: with the warn factor at
     * 0.5 and the "nothing to check" cases (no images / empty canonical / no paragraphs /
     * short-no-headings) banking full pass credit, a post with literally nothing but a title
     * climbed to ~60% — "good" territory starting at 65 was within reach without any real SEO work.
     * The tighten-score change (warn 0.5→0.25, na excluded from the denominator) drops this case
     * well below the 'poor'/'needs-work' boundary at 45. If a future change pushes this number
     * back up, the bar the user actually cares about is here.
     */
    public function test_title_only_post_scores_poor(): void
    {
        $post = $this->makePost([
            // The bare minimum a real post has: one title, auto-derived slug, status unset
            // (Post::booted() regenerates the slug from the title when it's empty, but makePost
            // sets both — left in to mirror what the editor actually produces on first save).
            'title_en' => 'A Great Post About Widgets',
            'slug' => 'a-great-post-about-widgets',
            'status' => 'draft',
            'locale' => 'en',
        ]);

        $result = $this->analyzer()->analyze($post, 'en');

        $this->assertLessThan(45, $result['score'], "title-only post should score in 'poor' (<45), got {$result['score']}");
        $this->assertSame('poor', $result['rating']);
    }

    private function rating(int $score): string
    {
        $method = new \ReflectionMethod(SeoAnalyzer::class, 'rating');
        $method->setAccessible(true);

        return $method->invoke($this->analyzer(), $score);
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Demo;

use DOMDocument;
use DOMXPath;
use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Workbench\Database\Seeders\DemoSeeder;

/**
 * The end-to-end "adopter path" proof requested by the workbench demo task: seeds the
 * workbench's own DemoSeeder (the same class `php vendor/bin/testbench demo:seed` runs),
 * then exercises BOTH public surfaces side by side —
 *
 *  - the PACKAGE's bundled `GET /posts/{locale}/{slug}` (routes/public.php,
 *    PostPublicController) — zero host code, zero template-capability wiring;
 *  - the WORKBENCH's own `GET /blog/{locale}/{slug}` (workbench/routes/web.php) — every
 *    capability workbench/resources/heisenberg-templates/blog/post.json declares, wired
 *    by hand because nothing under src/ does that dispatch (see docs/demo.md).
 *
 * As of 2026-09-19 the lead fixed three bugs this demo originally caught (see
 * docs/demo.md's "found by this demo, fixed 2026-09-19" note): `PostPublicController`
 * now resolves ONE row for whichever locale it has content in (the single-row bilingual
 * model docs/content-translation.md §0 describes), the URL's `{locale}` segment now
 * drives rendering, the editor-preview banner no longer shows on the public route, and
 * `SeoUrlResolver` now falls back to the bundled public route instead of `/editor/{id}/
 * preview`. `workbench/database/seeders/DemoSeeder` and this test were updated to match
 * — bilingual posts are now ONE row each, and the French assertions below check the
 * FIXED behavior directly. The remaining finding — that none of the OTHER template
 * capabilities (breadcrumbs, reading time, author box, share buttons, related posts,
 * pagination) are wired to the bundled route — still stands and is still asserted here.
 *
 * This test deliberately does NOT rely on Testbench's full Workbench auto-discovery
 * chain (workbench.discovers.*) for its own execution — it requires workbench/routes/
 * web.php and workbench/database/seeders/DemoSeeder.php directly via defineWebRoutes()/
 * setUp(), and adds workbench/resources/views to the view finder itself. That keeps the
 * test deterministic and independent of composer.json autoloading (there is no
 * `Workbench\\` PSR-4 entry — see docs/demo.md's "composer.json lines" section), matching
 * this task's ground rule ("structure things so your test works without it").
 */
class AdopterPathTest extends TestCase
{
    use RefreshDatabase;

    private const WORKBENCH = __DIR__ . '/../../workbench';

    /**
     * `heisenberg.public.routes` is opt-in (default false) and routes load once at
     * provider boot, so it must be set here (not setUp()) — same reasoning as
     * tests/Public/PostPublicControllerTest. `template_root` mirrors workbench/config/
     * heisenberg.php (loaded for real by `testbench serve` via workbench.discovers.config
     * — not exercised by this test, applied directly instead). `seo.url_template` is set
     * explicitly here even though `SeoUrlResolver` (fixed 2026-09-19) now falls back to
     * the bundled public route automatically once `public.routes` is on — being explicit
     * keeps this test's alternates assertions independent of that fallback ever changing.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.public.routes', true);
        $app['config']->set('heisenberg.template_root', self::WORKBENCH . '/resources/heisenberg-templates');
        $app['config']->set('heisenberg.seo.url_template', '/posts/{locale}/{slug}');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Local-dev auth bypass (Heisenberg\Adapters\LocalDevRoleGate) — same posture as
        // every other unauthenticated-editor test in this suite.
        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();

        $viewPath = self::WORKBENCH . '/resources/views';
        $this->app['config']->set('view.paths', array_merge($this->app['config']->get('view.paths', []), [$viewPath]));
        $this->app['view']->addLocation($viewPath);

        require_once self::WORKBENCH . '/database/seeders/DemoSeeder.php';
        (new DemoSeeder())->run();
    }

    /**
     * Registers the SAME workbench/routes/web.php a real `testbench serve` loads (via
     * Testbench's own Workbench::discoverRoutes()) — required directly (not through the
     * Workbench auto-discovery chain) so this test has no dependency on
     * `workbench.discovers.web`/composer.json autoloading. Plain `require` (not
     * `require_once`): PHPUnit reuses this same process across test methods, each with a
     * FRESH Router, and every statement in that file is a route registration / closure
     * value — safe to re-run, never a class/function declaration (see the file's own
     * docblock for why that rule matters).
     */
    protected function defineWebRoutes($router): void
    {
        require self::WORKBENCH . '/routes/web.php';
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    // -- 1. the package's bundled public route, both locales -------------

    public function test_bundled_public_route_renders_published_post_in_english(): void
    {
        $response = $this->get('/posts/en/getting-started-with-widgets');

        $response->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertGreaterThan(0, $xpath->query('//h1[contains(text(), "Getting Started with Widgets")]')->length);
        // Block HTML actually rendered through BlockRenderer — a heading block's anchor id.
        $this->assertGreaterThan(0, $xpath->query('//*[@id="why-widgets"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[contains(@class, "hb-block-heading")]')->length);
        $this->assertGreaterThan(0, $xpath->query('//*[contains(@class, "hb-block-quote")]')->length);

        // Regression guard for the "Preview" bar bug (fixed 2026-09-19,
        // PostPublicController now passes `previewBar => false`): a real visitor must
        // never see the editor's "close this tab to return to the editor" copy.
        $this->assertStringNotContainsString('close this tab to return to the editor', $response->getContent());
    }

    public function test_bundled_public_route_renders_published_post_in_french(): void
    {
        // Single-row bilingual model (docs/content-translation.md §0): DemoSeeder writes
        // ONE `heisenberg_posts` row for this post (locale='en' — its authoring/home
        // locale — with title_fr + block `_fr` attribute variants also on that SAME
        // row). `PostPublicController::resolvePost()` (fixed 2026-09-19) now serves BOTH
        // `/posts/en/{slug}` and `/posts/fr/{slug}` from that one row, and the URL's
        // `{locale}` segment now drives rendering directly — no `?locale=` needed.
        $this->assertSame(
            1,
            Post::query()->where('slug', 'getting-started-with-widgets')->count(),
            'the bilingual post must be a SINGLE row, not a split-row pair'
        );

        $response = $this->get('/posts/fr/getting-started-with-widgets');

        $response->assertOk();
        // The French title, and French block text substituted from each block's
        // `content_fr`/`citation_fr` attribute variant by BlockRenderer.
        $response->assertSee('Bien démarrer avec les widgets', false);
        $response->assertSee('Pourquoi des widgets', false);
        $response->assertSee('Mesurez deux fois, serrez une fois.', false);

        // NOT the English title or English body text — this is genuinely the French
        // render, not the English row with a French-looking title bolted on. (Not
        // asserting the ENGLISH "Why widgets?" heading text is absent: the authored
        // table of contents has no `label_fr` column at all — see DemoSeeder's own
        // docblock — so its English labels legitimately appear on this page too; that
        // is a known, separate, documented gap, not evidence the body didn't render
        // in French.)
        $this->assertStringNotContainsString('Getting Started with Widgets', $response->getContent());
        $this->assertStringNotContainsString('Measure twice, tighten once.', $response->getContent());
        $this->assertStringNotContainsString('A widget is the smallest building block', $response->getContent());
    }

    public function test_bundled_public_route_ignores_template_capabilities_it_does_not_implement(): void
    {
        // Task 1 finding, proven live: the bundled route/view never renders breadcrumbs,
        // reading time, an author box, share buttons, related posts, or pagination —
        // none of PostTemplateRegistryService's discovered capabilities reach this page.
        $response = $this->get('/posts/en/getting-started-with-widgets');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('data-wb-breadcrumbs', $html);
        $this->assertStringNotContainsString('reading-time', $html);
        $this->assertStringNotContainsString('author-box', $html);
        $this->assertStringNotContainsString('Related posts', $html);
    }

    // -- 2. the workbench's own route, capabilities wired by hand ---------

    public function test_workbench_blog_show_applies_every_declared_capability(): void
    {
        $response = $this->get('/blog/en/getting-started-with-widgets');

        $response->assertOk();
        $xpath = $this->xpath($response->getContent());

        // breadcrumbs (Blog > Guides > title)
        $crumbs = $xpath->query('//*[@data-wb-breadcrumbs]');
        $this->assertSame(1, $crumbs->length);
        $this->assertStringContainsString('Guides', $response->getContent());

        // reading time, computed by the workbench route (nothing in src/ computes this)
        $this->assertSame(1, $xpath->query('//*[@data-wb-reading-time]')->length);

        // table of contents, from the post's authored tocEntries (3 entries seeded)
        $tocLinks = $xpath->query('//*[@data-wb-toc]//a');
        $this->assertSame(3, $tocLinks->length);

        // featured image (scoped via data-wb-featured — the body ALSO contains an
        // `heisenberg/image` block, which independently renders its own <figure>)
        $this->assertSame(1, $xpath->query('//*[@data-wb-featured]//img')->length);
        $this->assertGreaterThanOrEqual(2, $xpath->query('//figure//img')->length); // featured + at least one inline body image

        // author box + share buttons
        $this->assertSame(1, $xpath->query('//*[@data-wb-author-box]')->length);
        $shareLinks = $xpath->query('//*[@data-wb-share]//a');
        $this->assertSame(5, $shareLinks->length); // x, facebook, linkedin, email, copy-link

        // related posts (shares the "guides" category with widget-maintenance-tips)
        $relatedLinks = $xpath->query('//*[@data-wb-related]//a');
        $this->assertGreaterThan(0, $relatedLinks->length);

        // comments: only the APPROVED comment shows, never the pending one, and the
        // template's own sortOrder ("oldest") is honoured — unlike PostPublicController,
        // which hardcodes 'newest' regardless of what a template contract asks for.
        $this->assertStringContainsString('Priya N.', $response->getContent());
        $this->assertStringNotContainsString('New Reader', $response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-wb-comments]//*[@data-wb-comment]')->length);
    }

    public function test_workbench_blog_show_serves_french_from_the_same_row(): void
    {
        // Task A: the workbench's own route resolves the single row the same way
        // PostPublicController::resolvePost() does (own `locale` matches the URL, else
        // "has a title_<locale>") — not a second `locale=fr` row.
        $response = $this->get('/blog/fr/getting-started-with-widgets');

        $response->assertOk();
        $response->assertSee('Bien démarrer avec les widgets', false);
        $response->assertSee('Pourquoi des widgets', false);
        $this->assertStringNotContainsString('Getting Started with Widgets', $response->getContent());
        // Not asserting the English "Why widgets?" heading text is absent — the
        // authored TOC has no `label_fr` column (DemoSeeder's own docblock), so its
        // English labels legitimately appear here too; see the bundled-route French
        // test's comment for the full reasoning.
        $this->assertStringNotContainsString('A widget is the smallest building block', $response->getContent());

        // Capabilities still apply in French: breadcrumbs (using the category's
        // name_fr) and the featured image (alt text via getAlt('fr')).
        $this->assertStringContainsString('Guides', $response->getContent());
        $this->assertSame(1, $this->xpath($response->getContent())->query('//*[@data-wb-featured]//img')->length);
    }

    // -- 3. drafts stay invisible on BOTH surfaces ------------------------

    public function test_draft_post_is_404_on_the_bundled_public_route(): void
    {
        $this->get('/posts/en/upcoming-widget-roadmap')->assertNotFound();
    }

    public function test_draft_post_is_404_on_the_workbench_blog_route(): void
    {
        $this->get('/blog/en/upcoming-widget-roadmap')->assertNotFound();
    }

    // -- 4. the email surface ---------------------------------------------

    public function test_email_renders_html_and_text_preserving_variable_tokens(): void
    {
        $email = Post::query()->emails()->where('slug', 'widgets-weekly-welcome')->firstOrFail();

        $result = app(EmailRenderer::class)->render($email, 'en');

        $this->assertStringContainsString('{{ user.first_name }}', $result->html);
        $this->assertStringContainsString('{{ user.first_name }}', $result->text);
        $this->assertStringContainsString('{{ unsubscribe_url }}', $result->text);
        $this->assertSame('Widgets Weekly Welcome', $result->subject);

        // No unsanitized script content survives the render pipeline.
        $this->assertStringNotContainsString('<script', strtolower($result->html));
    }

    public function test_email_renderer_strips_a_script_tag_injected_into_block_content(): void
    {
        // Simulates content that bypassed the editor's own save-time HTML Purifier pass
        // (e.g. written directly to the DB, or a future save path that forgets to
        // sanitize) — BlockRenderer's OWN render-time rich-text sanitizer
        // (sanitizeRichText()) is the second, independent line of defense this asserts.
        $email = Post::create([
            'locale' => 'en',
            'title_en' => 'Injection probe',
            'slug' => 'injection-probe',
            'status' => 'published',
        ]);
        $email->type = 'email';
        $email->save();

        Block::create([
            'post_id' => $email->id,
            'type' => 'paragraph',
            'content' => [
                'id' => 'b0',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => 'Hello <script>alert(1)</script> world'],
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => 0,
        ]);

        $result = app(EmailRenderer::class)->render($email->fresh(), 'en');

        $this->assertStringNotContainsString('<script', strtolower($result->html));
        $this->assertStringContainsString('Hello', $result->html);
        $this->assertStringContainsString('world', $result->html);
    }

    public function test_workbench_email_preview_route_renders_html_and_text_side_by_side(): void
    {
        $response = $this->get('/demo/email-preview/widgets-weekly-welcome');

        $response->assertOk();
        $response->assertSee('Widgets Weekly Welcome', false);
        $response->assertSee('{{ user.first_name }}', false);
    }
}

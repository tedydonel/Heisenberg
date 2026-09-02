<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * docs/email-system.md §6.1/§7-E3: EmailPreviewController's read-only endpoints. A built email is
 * served at ONE address — `/emails/{slug}` (routes/email.php) — which renders through the SAME
 * EmailRenderer the Mailable uses, with images swapped to real URLs (browsers don't resolve
 * `cid:`, see EmailRenderer::rewriteImages()'s `$preview` branch). The editor's id-scoped
 * `/editor/{post}/email-preview` only redirects there, the post preview route 404s for an email,
 * and the size route (the editor's own JSON feed) reports the REAL, cid-embedded
 * EmailRenderResult::$sizeBytes. All gated exactly like PreviewController::showPost()
 * (PostPolicy `view`).
 */
class EmailPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function tinyImageBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
    }

    private function addBlock(Post $post, int $order, string $name, array $attributes): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => substr($name, strrpos($name, '/') + 1),
            'content' => [
                'id' => 'b' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => $order,
        ]);
    }

    private function makeEmailWithImage(): Post
    {
        Storage::disk('uploads')->put('media/2026/07/photo.jpg', $this->tinyImageBytes());

        PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 400,
            'height' => 300,
            'variants' => [],
        ]);

        // Published so PostPolicy::view() grants a guest actor access with no local-dev bypass
        // or acting-user stub needed — the authorization-required test below keeps the default
        // draft status instead, same as EditorRendersTest's own pattern.
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en', 'status' => 'published']);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello', 'level' => 2]);
        $this->addBlock($post, 2, 'heisenberg/image', ['url' => '/uploads/media/2026/07/photo.jpg', 'alt' => 'A photo']);

        return $post;
    }

    public function test_the_slug_route_swaps_cid_references_for_real_urls(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->get("/emails/{$post->slug}");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('src="cid:', $html);
        $this->assertStringContainsString('/uploads/media/2026/07/photo.jpg', $html);
    }

    public function test_the_slug_route_content_type_is_html(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->get("/emails/{$post->slug}");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    /**
     * A served email is a page on the host's own domain, but it is not web content — the sitemap
     * excludes `type = 'email'` for the same reason. Carried as a HEADER so the served bytes stay
     * byte-identical to the render: the reader gets exactly the HTML a mailer would send, not a
     * web-only variant with a meta tag spliced in.
     */
    public function test_the_served_page_is_noindexed_without_altering_the_rendered_html(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->get("/emails/{$post->slug}")->assertOk();

        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('name="robots"', (string) $response->getContent());
    }

    /** The slug route is scoped to `type = 'email'`: a POST is a different document with its own URL. */
    public function test_a_plain_posts_slug_is_not_reachable_on_the_email_route(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en', 'status' => 'published']);

        $this->get("/emails/{$post->slug}")->assertNotFound();
    }

    public function test_an_unknown_slug_404s(): void
    {
        $this->get('/emails/no-such-email')->assertNotFound();
    }

    /**
     * `['locale', 'slug']` is the posts table's unique index, so the same slug legitimately exists
     * in several locales — the active locale's row wins over an arbitrary first match.
     */
    public function test_the_active_locales_row_wins_when_a_slug_exists_in_several_locales(): void
    {
        foreach (['en' => 'English body', 'fr' => 'Corps français'] as $locale => $text) {
            $post = Post::create(['title_en' => 'Shared', 'locale' => $locale, 'status' => 'published', 'slug' => 'shared']);
            $post->type = 'email';
            $post->save();
            $this->addBlock($post, 1, 'heisenberg/paragraph', ['content' => $text]);
        }

        $this->app->setLocale('fr');

        $this->assertStringContainsString('Corps français', (string) $this->get('/emails/shared')->assertOk()->getContent());
    }

    // ── which LANGUAGE gets rendered (docs/content-translation.md §0) ────────

    private function makeBilingualEmail(): Post
    {
        $post = Post::create([
            'title_en' => 'August Letter',
            'title_fr' => 'Lettre d\'août',
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'august-letter',
        ]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/paragraph', [
            'content' => 'English body',
            'content_fr' => 'Corps français',
        ]);

        return $post;
    }

    /**
     * The reported bug: an author editing the French version exported the English one. The editing
     * locale is CLIENT state in the editor and the app locale is the UI language, so the only way
     * the server can know which translation to render is to be told — `?locale=`.
     */
    public function test_an_explicit_locale_renders_that_translation(): void
    {
        $post = $this->makeBilingualEmail();

        $french = $this->get("/emails/{$post->slug}?locale=fr")->assertOk()->getContent();

        $this->assertStringContainsString('Corps français', $french);
        // The subject too, not just the blocks — it comes from Post::title($locale). Matched on
        // the leading word alone: the full string's apostrophe is HTML-escaped in the <title>.
        $this->assertStringContainsString('<title>Lettre', $french);
        $this->assertStringNotContainsString('English body', $french);
    }

    /**
     * The no-locale case gets its own test on purpose: the controller calls `App::setLocale()`,
     * which lives for the rest of the PROCESS, and Laravel reuses one application across several
     * `$this->get()` calls inside a single test — so asserting both languages in one method would
     * be testing that leak rather than the behaviour. Each real HTTP request builds its own
     * container, so this only ever bites here.
     */
    public function test_no_locale_renders_the_app_locale(): void
    {
        $post = $this->makeBilingualEmail();

        $english = $this->get("/emails/{$post->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('English body', $english);
        $this->assertStringNotContainsString('Corps français', $english);
    }

    public function test_the_export_renders_and_names_the_requested_locale(): void
    {
        $post = $this->makeBilingualEmail();

        $response = $this->get("/emails/{$post->slug}/export?format=html&locale=fr")->assertOk();

        $this->assertStringContainsString('Corps français', (string) $response->getContent());
        $this->assertStringContainsString('filename="august-letter-fr.html"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_size_chip_measures_the_requested_locale(): void
    {
        $post = $this->makeBilingualEmail();

        $en = $this->getJson("/editor/{$post->id}/email-size?locale=en")->json('sizeBytes');
        $fr = $this->getJson("/editor/{$post->id}/email-size?locale=fr")->json('sizeBytes');

        $this->assertNotSame($en, $fr, 'the two translations differ in length, so their measured sizes must too');
    }

    /** An unconfigured locale is ignored rather than rendering an empty document. */
    public function test_an_unknown_locale_falls_back_to_the_app_locale(): void
    {
        $post = $this->makeBilingualEmail();

        $html = $this->get("/emails/{$post->slug}?locale=zz")->assertOk()->getContent();

        $this->assertStringContainsString('English body', $html);
    }

    /** The editor addresses posts by id, so its redirect has to carry the choice through. */
    public function test_the_id_scoped_routes_carry_the_locale_into_the_redirect(): void
    {
        $post = $this->makeBilingualEmail();

        $this->get("/editor/{$post->id}/email-preview?locale=fr")
            ->assertRedirect("/emails/{$post->slug}?locale=fr");
        $this->get("/editor/{$post->id}/email-export?format=eml&locale=fr")
            ->assertRedirect("/emails/{$post->slug}/export?format=eml&locale=fr");
    }

    public function test_the_editor_preview_route_redirects_to_the_slug(): void
    {
        $post = $this->makeEmailWithImage();

        $this->get("/editor/{$post->id}/email-preview")->assertRedirect("/emails/{$post->slug}");
    }

    public function test_the_post_preview_route_404s_for_an_email_document(): void
    {
        $post = $this->makeEmailWithImage();

        $this->get("/editor/{$post->id}/preview")->assertNotFound();
    }

    public function test_the_email_routes_404_for_a_plain_post(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en', 'status' => 'published']);

        $this->get("/editor/{$post->id}/email-preview")->assertNotFound();
        $this->get("/editor/{$post->id}/email-size")->assertNotFound();
    }

    public function test_email_preview_requires_view_authorization(): void
    {
        $post = Post::create(['title_en' => 'Secret draft', 'status' => 'draft']);
        $post->type = 'email';
        $post->save();

        $this->get("/emails/{$post->slug}")->assertForbidden();
        $this->get("/editor/{$post->id}/email-preview")->assertForbidden();
        $this->get("/editor/{$post->id}/email-size")->assertForbidden();
    }

    public function test_email_size_endpoint_returns_the_rendered_size_in_bytes(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->getJson("/editor/{$post->id}/email-size");

        $response->assertOk();
        $response->assertJsonStructure(['sizeBytes']);
        $this->assertIsInt($response->json('sizeBytes'));
        $this->assertGreaterThan(0, $response->json('sizeBytes'));
    }

    public function test_email_size_reflects_the_real_cid_embedded_render_not_the_preview_variant(): void
    {
        $post = $this->makeEmailWithImage();

        $size = $this->getJson("/editor/{$post->id}/email-size")->json('sizeBytes');

        // The cid-embedded render's sizeBytes includes the attachment bytes on top of the HTML,
        // so it must exceed the bare preview HTML's own length.
        $previewHtml = $this->get("/emails/{$post->slug}")->getContent();
        $this->assertGreaterThan(strlen($previewHtml), $size);
    }

    // ====================================================================
    // Wave E5 / Task 4 — sample-only author-facing GETs. Every built-in
    // GET that actually renders (`showBySlug`, size, HTML export, EML
    // export) MUST pass `EmailVariableContext::samples(...)` explicitly
    // so a registered sample value reaches the browser, never a runtime
    // value the host (or attacker) tried to inject.
    // ====================================================================

    /**
     * Fixture: published email with `{{ user.first_name }}` in the heading
     * subject mirror. The registry registers a non-secret sample so we can
     * observe which value (sample vs. raw token vs. attacker-supplied
     * runtime value) reaches the response.
     */
    private function makeEmailWithVariableToken(string $sample = 'Sample', string $subject = 'Hello {{ user.first_name }}'): Post
    {
        $post = Post::create([
            'title_en' => $subject,
            'locale' => 'en',
            'status' => 'published',
        ]);
        $post->type = 'email';
        $post->save();

        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'level' => 1,
        ]);
        // The button URL carries `{{ unsubscribe_url }}` (url target) and the
        // button label is the rich-text `user.first_name` (text target).
        $this->addBlock($post, 2, 'heisenberg/button', [
            'text' => 'Read more from {{ user.first_name }}',
            'url' => '{{ unsubscribe_url }}',
        ]);

        /** @var EmailVariableRegistry $registry */
        $registry = $this->app->make(EmailVariableRegistry::class);
        $registry->override(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: $sample,
        ));
        $registry->override(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsubscribe/sample',
        ));

        return $post;
    }

    public function test_show_by_slug_substitutes_registered_samples_never_raw_tokens(): void
    {
        // An author-facing GET previewing an email that contains a registered variable must
        // render the SAMPLE value (so the author can see how a token looks) — never the raw
        // `{{ user.first_name }}` token, which is what a strict empty runtime context would
        // produce via an aggregated REASON_MISSING_VALUE failure.
        $post = $this->makeEmailWithVariableToken();

        $html = (string) $this->get("/emails/{$post->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('Hi Sample', $html);
        $this->assertStringNotContainsString('{{ user.first_name }}', $html);
        $this->assertStringContainsString('https://example.test/unsubscribe/sample', $html);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}', $html);
    }

    public function test_show_by_slug_does_not_accept_runtime_values_from_query_string(): void
    {
        // `?variables[user.first_name]=Ada` must NEVER reach the render — author-facing GETs
        // are sample-only, and runtime values belong on the host mailer / batch admin route,
        // never on a slug GET a "view in browser" link might point at.
        $post = $this->makeEmailWithVariableToken(sample: 'Sample');

        $this->get("/emails/{$post->slug}?variables[user.first_name]=Ada")
            ->assertOk();

        $html = (string) $this->get("/emails/{$post->slug}?variables[user.first_name]=Ada")->getContent();

        $this->assertStringContainsString('Hi Sample', $html);
        $this->assertStringNotContainsString('Hi Ada', $html);
    }

    public function test_known_id_scoped_routes_redirect_to_the_slug_which_then_uses_samples(): void
    {
        // The id-scoped /editor/{post}/email-preview only REDIRECTS today (the actual render
        // happens at the slug URL) — but Task 4 requires that EVERY author-facing render path
        // (including those that delegate by id) explicitly passes the sample context. This test
        // follows the redirect and asserts the sample reaches the response.
        $post = $this->makeEmailWithVariableToken();

        $redirect = $this->get("/editor/{$post->id}/email-preview");
        $redirect->assertRedirect("/emails/{$post->slug}");

        $html = (string) $this->followingRedirects()->get("/editor/{$post->id}/email-preview")->getContent();
        $this->assertStringContainsString('Hi Sample', $html);
        $this->assertStringNotContainsString('{{ user.first_name }}', $html);
    }

    public function test_size_endpoint_uses_samples_and_returns_200(): void
    {
        // The size chip (topbar/footer) measures the REAL, cid-embedded render — same surface
        // the Mailable sends — and must therefore run with the same sample context the slug
        // preview does. Otherwise an unsatisfied token would 422 the size fetch even though the
        // author is editing, not sending.
        $post = $this->makeEmailWithVariableToken();

        $response = $this->getJson("/editor/{$post->id}/email-size");

        $response->assertOk();
        $response->assertJsonStructure(['sizeBytes']);
        $this->assertIsInt($response->json('sizeBytes'));
        $this->assertGreaterThan(0, $response->json('sizeBytes'));
    }

    public function test_size_endpoint_does_not_accept_runtime_values_from_query_string(): void
    {
        // A size request that tries to inject runtime values via the query string must still
        // return a size that corresponds to the registered sample — otherwise a "view in browser"
        // link could leak a recipient's address into a size measurement.
        $post = $this->makeEmailWithVariableToken(sample: 'Sample');

        $response = $this->getJson("/editor/{$post->id}/email-size?variables[user.first_name]=Ada");

        $response->assertOk();
        $this->assertIsInt($response->json('sizeBytes'));

        // The size measured with an attempt at runtime injection equals the size with no
        // injection at all (both use the registered sample). Comparing to the rendered HTML
        // (cid portion normalized) is more reliable than comparing to a fixed number — sizes
        // shift with theme tokens, image bytes, etc.
        $plain = $this->getJson("/editor/{$post->id}/email-size")->json('sizeBytes');
        $this->assertSame($plain, $response->json('sizeBytes'));
    }
}

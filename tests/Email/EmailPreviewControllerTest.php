<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
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

    // ── the slug the email is served at ──────────────────────────────────────

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

    // ── the editor's id-scoped routes ────────────────────────────────────────

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
}

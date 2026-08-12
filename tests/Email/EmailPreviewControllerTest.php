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
 * docs/email-system.md §7-E3: EmailPreviewController's two read-only endpoints. The preview route
 * renders through the SAME EmailRenderer the Mailable uses, with images swapped to real URLs
 * (browsers don't resolve `cid:`, see EmailRenderer::rewriteImages()'s `$preview` branch); the
 * size route reports the REAL, cid-embedded EmailRenderResult::$sizeBytes. Both gated exactly
 * like PreviewController::showPost() (PostPolicy `view`).
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

    public function test_email_preview_swaps_cid_references_for_real_urls(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->get("/editor/{$post->id}/email-preview");

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('src="cid:', $html);
        $this->assertStringContainsString('/uploads/media/2026/07/photo.jpg', $html);
    }

    public function test_email_preview_content_type_is_html(): void
    {
        $post = $this->makeEmailWithImage();

        $response = $this->get("/editor/{$post->id}/email-preview");

        $response->assertOk();
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_email_preview_requires_view_authorization(): void
    {
        $post = Post::create(['title_en' => 'Secret draft', 'status' => 'draft']);
        $post->type = 'email';
        $post->save();

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
        $previewHtml = $this->get("/editor/{$post->id}/email-preview")->getContent();
        $this->assertGreaterThan(strlen($previewHtml), $size);
    }
}

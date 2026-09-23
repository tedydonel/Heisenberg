<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * The icon block on the email surface (docs/email-system.md §4).
 *
 * No mail client renders SVG, so an icon ships as a PNG the EDITOR rasterizes and
 * `EmailIconImageController` stores; the block's email template points an `<img>` at it and
 * `EmailRenderer` turns that into the same inline `cid:` part an uploaded image gets — the icon
 * arrives in the body, not as a visible attachment.
 *
 * These PNGs are generated artifacts, not uploads: they deliberately have no media-library row,
 * which is the one thing that makes them different from every other image the renderer embeds.
 */
class EmailIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    /** A real 2x2 PNG — the renderer reads these bytes, so they cannot be a placeholder string. */
    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR4nGP8//8/AzJgYkAD5AsAAJ0GAwoLSPgAAAAASUVORK5CYII=', true);
    }

    private function makeEmailWithIcon(array $attributes): Post
    {
        $post = Post::create(['title_en' => 'Icon Letter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        Block::create([
            'post_id' => $post->id,
            'type' => 'icon',
            'content' => [
                'id' => 'i1',
                'name' => 'heisenberg/icon',
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => 0,
        ]);

        return $post;
    }

    public function test_a_rasterized_icon_is_embedded_inline_like_any_email_image(): void
    {
        Storage::disk('uploads')->put('email-icons/material-design-home-e91e63-48.png', $this->pngBytes());

        $post = $this->makeEmailWithIcon([
            'icon' => 'material-design/home',
            'titleAttr' => 'Home',
            'emailImage' => '/uploads/email-icons/material-design-home-e91e63-48.png',
            'emailImageW' => '48',
            'emailImageH' => '48',
        ]);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en');

        $this->assertCount(1, $result->embeds, 'the generated PNG has no media row, but must still embed');
        $cid = $result->embeds[0]['cid'];
        $this->assertSame('image/png', $result->embeds[0]['mime']);
        $this->assertStringContainsString('src="cid:' . $cid . '"', $result->html);
        $this->assertStringContainsString('width="48"', $result->html);
        $this->assertStringContainsString('alt="Home"', $result->html);
        // The invariant the whole email pipeline holds: nothing unresolved survives.
        $this->assertStringNotContainsString('var(', $result->html);
    }

    /** Two blocks, one icon: attached once, referenced twice — as repeated images already are. */
    public function test_the_same_icon_used_twice_is_attached_once(): void
    {
        Storage::disk('uploads')->put('email-icons/material-design-home-e91e63-48.png', $this->pngBytes());

        $post = $this->makeEmailWithIcon([
            'icon' => 'material-design/home',
            'emailImage' => '/uploads/email-icons/material-design-home-e91e63-48.png',
            'emailImageW' => '48',
            'emailImageH' => '48',
        ]);
        Block::create([
            'post_id' => $post->id,
            'type' => 'icon',
            'content' => [
                'id' => 'i2',
                'name' => 'heisenberg/icon',
                'schemaVersion' => '1.0.0',
                'attributes' => [
                    'icon' => 'material-design/home',
                    'emailImage' => '/uploads/email-icons/material-design-home-e91e63-48.png',
                    'emailImageW' => '48',
                    'emailImageH' => '48',
                ],
                'supports' => [],
                'innerBlocks' => [],
            ],
            'order' => 1,
        ]);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en');

        $this->assertCount(1, $result->embeds);
        $this->assertSame(2, substr_count($result->html, 'cid:' . $result->embeds[0]['cid']));
    }

    /**
     * An icon the editor never managed to rasterize ships NOTHING rather than a broken image —
     * and EmailBlockCoverageService is what tells the author, before the send.
     */
    public function test_an_icon_with_no_rasterized_png_renders_nothing_and_attaches_nothing(): void
    {
        $post = $this->makeEmailWithIcon(['icon' => 'material-design/home']);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en');

        $this->assertSame([], $result->embeds);
        $this->assertStringNotContainsString('<img', $result->html);
    }

    /** A `src` that points outside the generated directory is never embedded off the disk. */
    public function test_an_arbitrary_uploads_path_is_not_embedded_as_a_generated_icon(): void
    {
        Storage::disk('uploads')->put('secrets/private.png', $this->pngBytes());

        $post = $this->makeEmailWithIcon([
            'icon' => 'material-design/home',
            'emailImage' => '/uploads/secrets/private.png',
            'emailImageW' => '48',
            'emailImageH' => '48',
        ]);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en');

        $this->assertSame([], $result->embeds, 'only the generated email-icons directory embeds without a media row');
        $this->assertStringContainsString('/uploads/secrets/private.png', $result->html, 'left exactly as authored');
    }

    /** The browser preview tab has no MIME parts: it gets the public URL, as images do. */
    public function test_the_preview_surface_uses_the_public_url_instead_of_a_cid(): void
    {
        Storage::disk('uploads')->put('email-icons/material-design-home-e91e63-48.png', $this->pngBytes());

        $post = $this->makeEmailWithIcon([
            'icon' => 'material-design/home',
            'emailImage' => '/uploads/email-icons/material-design-home-e91e63-48.png',
            'emailImageW' => '48',
            'emailImageH' => '48',
        ]);

        $result = $this->app->make(EmailRenderer::class)->render($post, 'en', true);

        $this->assertSame([], $result->embeds);
        $this->assertStringNotContainsString('cid:', $result->html);
        $this->assertStringContainsString('email-icons/material-design-home-e91e63-48.png', $result->html);
    }
}

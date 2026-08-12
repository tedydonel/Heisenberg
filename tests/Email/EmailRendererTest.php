<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * docs/email-system.md §5 end-to-end coverage: a fixture email post with
 * heading/paragraph/image/button/columns(+column+paragraph) blocks, plus an `embed` block
 * (email-excluded, §4) to prove a skipped block never crashes the render.
 */
class EmailRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function renderer(): EmailRenderer
    {
        return $this->app->make(EmailRenderer::class);
    }

    /** A tiny (1x1) real GIF, so file reads (size/mime) never fail. */
    private function tinyImageBytes(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7');
    }

    private function addBlock(Post $post, int $order, string $name, array $attributes, array $innerBlocks = []): Block
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
                'innerBlocks' => $innerBlocks,
            ],
            'order' => $order,
        ]);
    }

    private function makeEmail(): Post
    {
        $post = Post::create(['title_en' => 'August Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        return $post;
    }

    /** The fixture PublicFile the image block's URL resolves to, with variants either side of 600px. */
    private function makeImageFile(): PublicFile
    {
        Storage::disk('uploads')->put('media/2026/07/photo.jpg', $this->tinyImageBytes());
        Storage::disk('uploads')->put('media/2026/07/photo-small.jpg', $this->tinyImageBytes());
        Storage::disk('uploads')->put('media/2026/07/photo-medium.jpg', $this->tinyImageBytes());

        return PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/07/photo.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204800,
            'width' => 1600,
            'height' => 1200,
            'variants' => [
                'small' => ['path' => 'media/2026/07/photo-small.jpg', 'width' => 320, 'height' => 240],
                'medium' => ['path' => 'media/2026/07/photo-medium.jpg', 'width' => 768, 'height' => 576],
            ],
        ]);
    }

    private function fullFixture(): Post
    {
        $post = $this->makeEmail();
        $this->makeImageFile();

        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello Subscribers', 'level' => 2]);
        $this->addBlock($post, 2, 'heisenberg/paragraph', ['content' => 'Thanks for reading this month.']);
        $this->addBlock($post, 3, 'heisenberg/image', ['url' => '/uploads/media/2026/07/photo.jpg', 'alt' => 'A photo']);
        $this->addBlock($post, 4, 'heisenberg/button', ['text' => 'Read more', 'url' => 'https://example.com/landing']);
        $this->addBlock($post, 5, 'heisenberg/columns', ['columns' => 2], [
            [
                'id' => 'col1', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    ['id' => 'col1p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => 'Left column text.'], 'supports' => [], 'innerBlocks' => []],
                ],
            ],
            [
                'id' => 'col2', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    ['id' => 'col2p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => 'Right column text.'], 'supports' => [], 'innerBlocks' => []],
                ],
            ],
        ]);
        // Excluded from the email palette (§4) -- must render nothing and never crash the pipeline.
        $this->addBlock($post, 6, 'heisenberg/embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        return $post;
    }

    public function test_table_markup_is_present_for_the_core_blocks(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertStringContainsString('<table', $result->html);
        $this->assertStringContainsString('Hello Subscribers', $result->html);
        $this->assertStringContainsString('Thanks for reading', $result->html);
        $this->assertStringContainsString('Read more', $result->html);
        $this->assertStringContainsString('Left column text.', $result->html);
        $this->assertStringContainsString('Right column text.', $result->html);
    }

    public function test_no_var_survives_anywhere_in_the_output(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertStringNotContainsString('var(', $result->html);
    }

    public function test_image_src_is_rewritten_to_a_cid_with_a_matching_embed_using_the_widest_variant_under_600px(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertMatchesRegularExpression('/src="cid:[a-zA-Z0-9]+@heisenberg"/', $result->html);
        $this->assertStringNotContainsString('src="/uploads/', $result->html);

        $this->assertCount(1, $result->embeds);
        $embed = $result->embeds[0];
        $this->assertStringContainsString('photo-small.jpg', $embed['path'], 'medium (768px) exceeds 600px, small (320px) is the widest qualifying variant');
        $this->assertSame('image/jpeg', $embed['mime']);
        $this->assertStringContainsString('cid:' . $embed['cid'], $result->html);
    }

    public function test_a_block_with_no_email_section_is_skipped_without_crashing(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        // The embed block's URL never appears -- it contributed no output at all.
        $this->assertStringNotContainsString('youtube', $result->html);
        $this->assertStringNotContainsString('<iframe', $result->html);
    }

    public function test_subject_is_the_posts_title(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertSame('August Newsletter', $result->subject);
    }

    public function test_the_text_alternative_contains_heading_paragraph_and_the_button_url(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertStringContainsString('Hello Subscribers', $result->text);
        $this->assertStringContainsString('Thanks for reading this month.', $result->text);
        $this->assertStringContainsString('Read more (https://example.com/landing)', $result->text);
        $this->assertStringContainsString('Left column text.', $result->text);
        $this->assertStringNotContainsString('<', $result->text, 'the text alternative carries no markup');
    }

    public function test_size_bytes_is_sane(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertGreaterThan(strlen($result->html), $result->sizeBytes, 'must include at least the embedded image bytes on top of the HTML');
        $this->assertGreaterThan(0, $result->sizeBytes);
    }

    public function test_an_email_with_no_blocks_renders_without_crashing(): void
    {
        $post = $this->makeEmail();

        $result = $this->renderer()->render($post, 'en');

        $this->assertSame('August Newsletter', $result->subject);
        $this->assertSame([], $result->embeds);
        $this->assertSame('', $result->text);
        $this->assertStringNotContainsString('var(', $result->html);
    }
}

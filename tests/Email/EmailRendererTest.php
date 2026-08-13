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

        $this->addBlock($post, 1, 'heisenberg/heading', ['content' => 'Hello Subscribers', 'level' => 1]);
        $this->addBlock($post, 2, 'heisenberg/paragraph', ['content' => 'Thanks for reading this month.']);
        $this->addBlock($post, 3, 'heisenberg/image', ['url' => '/uploads/media/2026/07/photo.jpg', 'alt' => 'A photo']);
        $this->addBlock($post, 4, 'heisenberg/button', ['text' => 'Read more', 'url' => 'https://example.com/landing']);
        $this->addBlock($post, 5, 'heisenberg/separator', []);
        $this->addBlock($post, 6, 'heisenberg/columns', ['columns' => 2], [
            [
                'id' => 'col1', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    ['id' => 'col1h', 'name' => 'heisenberg/heading', 'schemaVersion' => '1.0.0', 'attributes' => ['content' => 'Column heading', 'level' => 3], 'supports' => [], 'innerBlocks' => []],
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
        $this->addBlock($post, 7, 'heisenberg/embed', ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

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

    /** Regression: `blockStyleDeclarations()`'s root custom properties used to survive into
     *  every `style="…"` attribute (`--hb-heading-color`, `--hb-anim-dur`, …) — they resolve
     *  values, they are never the actual output. */
    public function test_no_css_custom_property_declarations_survive_anywhere_in_the_output(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertDoesNotMatchRegularExpression('/--[a-z0-9-]+\s*:/i', $result->html);
    }

    /**
     * Bug A email degrade (2026-08-13): Outlook cannot render `linear-gradient()`/
     * `radial-gradient()`. `heisenberg/group`'s email template consumes its background through
     * `background-color: var(--hb-group-bg, transparent)` — the ONE case in the fixture where a
     * `color-value-or-gradient` variable is actually read via `var()` in the email surface (most
     * blocks' email templates only ever reference their `-color`, not their `-bg`, variable).
     * A gradient background must degrade to its first colour stop: no `linear-gradient(` and no
     * surviving `var(` (the same invariant every other email test in this class pins).
     */
    public function test_a_gradient_background_degrades_to_its_first_stop_colour_in_email(): void
    {
        $post = $this->makeEmail();
        Block::create([
            'post_id' => $post->id,
            'type' => 'group',
            'content' => [
                'id' => 'b1',
                'name' => 'heisenberg/group',
                'schemaVersion' => '1.0.0',
                'attributes' => [],
                'supports' => ['color' => ['background' => 'linear-gradient(45deg, #ffffff 0%, #000000 100%)']],
                'innerBlocks' => [
                    [
                        'id' => 'b1p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0',
                        'attributes' => ['content' => 'Inside the gradient group.'], 'supports' => [], 'innerBlocks' => [],
                    ],
                ],
            ],
            'order' => 1,
        ]);

        $result = $this->renderer()->render($post, 'en');

        // Not an exact-substring match: CssToInlineStyles re-serializes the style attribute, so
        // the surviving whitespace around the colon is not this test's to pin.
        $this->assertMatchesRegularExpression('/background-color:\s*#ffffff/i', $result->html);
        $this->assertStringNotContainsString('linear-gradient', $result->html);
        $this->assertStringNotContainsString('var(', $result->html);
    }

    /** Regression: every block used to carry `hb-supports`/`hb-ease-*`/`hb-flex-layout` (web-only
     *  interaction/animation hooks) and `data-block-name`/`data-block-id` in the email markup. */
    public function test_no_editor_only_classes_or_data_block_attributes_survive(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        foreach (['hb-supports', 'hb-ease-', 'hb-anim-', 'hb-flex-layout', 'data-block-name', 'data-block-id'] as $needle) {
            $this->assertStringNotContainsString($needle, $result->html, "'{$needle}' must not survive into email output");
        }
    }

    /** Regression: the paragraph contract's text-color default pointed at `var(--accent-1)`
     *  (the theme's brand accent) instead of `var(--ink)`, unlike every other text block — so a
     *  plain, unstyled paragraph and a plain heading must resolve to the SAME literal colour
     *  (whatever the active theme's `ink` token is; asserted structurally, not by a hardcoded hex,
     *  since the token's literal value is theme-configurable). */
    public function test_paragraph_text_resolves_to_the_same_ink_colour_as_heading_not_the_accent(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertMatchesRegularExpression('/<h1[^>]*color: (#[0-9a-fA-F]+)">/', $result->html);
        preg_match('/<h1[^>]*color: (#[0-9a-fA-F]+)">/', $result->html, $headingMatch);
        preg_match('/color: (#[0-9a-fA-F]+)">Thanks for reading this month\./', $result->html, $paragraphMatch);

        $this->assertNotEmpty($paragraphMatch, 'expected to find the paragraph text with a resolved literal color');
        $this->assertSame($headingMatch[1], $paragraphMatch[1], 'paragraph body text must resolve to the same ink colour as a heading, not the accent');
    }

    /** Regression: an `<h3>` rendered at the same 24px fallback as an `<h1>` — level was
     *  ignored entirely for email sizing, unlike the canvas's per-tag CSS scale. */
    public function test_heading_font_size_scales_by_level(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertMatchesRegularExpression('/<h1[^>]*font-size: 32px[^>]*>Hello Subscribers/', $result->html);
        $this->assertMatchesRegularExpression('/<h3[^>]*font-size: 20px[^>]*>Column heading/', $result->html);
    }

    /** Regression: `<a><img …></a>` was emitted even when the image had no `href` — dead,
     *  non-functional markup around every unlinked image. */
    public function test_an_image_with_no_href_gets_no_wrapping_anchor(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>\s*<img/', $result->html);
    }

    /** Regression: the separator's filler cell carried a literal U+00A0 character — fragile
     *  through non-UTF-8-safe tooling and pointless once `font-size:0` already zeroes it. */
    public function test_the_separator_cell_carries_no_filler_character(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertStringNotContainsString("\u{00A0}", $result->html);
        $this->assertMatchesRegularExpression('/border-top:1px solid #e4e4e4[^"]*"><\/td>/', $result->html);
    }

    /** Regression: `<td class="hb-email-col">` cells carried no `width` — Outlook needs an
     *  explicit per-cell width or the layout collapses unpredictably. */
    public function test_columns_get_explicit_percentage_widths_summing_to_100(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertSame(2, preg_match_all('/class="hb-email-col" valign="top" width="50%"/', $result->html));
    }

    /** Regression: the Outlook/iOS client-hack resets (`-webkit-text-size-adjust`,
     *  `mso-table-lspace`, the `img` reset) were inlined onto every `<table>`/`<td>`/`<img>`
     *  instead of staying only in the head `<style>`. */
    public function test_client_hack_css_stays_in_the_head_and_is_never_inlined(): void
    {
        $result = $this->renderer()->render($this->fullFixture(), 'en');

        $this->assertStringContainsString('-webkit-text-size-adjust:100%', $result->html);
        $this->assertStringNotContainsString('style="-webkit-text-size-adjust', $result->html);
        $this->assertStringNotContainsString('mso-table-lspace: 0pt;', $result->html);
    }
}

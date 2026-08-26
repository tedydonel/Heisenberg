<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\EmailVariableContext;
use Heisenberg\Support\EmailVariableDefinition;
use Heisenberg\Support\EmailVariableResolutionException;
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

    // ====================================================================
    // Wave E5 / Task 3 — personalization threading through the renderer.
    // The signature gains `?EmailVariableContext $variables = null`; an
    // omitted context MUST mean a strict empty runtime context (every
    // missing token becomes REASON_MISSING_VALUE), never a sample fallback.
    // Existing no-variable calls remain byte-for-byte compatible.
    // ====================================================================

    /**
     * Fixture used by every Task 3 vertical slice: subject + heading +
     * paragraph (rich-text) + button (rich-text label + url) + image +
     * separator + two columns each with a paragraph, all carrying
     * `{{ user.first_name }}` / `{{ user.email }}` / `{{ unsubscribe_url }}`
     * tokens in the right contract-aware slots.
     */
    private function variableFixture(): Post
    {
        $post = Post::create([
            'title_en' => 'Welcome {{ user.first_name }}',
            'locale' => 'en',
        ]);
        $post->type = 'email';
        $post->save();

        $this->makeImageFile();

        $this->addBlock($post, 1, 'heisenberg/heading', [
            'content' => 'Hi {{ user.first_name }}',
            'level' => 1,
        ]);
        $this->addBlock($post, 2, 'heisenberg/paragraph', [
            'content' => 'Hello {{ user.first_name }}, welcome aboard.',
        ]);
        $this->addBlock($post, 3, 'heisenberg/image', [
            'url' => '/uploads/media/2026/07/photo.jpg',
            'alt' => 'Photo for {{ user.first_name }}',
            'caption' => 'Photo caption for {{ user.first_name }}',
        ]);
        // The button URL carries `{{ unsubscribe_url }}` (url target) and the
        // button label is the rich-text `user.first_name` (text target).
        $this->addBlock($post, 4, 'heisenberg/button', [
            'text' => 'Read more from {{ user.first_name }}',
            'url' => '{{ unsubscribe_url }}',
        ]);
        $this->addBlock($post, 5, 'heisenberg/separator', []);
        $this->addBlock($post, 6, 'heisenberg/columns', ['columns' => 2], [
            [
                'id' => 'col1', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    [
                        'id' => 'col1p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0',
                        'attributes' => ['content' => 'Left: {{ user.first_name }}'], 'supports' => [], 'innerBlocks' => [],
                    ],
                ],
            ],
            [
                'id' => 'col2', 'name' => 'heisenberg/column', 'schemaVersion' => '1.0.0',
                'attributes' => [], 'supports' => [],
                'innerBlocks' => [
                    [
                        'id' => 'col2p', 'name' => 'heisenberg/paragraph', 'schemaVersion' => '1.0.0',
                        'attributes' => ['content' => 'Right: {{ user.first_name }}'], 'supports' => [], 'innerBlocks' => [],
                    ],
                ],
            ],
        ]);

        // Register the three definitions the fixture uses.
        $registry = $this->app->make(\Heisenberg\Services\EmailVariableRegistry::class);
        $registry->register(new EmailVariableDefinition(
            key: 'user.first_name',
            label: 'First name',
            type: 'text',
            sample: 'Sample',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'user.email',
            label: 'Email',
            type: 'email',
            sample: 'sample@example.test',
        ));
        $registry->register(new EmailVariableDefinition(
            key: 'unsubscribe_url',
            label: 'Unsubscribe URL',
            type: 'url',
            sample: 'https://example.test/unsub/sample',
        ));

        return $post;
    }

    public function test_render_accepts_an_optional_variable_context(): void
    {
        // Plain render (no variables) — byte-for-byte compatible. The new
        // fourth parameter is `?EmailVariableContext $variables = null`.
        // CIDs are randomized by EmailRenderer::rewriteImages(), so we
        // strip the cid component out of both outputs before comparison —
        // the cid is the SAME mechanism regardless of whether a context
        // was passed.
        $legacy = $this->renderer()->render($this->fullFixture(), 'en');
        $explicitNull = $this->renderer()->render(
            $this->fullFixture(),
            'en',
            false,
            EmailVariableContext::runtime([]),
        );

        $normalize = static function (string $html): string {
            return (string) preg_replace('/cid:[a-zA-Z0-9]+@heisenberg/', 'cid:NORMALIZED', $html);
        };

        $this->assertSame($normalize($legacy->html), $normalize($explicitNull->html));
        $this->assertSame($legacy->subject, $explicitNull->subject);
        $this->assertSame($legacy->text, $explicitNull->text);
    }

    public function test_subject_html_text_and_button_label_are_personalized(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        // Subject is interpolated raw (no HTML entities) — MIME subjects stay plain text.
        $this->assertSame('Welcome Ada', $result->subject);

        // Rich-text `content` is HTML-escaped before the block renderer's sanitizer
        // sees it; "Ada" has nothing to escape, but "<unsafe>" would prove it.
        $this->assertStringContainsString('Hi Ada', $result->html);
        $this->assertStringContainsString('Hello Ada, welcome aboard.', $result->html);
        $this->assertStringContainsString('Photo for Ada', $result->html);
        $this->assertStringContainsString('Photo caption for Ada', $result->html);
        $this->assertStringContainsString('Left: Ada', $result->html);
        $this->assertStringContainsString('Right: Ada', $result->html);

        // Button label (rich-text) and button URL (url contract attr) are substituted.
        $this->assertStringContainsString('Read more from Ada', $result->html);
        $this->assertStringContainsString('href="https://example.test/unsub/ada"', $result->html);

        // Plain-text alternative contains the interpolated subject line + URL.
        $this->assertStringContainsString('Hi Ada', $result->text);
        $this->assertStringContainsString('Read more from Ada (https://example.test/unsub/ada)', $result->text);
    }

    public function test_rich_text_variable_replacement_is_html_escaped_so_script_payloads_become_text(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => '<script>alert(1)</script>',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        // The replacement was HTML-escaped BEFORE BlockRenderer::sanitizeRichText()
        // saw it — there is no surviving <script> anywhere in the HTML body.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $result->html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result->html);
    }

    public function test_javascript_url_is_rejected_by_existing_safe_url_gate(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                // The interpolator substitutes this RAW; BlockRenderer::safeUrl()
                // is the gate that neutralizes javascript: in the HTML output.
                'unsubscribe_url' => 'javascript:alert(1)',
            ]),
        );

        // The HTML body must NOT carry a `javascript:` URL — safeUrl() is the gate.
        $this->assertStringNotContainsString('javascript:', $result->html);
    }

    public function test_two_recipient_contexts_produce_distinct_html_text_and_subject(): void
    {
        $post = $this->variableFixture();

        $ada = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );
        $ben = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ben',
                'user.email' => 'ben@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ben',
            ]),
        );

        $this->assertNotSame($ada->html, $ben->html);
        $this->assertNotSame($ada->text, $ben->text);
        $this->assertSame('Welcome Ada', $ada->subject);
        $this->assertSame('Welcome Ben', $ben->subject);
    }

    public function test_mime_subject_is_raw_plain_text_without_html_entities(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => '<unsafe> & "quoted"',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        // The subject the Mailable forwards to Symfony Mime is the raw plain
        // text — no HTML entities. HTML consumers such as wrapShell() escape
        // at their own boundary.
        $this->assertSame('Welcome <unsafe> & "quoted"', $result->subject);
        $this->assertStringNotContainsString('&lt;', $result->subject);
        $this->assertStringNotContainsString('&quot;', $result->subject);
    }

    public function test_image_blocks_keep_cid_embed_behavior_under_personalization(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        // The image block's URL is still rewritten to `cid:…` and the
        // embeds manifest still carries exactly one entry — the interpolator
        // does NOT mutate `url` for image blocks unless the contract slot
        // says so, but the URL rewrite to cid still fires for the substituted
        // /uploads/media/2026/07/photo.jpg literal.
        $this->assertMatchesRegularExpression('/src="cid:[a-zA-Z0-9]+@heisenberg"/', $result->html);
        $this->assertCount(1, $result->embeds);
        $this->assertStringContainsString('cid:' . $result->embeds[0]['cid'], $result->html);
    }

    public function test_size_bytes_is_a_non_zero_total_under_personalization(): void
    {
        $post = $this->variableFixture();

        $result = $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        $this->assertGreaterThan(0, $result->sizeBytes);
        $this->assertGreaterThanOrEqual(strlen($result->html), $result->sizeBytes);
    }

    public function test_persisted_block_content_remains_tokenized_after_render(): void
    {
        $post = $this->variableFixture();

        $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );

        // The interpolator must NEVER write back to the input tree. The
        // stored Block::content payload still carries literal tokens —
        // except the separator block, which never had any token.
        $hadTokenCount = 0;
        foreach ($post->blocks as $block) {
            $content = is_array($block->content) ? $block->content : [];
            $serialized = (string) json_encode($content);

            if (str_contains($serialized, '{{ user.first_name }}')) {
                $hadTokenCount++;
            } else {
                // Sanity: a block that had no tokens stays a block with no tokens.
                $this->assertStringNotContainsString('Ada', $serialized, 'a block without tokens must not receive a substituted value');
            }
        }
        $this->assertGreaterThan(0, $hadTokenCount, 'at least one block in the fixture carried a token');

        // Same assertion for the persisted post title.
        $post->refresh();
        $this->assertSame('Welcome {{ user.first_name }}', $post->title_en);
    }

    public function test_missing_runtime_value_throws_before_a_real_render_can_send(): void
    {
        $post = $this->variableFixture();

        $this->expectException(EmailVariableResolutionException::class);
        $this->expectExceptionMessage('user.first_name');

        // `user.first_name` is registered but the runtime map omits it.
        $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
            ]),
        );
    }

    public function test_unknown_token_throws_before_a_real_render_can_send(): void
    {
        $post = $this->variableFixture();

        // Add a paragraph that references `user.mystery` (no definition).
        $this->addBlock($post, 8, 'heisenberg/paragraph', [
            'content' => 'Top-secret: {{ user.mystery }}',
        ]);

        $this->expectException(EmailVariableResolutionException::class);
        $this->expectExceptionMessage('user.mystery');

        // `user.mystery` is not registered.
        $this->renderer()->render(
            $post,
            'en',
            false,
            EmailVariableContext::runtime([
                'user.first_name' => 'Ada',
                'user.email' => 'ada@example.test',
                'unsubscribe_url' => 'https://example.test/unsub/ada',
                'user.mystery' => 'whatever',
            ]),
        );
    }

    public function test_subject_and_block_failures_are_aggregated_into_one_exception(): void
    {
        $post = Post::create([
            'title_en' => 'Welcome {{ user.subject_unknown }}',
            'locale' => 'en',
            'type' => 'email',
        ]);
        $this->addBlock($post, 1, 'heisenberg/paragraph', [
            'content' => 'Hello {{ user.block_unknown }}',
        ]);

        try {
            $this->renderer()->render($post, 'en', false, EmailVariableContext::runtime([]));
            $this->fail('Expected subject and block failures to prevent rendering.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame(
                ['user.subject_unknown', 'user.block_unknown'],
                $e->getKeys(),
            );
        }
    }

    public function test_incompatible_target_throws_before_a_real_render_can_send(): void
    {
        // Register a custom formatter that ONLY supports `url` — pushing it
        // into a text attribute (the paragraph `content`) must fail with
        // REASON_INCOMPATIBLE_TARGET, aggregated.
        $registry = $this->app->make(\Heisenberg\Services\EmailVariableRegistry::class);
        $registry->registerType(new TestUrlOnlyEmailVariableType());
        $registry->register(new EmailVariableDefinition(
            key: 'campaign.badge_url',
            label: 'Campaign badge URL',
            type: 'url_only',
            sample: 'https://example.test/badge',
        ));

        $post = $this->variableFixture();
        // Add a paragraph that uses campaign.badge_url in `content` (text target).
        $this->addBlock($post, 7, 'heisenberg/paragraph', [
            'content' => 'Badge: {{ campaign.badge_url }}',
        ]);

        try {
            $this->renderer()->render(
                $post,
                'en',
                false,
                EmailVariableContext::runtime([
                    'user.first_name' => 'Ada',
                    'user.email' => 'ada@example.test',
                    'unsubscribe_url' => 'https://example.test/unsub/ada',
                    'campaign.badge_url' => 'https://example.test/badge/ada',
                ]),
            );
            $this->fail('Expected EmailVariableResolutionException for an incompatible formatter target.');
        } catch (EmailVariableResolutionException $e) {
            $this->assertSame('campaign.badge_url', $e->getKey());
            $this->assertStringContainsString('incompatible', $e->getReason());
            // The host value is never leaked through the exception message.
            $this->assertStringNotContainsString('badge/ada', $e->getMessage());
        }
    }

    public function test_omitted_context_means_strict_empty_runtime_context_never_samples(): void
    {
        // A registered variable with a SAMPLE must NOT silently fall through
        // to that sample — an omitted context means a strict empty runtime
        // map (every missing token throws). Preview/single-export surfaces
        // pass EmailVariableContext::samples(...) explicitly (Task 4).
        $post = $this->variableFixture();

        $this->expectException(EmailVariableResolutionException::class);
        $this->expectExceptionMessage('user.first_name');

        // No fourth argument at all.
        $this->renderer()->render($post, 'en');
    }

    public function test_no_variable_email_call_still_renders_byte_for_byte(): void
    {
        // A post with NO tokens renders byte-for-byte whether or not the new
        // fourth argument is supplied. This is the compatibility anchor the
        // plan's Locked API decision §7 promises. (CIDs are randomized inside
        // EmailRenderer::rewriteImages(), so we strip the cid component out
        // of both outputs before comparison — the cid is the SAME mechanism
        // it has always been, regardless of whether a variable context was
        // passed; what we are proving is that interpolation does not perturb
        // any other byte of the rendered HTML.)
        $baseline = $this->renderer()->render($this->fullFixture(), 'en');
        $withExplicitEmpty = $this->renderer()->render(
            $this->fullFixture(),
            'en',
            false,
            EmailVariableContext::runtime([]),
        );

        $normalize = static function (string $html): string {
            // Strip the random cid portion; keep the cid-scheme intact.
            return (string) preg_replace('/cid:[a-zA-Z0-9]+@heisenberg/', 'cid:NORMALIZED', $html);
        };

        $this->assertSame($normalize($baseline->html), $normalize($withExplicitEmpty->html));
        $this->assertSame($baseline->subject, $withExplicitEmpty->subject);
        $this->assertSame($baseline->text, $withExplicitEmpty->text);
        $this->assertSame($baseline->sizeBytes, $withExplicitEmpty->sizeBytes);
    }
}

/**
 * Helper formatter for Task 3's "incompatible target" slice. Targets ONLY
 * `url`, so pushing it into a `text` attribute fails aggregated with
 * REASON_INCOMPATIBLE_TARGET.
 */
final class TestUrlOnlyEmailVariableType implements \Heisenberg\Contracts\EmailVariableType
{
    public function key(): string
    {
        return 'url_only';
    }

    /** @return list<'text'|'url'|'email'> */
    public function targets(): array
    {
        return ['url'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        return (string) $value;
    }
}

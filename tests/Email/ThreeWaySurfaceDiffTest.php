<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Mail\HeisenbergMailable;
use Heisenberg\Rendering\BlockTreeRenderer;
use Heisenberg\Services\BlockRenderer;
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Tests\Email\Support\EmailDiffFixtures;
use Heisenberg\Tests\Email\Support\SurfaceNormalizer;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * THE MEASUREMENT HARNESS for "emails don't render properly" (see
 * docs/email-surface-divergences.md for the resulting inventory).
 *
 * Three surfaces exist for an email document:
 *   1. The editor CANVAS — client-side JS (resources/views/components/live/block-runtime/
 *      05-render-tree.blade.php's renderNode()/renderBlockEl()), a hand-maintained mirror of
 *      {@see BlockTreeRenderer}.
 *   2. The {@see EmailRenderer} PREVIEW — `render($post, $locale, preview: true)`, real public
 *      image URLs, browser-viewable (the `/emails/{slug}` route).
 *   3. The ACTUAL SENT MAIL — `render($post, $locale, preview: false)` via
 *      {@see HeisenbergMailable}: `cid:` embeds, a plain-text alternative, real MIME parts.
 *
 * Surfaces 2 and 3 are both {@see EmailRenderer} output and SHOULD be near-identical apart from
 * image embedding — that comparison is fully server-side, deterministic, and PINNED here
 * (`test_preview_and_real_send_differ_only_in_image_embedding_*`). It is the stable half of this
 * harness.
 *
 * Surface 1 (the canvas) is client-side JS that another, concurrent piece of work is actively
 * changing — pinning today's canvas HTML as a golden file would encode whatever bug or fix
 * happens to be mid-flight right now. So this file does NOT assert canvas output. What it DOES
 * do, deterministically and without a browser:
 *
 *   - `test_the_render_surface_and_email_surface_diverge_exactly_where_documented` renders the
 *     SAME block tree through {@see BlockRenderer} directly on BOTH the `render` (web) surface —
 *     the proxy this harness uses for "what the canvas would show if it walked render.template"
 *     — and the `email` surface, and pins the specific, documented divergences (align dropped,
 *     gradient degraded, editor-only classes absent). This is real measurement of a real,
 *     non-flaky server-side code path; it is NOT a claim about what the live browser canvas
 *     currently paints (see docs/email-surface-divergences.md's "what could not be measured"
 *     section for the actual browser/jsdom capture and its limits).
 *
 * A one-off jsdom capture of the REAL canvas JS (resources/views/components/live/block-runtime)
 * against these same fixtures was also performed for this harness's report — see
 * docs/email-surface-divergences.md for that evidence and its own caveats. It is not re-run by
 * this file on every test run: it needs Node + the dumped editor HTML and is exactly the kind of
 * one-off measurement the task calls for, not a pinned assertion against a moving target.
 */
class ThreeWaySurfaceDiffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('uploads');
    }

    private function emailRenderer(): EmailRenderer
    {
        return $this->app->make(EmailRenderer::class);
    }

    private function blockRenderer(): BlockRenderer
    {
        return $this->app->make(BlockRenderer::class);
    }

    // ====================================================================
    // 0. The normaliser must be proven inert on its own output before it is
    //    trusted to prove two surfaces equal (task mandate: "PROVE the
    //    normaliser by diffing a surface against itself").
    // ====================================================================

    public function test_normalizer_self_diff_is_empty(): void
    {
        foreach (EmailDiffFixtures::names() as $name) {
            $post = EmailDiffFixtures::load($name);
            $preview = $this->emailRenderer()->render($post, 'en', preview: true);

            // (a) the exact same string, normalized twice, must match byte for byte.
            $this->assertSame(
                SurfaceNormalizer::normalize($preview->html),
                SurfaceNormalizer::normalize($preview->html),
                "fixture '{$name}': normalizing the same HTML twice must be idempotent"
            );

            // (b) re-rendering the SAME post is documented to be idempotent
            // (EmailRendererTest::test_variable_placeholders_are_passed_through_verbatim already
            // pins this for one fixture); the normalizer must agree — an independent second
            // render, diffed against the first, must normalize to nothing.
            $again = $this->emailRenderer()->render($post->fresh(['blocks']), 'en', preview: true);
            $diff = SurfaceNormalizer::selfDiffProof($preview->html, $again->html);
            $this->assertSame('', $diff, "fixture '{$name}': two independent renders of the same post must normalize identically — {$diff}");
        }
    }

    /** A deliberately DIFFERENT cid / random token must still normalize identically — proves the
     *  normalizer is doing real work, not merely comparing already-identical strings. */
    public function test_normalizer_proof_is_not_vacuous_it_actually_absorbs_random_cid_tokens(): void
    {
        $a = '<img src="cid:aaaaaaaaaaaaaaaaaaaaaaaa@heisenberg" alt="x">';
        $b = '<img src="cid:bbbbbbbbbbbbbbbbbbbbbbbb@heisenberg" alt="x">';

        $this->assertNotSame($a, $b, 'sanity: the two raw fixtures really are byte-different');
        $this->assertSame(SurfaceNormalizer::normalize($a), SurfaceNormalizer::normalize($b));
    }

    // ====================================================================
    // 1. Preview vs real send: the PINNED comparison. Both come from
    //    EmailRenderer; per the task's own framing, if these differ beyond
    //    image embedding that is a real, previously-uncharacterised bug.
    // ====================================================================

    /**
     * @return list<array{0: string}>
     */
    public static function fixtureNameProvider(): array
    {
        return array_map(static fn (string $n): array => [$n], EmailDiffFixtures::names());
    }

    #[DataProvider('fixtureNameProvider')]
    public function test_subject_is_byte_identical_between_preview_and_real_send(string $fixture): void
    {
        $post = EmailDiffFixtures::load($fixture);

        $preview = $this->emailRenderer()->render($post, 'en', preview: true);
        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame($preview->subject, $mailable->result->subject, "fixture '{$fixture}'");
    }

    #[DataProvider('fixtureNameProvider')]
    public function test_plain_text_alternative_is_byte_identical_between_preview_and_real_send(string $fixture): void
    {
        $post = EmailDiffFixtures::load($fixture);

        $preview = $this->emailRenderer()->render($post, 'en', preview: true);
        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame(
            $preview->text,
            $mailable->result->text,
            "fixture '{$fixture}': the plain-text alternative is generated from the block tree directly (EmailRenderer::textFor()), independent of the preview flag — it must never differ"
        );
    }

    /**
     * THE central pinned assertion: once image `src` values are normalized away (the ONE
     * documented difference — a live URL on preview, a `cid:` reference on the real send), the
     * two HTML bodies must be byte-for-byte identical after normalization. A failure here is a
     * genuine, previously-uncharacterised bug in EmailRenderer (its `$preview` branch touches
     * more than image rewriting), not a canvas problem.
     */
    #[DataProvider('fixtureNameProvider')]
    public function test_preview_and_real_send_html_are_identical_once_image_embedding_is_normalized_away(string $fixture): void
    {
        $post = EmailDiffFixtures::load($fixture);

        $preview = $this->emailRenderer()->render($post, 'en', preview: true);
        $mailable = new HeisenbergMailable($post->id, 'en');

        $normalizedPreview = SurfaceNormalizer::normalize($preview->html, stripImageSrc: true);
        $normalizedMime = SurfaceNormalizer::normalize($mailable->result->html, stripImageSrc: true);

        $diff = SurfaceNormalizer::firstDivergence($normalizedPreview, $normalizedMime);
        $this->assertSame(
            $normalizedPreview,
            $normalizedMime,
            "fixture '{$fixture}': preview and real-send HTML diverge beyond image embedding — {$diff}"
        );
    }

    /**
     * The mirror-image assertion, WITHOUT normalizing image src away: for a fixture containing an
     * embeddable (media-library) image, the raw HTML must actually differ (proving the harness
     * isn't accidentally comparing two copies of the same string) and that difference must be
     * exactly the documented one — a live URL vs a `cid:` reference, nothing else about the
     * surrounding `<img>` tag.
     */
    public function test_the_only_raw_difference_for_a_fixture_with_a_media_library_image_is_the_src_scheme(): void
    {
        $post = EmailDiffFixtures::load('media-image-sources');

        $preview = $this->emailRenderer()->render($post, 'en', preview: true);
        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertNotSame($preview->html, $mailable->result->html, 'sanity: the raw HTML really does differ (image src)');
        $this->assertStringContainsString('/uploads/', $preview->html);
        $this->assertStringNotContainsString('cid:', $preview->html);
        $this->assertStringContainsString('cid:', $mailable->result->html);
        $this->assertStringNotContainsString('src="/uploads/', $mailable->result->html);

        // The external (non-media-library) image is untouched on EITHER surface — rewriteImages()
        // only ever rewrites a src that resolves to a PublicFile.
        $this->assertStringContainsString('https://cdn.example.com/hero-external.jpg', $preview->html);
        $this->assertStringContainsString('https://cdn.example.com/hero-external.jpg', $mailable->result->html);

        // Once normalized, the two ARE identical — the only real difference was the src scheme.
        $this->assertSame(
            SurfaceNormalizer::normalize($preview->html),
            SurfaceNormalizer::normalize($mailable->result->html)
        );
    }

    /** A fixture with NO image at all must be byte-identical on the raw HTML too (no image
     *  embedding difference to normalize away in the first place). */
    public function test_a_fixture_with_no_images_is_byte_identical_raw_between_preview_and_real_send(): void
    {
        $post = EmailDiffFixtures::load('core-text-and-variables');

        $preview = $this->emailRenderer()->render($post, 'en', preview: true);
        $mailable = new HeisenbergMailable($post->id, 'en');

        $this->assertSame($preview->html, $mailable->result->html, 'no images in this fixture — preview and real send should need no normalization at all to match');
    }

    /** The real send's embeds manifest attaches with the EXACT cid the html references, for every
     *  media-library image across the whole fixture corpus (not just the one HeisenbergMailableTest pins). */
    public function test_every_fixtures_embeds_attach_with_the_exact_referenced_cid(): void
    {
        foreach (EmailDiffFixtures::names() as $name) {
            $post = EmailDiffFixtures::load($name);
            $mailable = new HeisenbergMailable($post->id, 'en');

            foreach ($mailable->result->embeds as $embed) {
                $this->assertStringContainsString('cid:' . $embed['cid'], $mailable->result->html, "fixture '{$name}'");
            }

            $message = new SymfonyEmail();
            foreach ($mailable->callbacks as $callback) {
                $callback($message);
            }
            $this->assertCount(count($mailable->result->embeds), $message->getAttachments(), "fixture '{$name}': one Symfony inline part per embed");
        }
    }

    // ====================================================================
    // 2. The `render` (web) surface as the documented PROXY for "what the
    //    canvas currently shows" — see this class's docblock. Deterministic,
    //    server-side, and unaffected by the concurrently-changing canvas JS.
    // ====================================================================

    /**
     * Pins the exact, already-documented degradations (BlockTreeRenderer::resolveClass()'s own
     * docblock; EmailRendererTest::test_a_gradient_background_degrades_to_its_first_stop_colour_in_email)
     * by rendering the SAME block through both surfaces directly.
     */
    public function test_the_render_surface_and_email_surface_diverge_exactly_where_documented(): void
    {
        $post = EmailDiffFixtures::load('degradations');
        $blocks = $post->blocks->map(fn ($b) => $b->content)->values()->all();

        $renderHtml = $this->blockRenderer()->renderBlocks($blocks, 'en', 'render');
        $emailHtml = $this->emailRenderer()->render($post, 'en', preview: true)->html;

        // INTENDED (align): the web surface carries an hb-align-* class; the email surface drops
        // alignment entirely (no table-based equivalent is authored for it).
        $this->assertMatchesRegularExpression('/hb-align-center/', $renderHtml, 'web surface must carry the align class');
        $this->assertDoesNotMatchRegularExpression('/hb-align-center/', $emailHtml, 'email surface must never carry an hb-align-* class');

        // INTENDED (gradient): the web surface keeps the literal gradient function; the email
        // surface degrades to the first colour stop and never leaks `var(` or `linear-gradient(`.
        $this->assertStringContainsString('linear-gradient', $renderHtml, 'web surface renders the real gradient');
        $this->assertStringNotContainsString('linear-gradient', $emailHtml, 'email surface must degrade the gradient');
        $this->assertStringNotContainsString('var(', $emailHtml);

        // INTENDED (editor-only chrome): hb-supports/hb-flex-layout/data-block-* exist on the web
        // surface (the canvas needs them) and never on the email surface.
        foreach (['hb-supports', 'data-block-name', 'data-block-id'] as $needle) {
            $this->assertStringContainsString($needle, $renderHtml, "web surface should still carry '{$needle}'");
            $this->assertStringNotContainsString($needle, $emailHtml, "email surface must never carry '{$needle}'");
        }
    }

    /** The `embed`/`icon` structural exclusion (§4), proven directly against BOTH surfaces: the
     *  web surface renders them fully, the email surface renders nothing for them at all. */
    public function test_embed_and_icon_render_on_the_web_surface_but_vanish_entirely_on_email(): void
    {
        $post = EmailDiffFixtures::load('nested-layout');
        $blocks = $post->blocks->map(fn ($b) => $b->content)->values()->all();

        $renderHtml = $this->blockRenderer()->renderBlocks($blocks, 'en', 'render');
        $emailHtml = $this->emailRenderer()->render($post, 'en', preview: true)->html;

        $this->assertStringContainsString('youtube', $renderHtml, 'web surface renders the embed block');
        $this->assertStringNotContainsString('youtube', $emailHtml, 'email surface silently skips embed (§4)');

        // The icon block's contract-default icon slug ("feather/star") should surface as markup
        // on the web surface (an <svg>/icon element) and leave no trace at all on email.
        $this->assertStringNotContainsString('feather/star', $emailHtml);
    }

    /** MAX_EMAIL_COLUMNS cap (§4 "capped at 2–3 columns"): four authored columns render as four
     *  on the web surface and are trimmed to three on the email surface. */
    public function test_four_columns_render_uncapped_on_web_but_capped_to_three_on_email(): void
    {
        $post = EmailDiffFixtures::load('nested-layout');
        $blocks = $post->blocks->map(fn ($b) => $b->content)->values()->all();

        $renderHtml = $this->blockRenderer()->renderBlocks($blocks, 'en', 'render');
        $emailHtml = $this->emailRenderer()->render($post, 'en', preview: true)->html;

        $this->assertStringContainsString('Column one text', $renderHtml);
        $this->assertStringContainsString('Column four text', $renderHtml, 'web surface keeps all four authored columns');

        $this->assertStringContainsString('Column one text', $emailHtml);
        $this->assertStringNotContainsString('Column four text', $emailHtml, 'email surface caps at MAX_EMAIL_COLUMNS = 3, dropping the fourth');
    }

    // ====================================================================
    // 3. Opt-in raw dumps for the (separate, one-off) real-canvas jsdom
    //    capture — mirrors tests/Editor/DumpEditorHtmlTest.php's own
    //    "skip unless an env var is set" posture so a default run never
    //    needs Node and this file never pins the canvas's moving output.
    // ====================================================================

    public function test_dump_surfaces_for_offline_canvas_comparison(): void
    {
        $dir = getenv('HB_EMAIL_DIFF_DUMP_DIR');
        if (! $dir) {
            $this->markTestSkipped('Set HB_EMAIL_DIFF_DUMP_DIR to dump editor/preview/mime HTML for the offline jsdom canvas capture.');
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach (EmailDiffFixtures::names() as $name) {
            $post = EmailDiffFixtures::load($name);

            $editorHtml = $this->get("/editor/email/{$post->id}")->getContent();
            file_put_contents($dir . "/{$name}.editor.html", (string) $editorHtml);

            $preview = $this->emailRenderer()->render($post, 'en', preview: true);
            file_put_contents($dir . "/{$name}.preview.html", $preview->html);

            $mailable = new HeisenbergMailable($post->id, 'en');
            file_put_contents($dir . "/{$name}.mime.html", $mailable->result->html);
            file_put_contents($dir . "/{$name}.mime.txt", $mailable->result->text);

            $renderHtml = $this->blockRenderer()->renderBlocks(
                $post->blocks->map(fn ($b) => $b->content)->values()->all(),
                'en',
                'render'
            );
            file_put_contents($dir . "/{$name}.render-surface.html", $renderHtml);
        }

        $this->assertTrue(true);
    }
}

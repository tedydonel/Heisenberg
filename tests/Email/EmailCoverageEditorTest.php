<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Services\EmailBlockCoverageService;
use Heisenberg\Tests\Support\AssertsHtmlStructure;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * docs/email-system.md §4 — the email editor's own visibility into
 * {@see EmailBlockCoverageService}:
 *
 *  - the block palette (Components tab) and the quick-inserter both explain, once, that some
 *    registered blocks aren't offered here because they have no `email` template
 *    (EditorController::paletteBlocks() already excludes them entirely — this note says WHY);
 *  - the Post tab's "Email summary" disclosure reports how many placed blocks will be
 *    dropped entirely, and how many will render differently, once this document is sent.
 *
 * Structural assertions only (AssertsHtmlStructure) — never a literal-markup/JS-source pin.
 */
class EmailCoverageEditorTest extends TestCase
{
    use AssertsHtmlStructure;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();
    }

    private function makeEmail(): Post
    {
        $post = Post::create(['title_en' => 'A Newsletter', 'locale' => 'en']);
        $post->type = 'email';
        $post->save();

        return $post;
    }

    private function addBlock(Post $post, int $order, string $name, array $attributes = [], array $supports = []): Block
    {
        return Block::create([
            'post_id' => $post->id,
            'type' => substr($name, strrpos($name, '/') + 1),
            'content' => [
                'id' => 'b' . $order,
                'name' => $name,
                'schemaVersion' => '1.0.0',
                'attributes' => $attributes,
                'supports' => $supports,
                'innerBlocks' => [],
            ],
            'order' => $order,
        ]);
    }

    // ── palette / quick-inserter (item 2a: before placement) ──────────────────────────
    //
    // The explanatory note that used to sit above the palette was removed at the owner's
    // request (2026-09-20) — the palette already omits a block with no email template
    // entirely (EditorController::paletteBlocks() filters on contractsFor('email')), and the
    // document-level summary below still reports anything incompatible that is already in the
    // document. What remains asserted here is that filtering itself: the email palette must
    // not offer a block the send would silently drop.

    public function test_the_email_palette_does_not_offer_blocks_with_no_email_template(): void
    {
        $uncovered = app(EmailBlockCoverageService::class)->uncoveredBlockNames();
        $this->assertNotEmpty($uncovered, 'expected at least one block with no email template');

        $html = $this->get('/editor/email')->assertOk()->getContent();

        foreach ($uncovered as $name) {
            $this->assertElementMissing($html, '[data-hb-insert-block="' . $name . '"]');
        }
    }

    public function test_a_plain_post_palette_still_offers_every_block(): void
    {
        $uncovered = app(EmailBlockCoverageService::class)->uncoveredBlockNames();

        $html = $this->get('/editor')->assertOk()->getContent();

        foreach ($uncovered as $name) {
            $this->assertElementExists($html, '[data-hb-insert-block="' . $name . '"]');
        }
    }

    // ── document-level summary (item 2b) ────────────────────────────────────────────────

    public function test_an_email_with_an_uncovered_block_reports_it_will_not_appear(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/embed');
        $this->addBlock($post, 1, 'heisenberg/paragraph');

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementTextContains(
            $html,
            '[data-hb-post-meta-value="email_coverage_dropped"]',
            str_replace(':count', '1', __('heisenberg::editor.inspector.summary_email_dropped_value'))
        );
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }

    public function test_an_email_with_a_degraded_block_reports_it_may_render_differently(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/group', [], ['align' => 'wide']);

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
        $this->assertElementTextContains(
            $html,
            '[data-hb-post-meta-value="email_coverage_degraded"]',
            str_replace(':count', '1', __('heisenberg::editor.inspector.summary_email_degraded_value'))
        );
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
    }

    public function test_an_email_without_any_incompatible_blocks_shows_neither_warning_row(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/heading');
        $this->addBlock($post, 1, 'heisenberg/paragraph');

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }

    public function test_a_blank_new_email_document_shows_neither_warning_row(): void
    {
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }

    public function test_a_plain_post_never_shows_email_coverage_rows_even_with_an_email_only_defect(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en']);
        $this->addBlock($post, 0, 'heisenberg/embed');

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }

    public function test_both_warning_rows_can_appear_together(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/embed');
        $this->addBlock($post, 1, 'heisenberg/group', [], ['align' => 'full']);

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }
}

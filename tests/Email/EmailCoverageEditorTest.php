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

    // ── palette / quick-inserter marking (item 2a: before placement) ───────────────────

    public function test_a_blank_email_documents_palette_explains_the_two_missing_blocks(): void
    {
        $html = $this->get('/editor/email')->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-email-uncovered-note]');
        $this->assertElementHasAttribute($html, '[data-hb-email-uncovered-note]', 'data-count', '2');
        $this->assertElementTextContains(
            $html,
            '[data-hb-email-uncovered-note]',
            str_replace(':count', '2', __('heisenberg::editor.panel_components_blocks.email_uncovered_note'))
        );
    }

    public function test_a_plain_post_documents_palette_never_shows_the_email_note(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-email-uncovered-note]');
    }

    // ── document-level summary (item 2b) ────────────────────────────────────────────────

    public function test_an_email_with_an_uncovered_block_reports_it_will_not_appear(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/icon');
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
        $this->addBlock($post, 0, 'heisenberg/group', [], ['align' => 'center']);

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

    public function test_a_plain_post_never_shows_email_coverage_rows_even_with_an_icon_block(): void
    {
        $post = Post::create(['title_en' => 'A Blog Post', 'locale' => 'en']);
        $this->addBlock($post, 0, 'heisenberg/icon');

        $html = $this->get("/editor/{$post->id}")->assertOk()->getContent();

        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementMissing($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }

    public function test_both_warning_rows_can_appear_together(): void
    {
        $post = $this->makeEmail();
        $this->addBlock($post, 0, 'heisenberg/icon');
        $this->addBlock($post, 1, 'heisenberg/group', [], ['align' => 'left']);

        $html = $this->get("/editor/email/{$post->id}")->assertOk()->getContent();

        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_dropped"]');
        $this->assertElementExists($html, '[data-hb-post-meta-value="email_coverage_degraded"]');
    }
}

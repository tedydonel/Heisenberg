<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for the seam this change closes: the editor UI actually talking to the
 * save/load HTTP layer (PostController + SavePostRequest, exercised end-to-end by
 * PostPersistenceTest) instead of never referencing it at all. Distinct from
 * EditorRendersTest (general page-render smoke tests) — these assertions are specifically
 * about the save/autosave wiring live/topbar.blade.php ships, the save-status pill
 * live/footer.blade.php ships, and the /editor/{post} hydration path EditorController::show()
 * + editor/index.blade.php ship together.
 */
class EditorSaveWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same rationale as PostPersistenceTest: force the local-dev authorization bypass
        // (src/Adapters/LocalDevRoleGate.php) so an unauthenticated `postJson` can create a
        // post, and disable CSRF since forcing APP_ENV to 'local' also turns off
        // runningUnitTests(), which would otherwise 419 these requests.
        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registry(): BlockRegistryService
    {
        return app(BlockRegistryService::class);
    }

    /** Creates a real post via the actual save endpoint (not a hand-rolled Eloquent fixture). */
    private function createPost(): array
    {
        $envelope = [
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'autosave' => false,
            'blocks' => [
                [
                    'id' => 'b1',
                    'name' => 'heisenberg/heading',
                    'schemaVersion' => '1.0.0',
                    'attributes' => ['content' => 'Hydrated Heading', 'level' => 3],
                    'supports' => ['align' => 'center'],
                    'innerBlocks' => [],
                ],
            ],
            'title_en' => 'A Hydrated Post',
            'locale' => 'en',
        ];

        $response = $this->postJson('/editor/posts', $envelope);
        $response->assertCreated();

        return $response->json();
    }

    // ── the blank editor is untouched ───────────────────────────────────

    public function test_blank_editor_still_renders_with_no_post(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-post-id=""', $html);
        // Nothing to hydrate on a fresh document — the hydration script only ships
        // when EditorController::show() seeds at least one saved block.
        $this->assertStringNotContainsString('hydrate()', $html);
    }

    // ── the save button + post endpoint URLs are actually shipped ───────

    public function test_editor_ships_the_save_button_and_post_endpoint_urls(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('hb-topbar__save', $html);
        $this->assertStringContainsString(
            'data-hb-save-url="' . route('heisenberg.editor.posts.store') . '"',
            $html
        );
        $this->assertStringContainsString(
            'data-hb-update-url-template="' . route('heisenberg.editor.posts.store') . '/__ID__"',
            $html
        );
        // Every save payload is built through the runtime's own builder — never
        // hand-reconstructed — so it can't drift from what BlocksPayloadService expects.
        $this->assertStringContainsString('window.hbEditor.buildSavePayload(', $html);
        // The event the footer's save-status pill listens for.
        $this->assertStringContainsString("new CustomEvent('hb:save-state'", $html);
        $this->assertStringContainsString("document.addEventListener('hb:blocks-changed', hbMarkDirty)", $html);
    }

    // ── the footer's status pill ships real states, not a permanent danger pill ─

    public function test_footer_ships_the_real_save_status_states(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-save-status', $html);
        foreach (['saved', 'saving', 'dirty', 'offline', 'conflict', 'error'] as $state) {
            $this->assertStringContainsString('data-hb-status-icon="' . $state . '"', $html);
        }
        // The pill's old permanent "Connecting…" text is gone (its only prior use).
        $this->assertStringNotContainsString(__('heisenberg::editor.common.connecting'), $html);
        $this->assertStringContainsString("window.addEventListener('offline'", $html);
    }

    // ── /editor/{post} loads a real, existing post ──────────────────────

    public function test_show_route_renders_an_existing_posts_title_and_hydrates_its_blocks(): void
    {
        $post = $this->createPost();
        $postId = $post['post']['id'];
        $contentVersion = $post['post']['content_version'];

        $html = $this->get("/editor/{$postId}")->assertOk()->getContent();

        $this->assertStringContainsString('data-hb-post-id="' . $postId . '"', $html);
        $this->assertStringContainsString('data-hb-content-version="' . $contentVersion . '"', $html);
        // Seeded into both data-hb-title mirrors (the canvas h1 and the inspector's Post-tab input).
        $this->assertStringContainsString('A Hydrated Post', $html);
        // The saved block tree is shipped to the hydration script.
        $this->assertStringContainsString('hydrate()', $html);
        $this->assertStringContainsString('Hydrated Heading', $html);
    }

    public function test_show_route_404s_for_a_nonexistent_post(): void
    {
        $this->get('/editor/999999')->assertNotFound();
    }

    // ── Summary Status row shows a picked-but-unsaved status as PENDING, not as
    //    already-applied (owner-reported "picked Published, stayed draft", 2026-08-12).
    //    A status pick only rides the NEXT EXPLICIT Save (topbar.blade.php's
    //    hbPendingStatus); autosave never carries it and echoes the post's unchanged
    //    status on every successful tick. Before this fix, post-meta-live-script.blade.php's
    //    hb:post-saved handler unconditionally re-synced the row from that echo, visibly
    //    reverting a just-picked "Published" back to "Draft" the moment an unrelated
    //    autosave completed — even though the pick was still queued internally and would
    //    have applied on the next explicit Save. These pin the fix's wiring in place. ────

    public function test_status_trigger_ships_the_pending_hint_and_pending_data_attribute_hook(): void
    {
        $post = $this->createPost();
        $html = $this->get('/editor/' . $post['post']['id'])->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-hb-status-pending-hint="' . __('heisenberg::editor.inspector.summary_status_pending_hint') . '"',
            $html,
        );
        // The CSS hook the pill/label pending state paints through.
        $this->assertStringContainsString('[data-hb-pending="true"]', $html);
    }

    public function test_status_saved_handler_does_not_clobber_a_still_pending_pick_on_an_unrelated_autosave(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // setStatusPending() is set true the moment a differing status is picked...
        $this->assertStringContainsString("setStatusPending(trigger, true)", $html);
        // ...and hb:post-saved's guard refuses to re-sync the row from a save that
        // didn't actually carry the pending pick (autosave never does).
        $this->assertStringContainsString("trigger.dataset.hbPending === 'true'", $html);
        $this->assertStringContainsString("if (!stillPendingElsewhere) applyConfirmedStatus(", $html);
    }

    // ── Slug and Publish-date rows have the SAME latent bug Status just had fixed
    //    (2026-08-12 follow-up): both are also "queued for the next explicit Save only"
    //    (applySlug()/applyPublishedAt() are both autosave-skipped server-side), so the
    //    same hb:post-saved echo could revert a typed-but-unsaved value mid-edit. These
    //    pin the same generalised setRowPending() treatment for both rows. ────────────

    public function test_slug_and_publish_triggers_ship_their_pending_hints_and_the_shared_pending_hook(): void
    {
        $post = $this->createPost();
        $html = $this->get('/editor/' . $post['post']['id'])->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-hb-slug-pending-hint="' . __('heisenberg::editor.inspector.summary_slug_pending_hint') . '"',
            $html,
        );
        $this->assertStringContainsString(
            'data-hb-publish-pending-hint="' . __('heisenberg::editor.inspector.summary_publish_pending_hint') . '"',
            $html,
        );
    }

    public function test_slug_saved_handler_does_not_clobber_a_still_pending_slug_on_an_unrelated_autosave(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Queued the moment a typed slug differs from the committed one...
        $this->assertStringContainsString(
            "setRowPending(document.querySelector('[data-hb-post-popup-trigger=\"slug\"]'), typed !== committed, 'hbSlugPendingHint', typed)",
            $html,
        );
        // ...and hb:post-saved's guard refuses to re-sync the row (and the mirrored
        // SEO-panel field) from an autosave echo that never carried the slug edit.
        $this->assertStringContainsString('slugStillPendingElsewhere', $html);
        $this->assertStringContainsString('if (!slugStillPendingElsewhere) applyConfirmedSlug(post.slug);', $html);
    }

    public function test_published_at_saved_handler_does_not_clobber_a_still_pending_date_on_an_unrelated_autosave(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // Queued the moment a picked date differs from the committed one...
        $this->assertStringContainsString(
            "setRowPending(pubTrigger, value !== committed, 'hbPublishPendingHint', value)",
            $html,
        );
        // ...and hb:post-saved's guard refuses to re-sync the row from an autosave echo
        // that never carried the published_at edit.
        $this->assertStringContainsString('pubStillPendingElsewhere', $html);
        $this->assertStringContainsString('if (!pubStillPendingElsewhere) applyConfirmedPublishedAt(pubValue);', $html);
    }
}

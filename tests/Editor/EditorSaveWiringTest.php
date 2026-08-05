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
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for the seam this change closes: the topbar's eye/"Preview" button
 * (resources/views/components/live/topbar.blade.php) did nothing — no
 * data-hb-* hook, no handler. It now opens the current document in a new tab,
 * rendered server-side through the real BlockRenderer, via editor-scoped
 * routes (routes/editor.php) reaching PreviewController under
 * config('heisenberg.middleware.editor'). These used to also be reachable
 * under the builder's own route group and gate before the builder's removal
 * (2026-08-02, see TODO.md) — routes/editor.php's copy existed specifically
 * so /editor never depended on that group, which is why only it remains.
 *
 * Two flows, both exercised here:
 *  - never-saved document: POST /editor/preview (session-scoped, size-capped)
 *    then GET /editor/preview renders it through BlockRenderer.
 *  - an already-saved post: GET /editor/{post}/preview renders straight from
 *    the DB's stored block tree (PreviewController::showPost) — no session
 *    round-trip.
 *
 * Same local-dev authorization bypass rationale as PostPersistenceTest /
 * EditorSaveWiringTest: creating a post via an unauthenticated request needs
 * app()->environment('local') (see LocalDevRoleGate), which also flips off
 * runningUnitTests() — so CSRF is disabled explicitly instead.
 */
class EditorPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registry(): BlockRegistryService
    {
        return app(BlockRegistryService::class);
    }

    /** Creates a real post via the actual save endpoint (not a hand-rolled Eloquent fixture). */
    private function createPost(string $content = 'Hello from a saved post'): array
    {
        $envelope = [
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'autosave' => false,
            'blocks' => [
                [
                    'id' => 'b1',
                    'name' => 'heisenberg/paragraph',
                    'schemaVersion' => '1.0.0',
                    'attributes' => ['content' => $content],
                    'supports' => [],
                    'innerBlocks' => [],
                ],
            ],
            'title_en' => 'A Previewable Post',
            'locale' => 'en',
        ];

        $response = $this->postJson('/editor/posts', $envelope);
        $response->assertCreated();

        return $response->json();
    }

    // ── the editor page ships the preview wiring ────────────────────────

    public function test_editor_page_ships_the_preview_button_and_its_wiring(): void
    {
        $html = $this->get('/editor')->assertOk()->getContent();

        // The button itself: the eye icon in the topbar's center cluster now carries a real hook.
        // (Not a bare assertStringContainsString('data-hb-preview', ...) — that substring is also
        // a prefix of the data-hb-preview-store-url/-show-url/-post-url-template attributes below,
        // which would pass even if the button's own bare attribute never rendered.)
        $this->assertMatchesRegularExpression('/data-hb-preview(?!-)/', $html);

        // Editor-scoped URLs — never a /builder/... URL.
        $this->assertStringContainsString(
            'data-hb-preview-store-url="' . route('heisenberg.editor.preview.store') . '"',
            $html
        );
        $this->assertStringContainsString(
            'data-hb-preview-show-url="' . route('heisenberg.editor.preview') . '"',
            $html
        );
        $this->assertStringContainsString(
            'data-hb-preview-post-url-template="' . route('heisenberg.editor.index') . '/__ID__/preview"',
            $html
        );
        $this->assertStringNotContainsString('/builder/preview', $html);

        // The tab is opened synchronously (popup-blocker avoidance) before any fetch.
        $this->assertStringContainsString("window.open('about:blank', '_blank')", $html);
        // The doc is read through the public runtime API, never the frozen internals directly.
        $this->assertStringContainsString('window.hbEditor.getDoc()', $html);
    }

    // ── never-saved document: session round trip ────────────────────────

    public function test_unsaved_document_preview_stores_then_renders_through_block_renderer(): void
    {
        $doc = [
            'title' => 'A Draft Nobody Saved',
            'blocks' => [
                [
                    'id' => 'b1',
                    'name' => 'heisenberg/paragraph',
                    'attributes' => ['content' => 'Hello <strong onclick="alert(1)">world</strong>'],
                    'supports' => [],
                    'innerBlocks' => [],
                ],
            ],
        ];

        $store = $this->postJson('/editor/preview', $doc);
        $store->assertOk();
        $this->assertTrue($store->json('stored'));

        $page = $this->get(route('heisenberg.editor.preview'));
        $page->assertOk();
        $html = (string) $page->getContent();

        $this->assertStringContainsString('A Draft Nobody Saved', $html);
        // Rendered through the real, sanitizing BlockRenderer — markup kept, handlers scrubbed.
        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    // ── rejection cases PreviewController already intends ───────────────

    public function test_oversized_payload_is_rejected_with_413(): void
    {
        $huge = str_repeat('a', 1024 * 1024 + 1);
        $response = $this->call('POST', '/editor/preview', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{"blocks":[],"pad":"' . $huge . '"}');

        $response->assertStatus(413);
    }

    public function test_malformed_payload_is_rejected_with_422(): void
    {
        $this->postJson('/editor/preview', ['nope' => true])->assertStatus(422);
    }

    // ── already-saved post: DB-backed preview, no session round trip ────

    public function test_saved_post_preview_renders_straight_from_the_database(): void
    {
        $post = $this->createPost('Content only ever reaches the DB, never the session');
        $postId = $post['post']['id'];

        // Deliberately never touches /editor/preview (the session flow) — proves showPost()
        // renders from the post's own stored block tree, not a session round trip.
        $page = $this->get("/editor/{$postId}/preview");

        $page->assertOk();
        $html = (string) $page->getContent();

        $this->assertStringContainsString('A Previewable Post', $html);
        $this->assertStringContainsString('Content only ever reaches the DB, never the session', $html);
        // Proof it went through the real BlockRenderer (block CSS class), not a client re-render.
        $this->assertStringContainsString('hb-block-paragraph', $html);
    }

    public function test_saved_post_preview_404s_for_a_nonexistent_post(): void
    {
        $this->get('/editor/999999/preview')->assertNotFound();
    }

    /**
     * The featured image renders above the title when one is set (2026-08-10:
     * Post::featuredImage + PostSettingsController::updateFeaturedImage +
     * preview.blade.php's figure), and the figure is absent when none is.
     */
    public function test_saved_post_preview_renders_the_featured_image_when_set(): void
    {
        $post = $this->createPost('Body text');
        $postId = $post['post']['id'];

        // Match the MARKUP, not the class name — the stylesheet always carries
        // the .hb-preview-featured rules regardless of whether a figure renders.
        $without = (string) $this->get("/editor/{$postId}/preview")->assertOk()->getContent();
        $this->assertStringNotContainsString('<figure class="hb-preview-featured"', $without);

        $file = \Heisenberg\Models\PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/hero.jpg',
            'original_name' => 'hero.jpg',
            'stored_name' => 'hero.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
            'alt_text_en' => 'A hero image',
        ]);
        $this->putJson("/editor/posts/{$postId}/featured-image", ['featured_image_id' => $file->id])->assertOk();

        $with = (string) $this->get("/editor/{$postId}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('<figure class="hb-preview-featured"', $with);
        $this->assertStringContainsString($file->url, $with);
        $this->assertStringContainsString('alt="A hero image"', $with);
    }

    /**
     * The AUTHORED table of contents (Post::tocEntries(), PostSettingsController::updateToc(),
     * 2026-08-10) renders as a <nav> above the body ONLY when the post has entries — it must
     * never appear for a post nobody added one to.
     */
    public function test_saved_post_preview_renders_the_table_of_contents_when_entries_exist(): void
    {
        $post = $this->createPost('Body text');
        $postId = $post['post']['id'];

        // Match the MARKUP, not the class name — the stylesheet always carries the
        // .hb-preview-toc rules regardless of whether a <nav> renders (same reasoning as the
        // featured-image test above).
        $without = (string) $this->get("/editor/{$postId}/preview")->assertOk()->getContent();
        $this->assertStringNotContainsString('<nav class="hb-preview-toc"', $without);

        $this->putJson("/editor/posts/{$postId}/toc", [
            'entries' => [
                ['label' => 'Introduction', 'anchor' => 'introduction'],
                ['label' => 'Deep Dive', 'anchor' => 'deep-dive'],
            ],
        ])->assertOk();

        $with = (string) $this->get("/editor/{$postId}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="hb-preview-toc"', $with);
        $this->assertStringContainsString('href="#introduction"', $with);
        $this->assertStringContainsString('>Introduction<', $with);
        $this->assertStringContainsString('href="#deep-dive"', $with);
        $this->assertStringContainsString('>Deep Dive<', $with);
    }

    /**
     * Bug A persistence proof (2026-08-13): a gradient background set on a block survives the
     * FULL round trip — real HTTP save (POST /editor/posts) -> the database -> a fresh reload
     * (showPost() reads straight off the saved Block rows, no session) -> the sanitizing
     * BlockRenderer. Before the fix, `color-value` silently dropped any `linear-gradient(...)`
     * at render time no matter what was saved, so this would have rendered the variable's
     * empty default instead.
     */
    public function test_saved_post_preview_renders_a_gradient_background_set_on_a_block(): void
    {
        $gradient = 'linear-gradient(45deg, #ffffff 0%, #000000 100%)';
        $envelope = [
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'autosave' => false,
            'blocks' => [
                [
                    'id' => 'b1',
                    'name' => 'heisenberg/group',
                    'schemaVersion' => '1.0.0',
                    'attributes' => [],
                    'supports' => ['color' => ['background' => $gradient]],
                    'innerBlocks' => [],
                ],
            ],
            'title_en' => 'A Gradient Post',
            'locale' => 'en',
        ];

        $store = $this->postJson('/editor/posts', $envelope);
        $store->assertCreated();
        $postId = $store->json('post.id');

        $html = (string) $this->get("/editor/{$postId}/preview")->assertOk()->getContent();

        $this->assertStringContainsString('--hb-group-bg: ' . $gradient, $html);
    }

    public function test_post_scoped_preview_route_url_matches_the_editor_path(): void
    {
        $this->assertSame(
            url('/editor/42/preview'),
            route('heisenberg.editor.preview.post', ['post' => 42])
        );
    }
}

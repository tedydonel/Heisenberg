<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Owner-reported bug (2026-08-12): the Summary section's Status control never offered
 * `published` or `scheduled` — config('heisenberg.lifecycle.transitions')['draft'] had no
 * edge to either, so an admin had NO way to publish a post from the editor at all. Fixed
 * by widening the transitions graph (config/heisenberg.php's `lifecycle.transitions`, see
 * its own comment for the per-edge justification) while leaving `role_permissions` as the
 * sole authority on WHO may land on a given status.
 *
 * These tests pin the NEW edges' behavior specifically; PostPersistenceTest.php already
 * covers the pre-existing transition-guard machinery (409/403/422 plumbing, autosave
 * bypass, reschedule-in-place, etc.) this reuses rather than duplicates.
 */
class LifecycleTransitionsTest extends TestCase
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

    /** @param array<int, array<string, mixed>> $blocks */
    private function envelope(array $blocks, array $overrides = []): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'autosave' => false,
            'blocks' => $blocks,
            'title_en' => 'A Test Post',
            'locale' => 'en',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function block(string $name, array $attributes = [], array $overrides = []): array
    {
        return array_merge([
            'id' => 'b1',
            'name' => $name,
            'schemaVersion' => '1.0.0',
            'attributes' => $attributes,
            'supports' => [],
            'innerBlocks' => [],
        ], $overrides);
    }

    private function createDraft(Authenticatable $actor, string $title = 'Lifecycle Test'): array
    {
        $this->actingAs($actor);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => $title],
        ));
        $store->assertCreated();

        return ['id' => $store->json('post.id'), 'version' => $store->json('post.content_version')];
    }

    // ── draft -> published (the reported bug) ───────────────────────────

    public function test_draft_to_published_succeeds_for_an_editor_and_stamps_published_at(): void
    {
        $editor = new LifecycleFakeActor(1, 'editor');
        $post = $this->createDraft($editor);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'published'],
        ));

        $response->assertOk();
        $this->assertSame('published', $response->json('post.status'));

        $fresh = Post::find($post['id']);
        $this->assertSame('published', $fresh->status);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_draft_to_published_succeeds_for_an_admin_actor(): void
    {
        $admin = new LifecycleFakeActor(2, 'admin');
        $post = $this->createDraft($admin);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'published'],
        ));

        $response->assertOk();
        $this->assertSame('published', Post::find($post['id'])->status);
    }

    /** The edge now exists, but role_permissions still gates WHO may use it — an author cannot. */
    public function test_draft_to_published_is_403_for_an_author_tier_actor(): void
    {
        $author = new LifecycleFakeActor(3, 'author');
        $post = $this->createDraft($author);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'published'],
        ));

        $response->assertStatus(403);
        $this->assertSame('draft', Post::find($post['id'])->status);
    }

    // ── H1 — what the client actually experiences when a status-bearing save
    //    is rejected (owner-reported "picked Published, stayed draft" investigation,
    //    2026-08-12). Proves the transition never silently half-applies: a stale
    //    content_version or a stale registryHash both reject the WHOLE request,
    //    including the queued status, with the post left exactly as it was. ──────

    public function test_a_status_change_riding_a_stale_content_version_409s_and_does_not_publish(): void
    {
        $editor = new LifecycleFakeActor(9, 'editor');
        $post = $this->createDraft($editor);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Lifecycle Test',
                'content_version' => (int) $post['version'] + 41, // deliberately wrong
                'status' => 'published',
            ],
        ));

        $response->assertStatus(409);
        $this->assertSame(
            'This post was changed elsewhere — reload and try again.',
            $response->json('message'),
            'this is exactly the message hbEmitSaveState("conflict", …) surfaces via the footer pill',
        );

        $fresh = Post::find($post['id']);
        $this->assertSame('draft', $fresh->status, 'a version conflict must reject the queued transition too, not just the content');
        $this->assertNull($fresh->published_at);
    }

    public function test_a_status_change_riding_a_stale_registry_hash_422s_and_does_not_publish(): void
    {
        $editor = new LifecycleFakeActor(10, 'editor');
        $post = $this->createDraft($editor);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Lifecycle Test',
                'content_version' => $post['version'],
                'status' => 'published',
                'registryHash' => 'sha256:stale-does-not-match-the-live-registry',
            ],
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('registryHash', $response->json('errors') ?? []);
        // topbar.blade.php's error branch would join every errors[] message it finds —
        // this is the exact string an owner would see on save-state 'error'.
        $this->assertStringContainsString(
            'registryHash does not match the live block registry',
            implode(' ', $response->json('errors.registryHash') ?? []),
        );

        $fresh = Post::find($post['id']);
        $this->assertSame('draft', $fresh->status, 'a registry-hash rejection must reject the queued transition too, not just the content');
        $this->assertNull($fresh->published_at);
    }

    // ── draft -> scheduled (the other half of the reported bug) ─────────

    public function test_draft_to_scheduled_with_a_future_datetime_succeeds(): void
    {
        $editor = new LifecycleFakeActor(4, 'editor');
        $post = $this->createDraft($editor);

        $future = now()->addDays(3)->second(0)->toDateTimeString();
        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'scheduled', 'scheduled_at' => $future],
        ));

        $response->assertOk();
        $this->assertSame('scheduled', $response->json('post.status'));

        $fresh = Post::find($post['id']);
        $this->assertSame('scheduled', $fresh->status);
        $this->assertSame($future, $fresh->scheduled_at->toDateTimeString());
    }

    public function test_draft_to_scheduled_with_a_past_datetime_is_422(): void
    {
        $editor = new LifecycleFakeActor(5, 'editor');
        $post = $this->createDraft($editor);

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Lifecycle Test', 'content_version' => $post['version'],
                'status' => 'scheduled', 'scheduled_at' => now()->subDay()->toDateTimeString(),
            ],
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('scheduled_at', $response->json('errors') ?? []);

        $fresh = Post::find($post['id']);
        $this->assertSame('draft', $fresh->status, 'a rejected transition must not move the status');
        $this->assertNull($fresh->scheduled_at);
    }

    // ── published -> draft (unpublish) ───────────────────────────────────

    public function test_published_to_draft_unpublish_works_for_the_allowed_tier(): void
    {
        $editor = new LifecycleFakeActor(6, 'editor');
        $post = $this->createDraft($editor);

        $published = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'published'],
        ));
        $published->assertOk();
        $version = $published->json('post.content_version');

        $unpublished = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $version, 'status' => 'draft'],
        ));

        $unpublished->assertOk();
        $this->assertSame('draft', $unpublished->json('post.status'));
        $this->assertSame('draft', Post::find($post['id'])->status);
    }

    // ── an edge deliberately NOT added stays 422 ─────────────────────────

    /**
     * `published -> scheduled` is deliberately absent from the graph (config's comment:
     * "reschedule a live post" isn't a real single step) — pinned here so the map's SHAPE
     * is asserted, not just its new permissiveness. Even an admin actor gets 422.
     */
    public function test_published_to_scheduled_stays_422_even_for_an_admin(): void
    {
        $admin = new LifecycleFakeActor(7, 'admin');
        $post = $this->createDraft($admin);

        $published = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Lifecycle Test', 'content_version' => $post['version'], 'status' => 'published'],
        ));
        $published->assertOk();
        $version = $published->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$post['id']}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Lifecycle Test', 'content_version' => $version,
                'status' => 'scheduled', 'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ],
        ));

        $response->assertStatus(422);
        $this->assertSame('published', Post::find($post['id'])->status);
    }

    // ── the actual user-visible bug: seeded Status options for a draft post ──

    /**
     * The Summary Status control's option list comes from EditorController::postMeta(),
     * walking config('heisenberg.lifecycle.transitions') — asserted here via the rendered
     * editor page's `data-hb-post-status-option` markers (inspector/post-title-summary.blade.php),
     * NOT by re-deriving the config in the test, so this fails exactly the way the reported
     * bug did if the config edge ever regresses.
     */
    public function test_seeded_status_options_for_a_draft_post_include_published_and_scheduled(): void
    {
        $editor = new LifecycleFakeActor(8, 'editor');
        $post = $this->createDraft($editor);

        $page = $this->get("/editor/{$post['id']}");

        $page->assertOk();
        $page->assertSee('data-hb-post-status-option="published"', false);
        $page->assertSee('data-hb-post-status-option="scheduled"', false);
    }
}

/**
 * A minimal, real (non-GuestActor) Authenticatable stand-in — same shape as
 * PostPersistenceTest's own FakeActor, kept as a separate class here so this
 * file has no load-order dependency on that one.
 */
final class LifecycleFakeActor implements Authenticatable
{
    public function __construct(public int $id, public string $role)
    {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return '';
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the HTTP save/load layer (PostController +
 * SavePostRequest + PostPolicy) — routes/editor.php had zero POST/PUT/DELETE
 * routes before this change; these tests exercise the new
 * /editor/posts[/{post}] surface exactly as the editor's client runtime will.
 *
 * Like ThemeAndFontsTest, most of these force APP_ENV to 'local' so the SAME
 * local-dev authorization bypass (src/Adapters/LocalDevRoleGate.php) that
 * makes `/editor` usable out of the box also covers a plain, unauthenticated
 * `testbench serve` session saving a post — and, same as that test class,
 * that also flips `runningUnitTests()` off, so Laravel's CSRF middleware
 * would otherwise 419 these unauthenticated JSON requests; disabled
 * explicitly instead.
 */
class PostPersistenceTest extends TestCase
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

    public function test_store_then_show_round_trips_a_block_tree(): void
    {
        $blocks = [
            $this->block('heisenberg/heading', ['content' => 'Hello', 'level' => 2], ['id' => 'b1']),
            $this->block('heisenberg/paragraph', ['content' => 'World'], ['id' => 'b2']),
        ];

        $store = $this->postJson('/editor/posts', $this->envelope($blocks, ['title_en' => 'Round Trip']));

        $store->assertCreated();
        $this->assertSame('Round Trip', $store->json('post.title_en'));
        $this->assertSame(1, $store->json('post.content_version'));
        $this->assertSame($blocks, $store->json('blocks'));

        $postId = $store->json('post.id');
        $show = $this->getJson("/editor/posts/{$postId}");

        $show->assertOk();
        $this->assertSame('Round Trip', $show->json('post.title_en'));
        $this->assertSame($blocks, $show->json('blocks'));
    }

    public function test_store_rejects_an_invalid_payload_with_422_and_an_error_map(): void
    {
        $payload = $this->envelope([$this->block('heisenberg/nope')], ['title_en' => 'Bad']);

        $response = $this->postJson('/editor/posts', $payload);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect(array_keys($errors))->contains(fn ($key) => str_starts_with((string) $key, 'blocks.0')),
            'expected a blocks.0.* error key, got: ' . implode(', ', array_keys($errors))
        );
        $this->assertSame(0, Post::count());
    }

    public function test_update_returns_409_on_a_stale_content_version(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'v1'])],
            ['title_en' => 'Stale Test'],
        ));
        $store->assertCreated();

        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $update = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'v2'])],
            ['title_en' => 'Stale Test', 'content_version' => $version - 1],
        ));

        $update->assertStatus(409);

        $fresh = Post::find($postId);
        $this->assertSame('Stale Test', $fresh->title_en);
        $this->assertSame($version, $fresh->content_version, 'a rejected save must not bump the version');
        $this->assertSame('v1', $fresh->blocks()->first()->content['attributes']['content']);
    }

    public function test_update_denied_for_an_unauthorized_actor(): void
    {
        // Created via the local-dev guest bypass — author_id is null.
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'hi'])],
            ['title_en' => 'Owned By No One'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        // A REAL, authenticated actor is never affected by LocalDevRoleGate
        // in any environment — moving outside 'local' just makes that
        // explicit. `author` is an `authors`-tier role, never `admins`,
        // and doesn't own this post (its author_id is null, never matched by
        // PostPolicy's ownership check), so PostPolicy::update() must deny it.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(999, 'author'));

        $update = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'changed'])],
            ['title_en' => 'Owned By No One', 'content_version' => $version],
        ));

        $update->assertStatus(403);

        $fresh = Post::find($postId);
        $this->assertSame($version, $fresh->content_version);
        $this->assertSame('hi', $fresh->blocks()->first()->content['attributes']['content']);
    }

    public function test_autosave_does_not_trip_the_lifecycle_guard(): void
    {
        // `author` is `authors`-tier only — config('heisenberg.lifecycle
        // .role_permissions')['published'] is `editors`, so this actor could
        // NEVER move a post to `published` through a real (non-autosave)
        // transition. Owns the post it creates, so the CONTENT save itself
        // is authorized either way — isolating the assertion to the
        // lifecycle guard specifically.
        $author = new FakeActor(42, 'author');
        $this->actingAs($author);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'draft body'])],
            ['title_en' => 'Autosave Test'],
        ));
        $store->assertCreated();
        $this->assertSame(42, $store->json('post.author_id'));
        $this->assertSame('draft', $store->json('post.status'));

        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $autosave = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'autosaved body'])],
            ['title_en' => 'Autosave Test', 'content_version' => $version, 'autosave' => true, 'status' => 'published'],
        ));

        $autosave->assertOk();
        $this->assertSame('draft', $autosave->json('post.status'), 'a stray status must be ignored outright, not merely denied, during autosave');

        $fresh = Post::find($postId);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame('autosaved body', $fresh->blocks()->first()->content['attributes']['content']);
    }

    /**
     * Contrast for the autosave test above: for the SAME non-admin actor,
     * with autosave OFF, a status change that IS a legal edge from `draft`
     * (config('heisenberg.lifecycle.transitions')['draft'] includes
     * `archived`) but requires a tier this actor lacks
     * (role_permissions['archived'] === 'editors') must actually be
     * rejected — proof the guard is real and not merely always a no-op.
     * (`published` is deliberately NOT used here — draft -> published isn't
     * even a legal edge, which would 422 for an unrelated reason; see
     * test_an_illegal_transition_edge_is_rejected_with_422_even_for_an_admin.)
     */
    public function test_non_autosave_status_transition_still_requires_the_correct_tier(): void
    {
        $author = new FakeActor(42, 'author');
        $this->actingAs($author);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'draft body'])],
            ['title_en' => 'Guarded Test'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'draft body'])],
            ['title_en' => 'Guarded Test', 'content_version' => $version, 'autosave' => false, 'status' => 'archived'],
        ));

        $response->assertStatus(403);

        $fresh = Post::find($postId);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame($version, $fresh->content_version, 'a rejected transition must not bump the version or write blocks');
    }

    /** A legal edge (draft -> pending_review) with the tier it requires (`authors`) succeeds. */
    public function test_a_transition_the_actor_is_authorized_for_succeeds(): void
    {
        $author = new FakeActor(7, 'author');
        $this->actingAs($author);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Promotable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Promotable', 'content_version' => $version, 'status' => 'pending_review'],
        ));

        $response->assertOk();
        $this->assertSame('pending_review', $response->json('post.status'));
        $this->assertSame('pending_review', Post::find($postId)->status);
    }

    /**
     * An edge that isn't in the transitions graph at all is a 422, independent of tier.
     * `draft -> published` used to be this test's example, but the 2026-08-12 lifecycle
     * fix (owner bug: an admin had no way to publish a post) deliberately ADDED that edge
     * — see config/heisenberg.php's `lifecycle.transitions` comment — so this now exercises
     * an edge that was deliberately left OUT instead: `pending_review -> archived`
     * (a reviewer rejects back to `draft`, they don't archive outright).
     */
    public function test_an_illegal_transition_edge_is_rejected_with_422_even_for_an_admin(): void
    {
        $admin = new FakeActor(1, 'admin');
        $this->actingAs($admin);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Skip Ahead'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $this->promoteToPendingReview($admin, $postId, $store->json('post.content_version'));

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Skip Ahead', 'content_version' => $version, 'status' => 'archived'],
        ));

        $response->assertStatus(422);
        $this->assertSame('pending_review', Post::find($postId)->status);
    }

    /** Advances a fresh draft to `pending_review`, the only legal edge into `scheduled`. */
    private function promoteToPendingReview(FakeActor $actor, int|string $postId, int $version): int
    {
        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Schedulable', 'content_version' => $version, 'status' => 'pending_review'],
        ));
        $response->assertOk();

        return (int) $response->json('post.content_version');
    }

    public function test_a_transition_to_scheduled_stores_the_provided_future_datetime(): void
    {
        $admin = new FakeActor(1, 'admin');
        $this->actingAs($admin);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Schedulable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $this->promoteToPendingReview($admin, $postId, $store->json('post.content_version'));

        $scheduledAt = now()->addDays(3)->second(0)->toDateTimeString();

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Schedulable', 'content_version' => $version, 'status' => 'scheduled', 'scheduled_at' => $scheduledAt],
        ));

        $response->assertOk();
        $this->assertSame('scheduled', $response->json('post.status'));
        $this->assertNotNull($response->json('post.scheduled_at'));

        $fresh = Post::find($postId);
        $this->assertSame('scheduled', $fresh->status);
        $this->assertSame($scheduledAt, $fresh->scheduled_at->toDateTimeString());
    }

    public function test_scheduling_with_a_past_datetime_is_rejected_with_422_and_leaves_status_untouched(): void
    {
        $admin = new FakeActor(1, 'admin');
        $this->actingAs($admin);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Not Schedulable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $this->promoteToPendingReview($admin, $postId, $store->json('post.content_version'));

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Not Schedulable', 'content_version' => $version,
                'status' => 'scheduled', 'scheduled_at' => now()->subDay()->toDateTimeString(),
            ],
        ));

        $response->assertStatus(422);
        $this->assertArrayHasKey('scheduled_at', $response->json('errors') ?? []);

        $fresh = Post::find($postId);
        $this->assertSame('pending_review', $fresh->status, 'a rejected transition must not move the status');
        $this->assertNull($fresh->scheduled_at);
        $this->assertSame($version, $fresh->content_version, 'a rejected transition must not bump the version or write blocks');
    }

    /**
     * `scheduled -> scheduled` isn't an edge in the transitions graph (see config's
     * `lifecycle.transitions`), but PostController deliberately still treats a resent
     * `status: scheduled` on an already-scheduled post as a reschedule — the Summary's
     * datetime field stays editable after the post is already scheduled, and a changed
     * date must actually persist rather than silently no-op.
     */
    public function test_rescheduling_an_already_scheduled_post_updates_the_datetime(): void
    {
        $admin = new FakeActor(1, 'admin');
        $this->actingAs($admin);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Reschedulable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $this->promoteToPendingReview($admin, $postId, $store->json('post.content_version'));

        $firstDate = now()->addDays(2)->second(0)->toDateTimeString();
        $scheduled = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Reschedulable', 'content_version' => $version, 'status' => 'scheduled', 'scheduled_at' => $firstDate],
        ));
        $scheduled->assertOk();
        $version = $scheduled->json('post.content_version');

        $secondDate = now()->addDays(5)->second(0)->toDateTimeString();
        $rescheduled = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Reschedulable', 'content_version' => $version, 'status' => 'scheduled', 'scheduled_at' => $secondDate],
        ));

        $rescheduled->assertOk();
        $this->assertSame('scheduled', $rescheduled->json('post.status'));

        $fresh = Post::find($postId);
        $this->assertSame('scheduled', $fresh->status);
        $this->assertSame($secondDate, $fresh->scheduled_at->toDateTimeString());
    }

    // ── editable slug (Summary's URL row, 2026-08-11) ───────────────────────

    public function test_a_slug_change_persists(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Slug Test'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Slug Test', 'content_version' => $version, 'slug' => 'my-custom-slug'],
        ));

        $response->assertOk();
        $this->assertSame('my-custom-slug', $response->json('post.slug'));
        $this->assertSame('my-custom-slug', Post::find($postId)->slug);
    }

    public function test_a_duplicate_slug_is_rejected_with_422(): void
    {
        $first = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'First Post'],
        ));
        $first->assertCreated();
        $firstSlug = $first->json('post.slug');

        $second = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Second Post'],
        ));
        $second->assertCreated();
        $secondId = $second->json('post.id');
        $secondVersion = $second->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$secondId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Second Post', 'content_version' => $secondVersion, 'slug' => $firstSlug],
        ));

        $response->assertStatus(422);
        $this->assertSame('That slug is already in use.', $response->json('message'));
        $this->assertNotSame($firstSlug, Post::find($secondId)->slug);
    }

    public function test_an_empty_slug_regenerates_from_the_title(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Original Title'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        // Give it a custom slug first, so regeneration below is actually proven to discard it.
        $custom = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Original Title', 'content_version' => $version, 'slug' => 'a-custom-slug'],
        ));
        $custom->assertOk();
        $version = $custom->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Retitled Post', 'content_version' => $version, 'slug' => ''],
        ));

        $response->assertOk();
        $this->assertSame('retitled-post', $response->json('post.slug'));
    }

    public function test_a_custom_slug_survives_a_later_title_only_edit(): void
    {
        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Sticky Slug Post'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $custom = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Sticky Slug Post', 'content_version' => $version, 'slug' => 'hand-picked-slug'],
        ));
        $custom->assertOk();
        $this->assertSame('hand-picked-slug', $custom->json('post.slug'));
        $version = $custom->json('post.content_version');

        // Title-only edit — no `slug` key at all in this request.
        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'A Brand New Title', 'content_version' => $version],
        ));

        $response->assertOk();
        $this->assertSame(
            'hand-picked-slug',
            $response->json('post.slug'),
            'an explicitly-set slug must survive a later title-only edit',
        );
    }

    // ── editable post date (Summary's Publish row, 2026-08-11) ─────────────

    public function test_backdating_a_published_posts_date_persists(): void
    {
        $editor = new FakeActor(1, 'editor');
        $this->actingAs($editor);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Backdatable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $toReview = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Backdatable', 'content_version' => $version, 'status' => 'pending_review'],
        ));
        $toReview->assertOk();
        $version = $toReview->json('post.content_version');

        $published = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Backdatable', 'content_version' => $version, 'status' => 'published'],
        ));
        $published->assertOk();
        $version = $published->json('post.content_version');

        $backdate = now()->subYear()->second(0)->toDateTimeString();
        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Backdatable', 'content_version' => $version, 'published_at' => $backdate],
        ));

        $response->assertOk();
        $this->assertSame($backdate, Post::find($postId)->published_at->toDateTimeString());
    }

    /**
     * The CRITICAL interaction: a same-save transition to `published` must never clobber a
     * preset/backdated `published_at` that rode the SAME request — applyPublishedAt() sets the
     * date before applyTransition() ever looks at it, so applyTransition()'s own "stamp now()
     * only when null" publish logic finds it already non-null.
     */
    public function test_a_preset_date_on_a_draft_survives_the_publish_transition(): void
    {
        $editor = new FakeActor(1, 'editor');
        $this->actingAs($editor);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Presettable'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $preset = now()->subMonth()->second(0)->toDateTimeString();
        $withPreset = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Presettable', 'content_version' => $version, 'published_at' => $preset],
        ));
        $withPreset->assertOk();
        $this->assertSame($preset, Post::find($postId)->published_at->toDateTimeString());
        $version = $withPreset->json('post.content_version');

        $toReview = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Presettable', 'content_version' => $version, 'status' => 'pending_review'],
        ));
        $toReview->assertOk();
        $version = $toReview->json('post.content_version');

        // Same save carries BOTH the transition AND the (unchanged) preset date — proving the
        // preset wins regardless of ordering, not just when published_at is omitted outright.
        $published = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Presettable', 'content_version' => $version, 'status' => 'published', 'published_at' => $preset],
        ));

        $published->assertOk();
        $this->assertSame('published', $published->json('post.status'));
        $this->assertSame(
            $preset,
            Post::find($postId)->published_at->toDateTimeString(),
            'publishing must preserve a preset date, not stamp now()',
        );
    }

    public function test_an_author_tier_actor_cannot_set_published_at(): void
    {
        $author = new FakeActor(42, 'author');
        $this->actingAs($author);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Guarded Date'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Guarded Date', 'content_version' => $version, 'published_at' => now()->toDateTimeString()],
        ));

        $response->assertStatus(403);
        $this->assertNull(Post::find($postId)->published_at);
    }

    public function test_autosave_ignores_slug_and_published_at(): void
    {
        $editor = new FakeActor(1, 'editor');
        $this->actingAs($editor);

        $store = $this->postJson('/editor/posts', $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Autosave Guard'],
        ));
        $store->assertCreated();
        $postId = $store->json('post.id');
        $version = $store->json('post.content_version');
        $originalSlug = $store->json('post.slug');

        $autosave = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            [
                'title_en' => 'Autosave Guard', 'content_version' => $version, 'autosave' => true,
                'slug' => 'sneaky-autosave-slug', 'published_at' => now()->toDateTimeString(),
            ],
        ));

        $autosave->assertOk();
        $fresh = Post::find($postId);
        $this->assertSame($originalSlug, $fresh->slug, 'autosave must never write slug');
        $this->assertNull($fresh->published_at, 'autosave must never write published_at');
    }
}

/**
 * A minimal, real (non-GuestActor) Authenticatable stand-in.
 * ConfigRoleGate::rolesOf() falls back to a plain `role` string property
 * when the user exposes no getRoleNames() (Spatie) method — exactly what
 * this class provides, so tests can exercise real (non-local-dev-bypass)
 * RoleGate tier checks without needing a database-backed user model.
 */
final class FakeActor implements Authenticatable
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

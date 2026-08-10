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

    // ── round trip ───────────────────────────────────────────────────────

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

    // ── 422 on an invalid payload ────────────────────────────────────────

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

    // ── 409 on a stale content_version ──────────────────────────────────

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

    // ── authorization denied for an unauthorized actor ──────────────────

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

    // ── autosave does not trip the lifecycle guard ──────────────────────

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

    /** An edge that isn't in the transitions graph at all (draft -> published) is a 422, independent of tier. */
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
        $version = $store->json('post.content_version');

        $response = $this->putJson("/editor/posts/{$postId}", $this->envelope(
            [$this->block('heisenberg/paragraph', ['content' => 'x'])],
            ['title_en' => 'Skip Ahead', 'content_version' => $version, 'status' => 'published'],
        ));

        $response->assertStatus(422);
        $this->assertSame('draft', Post::find($postId)->status);
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

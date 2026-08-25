<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for PostTrashController (routes/editor.php: DELETE /editor/posts/{post},
 * POST /editor/posts/{post}/restore, GET /editor/posts/trashed) — the HTTP surface behind the
 * editor's "Move to trash" button. Same local-dev authorization bypass pattern (env=local)
 * PostSettingsControllerTest already uses, so the happy-path tests exercise the real
 * PostPolicy::delete()/restore()/viewTrashed() gates through LocalDevRoleGate rather than
 * mocking them away.
 */
class PostTrashControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_trashing_a_post_soft_deletes_it_and_returns_the_deleted_at_timestamp(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->deleteJson("/editor/posts/{$post->id}");

        $response->assertOk();
        $this->assertSame($post->id, $response->json('id'));
        $this->assertTrue($response->json('trashed'));
        $this->assertNotNull($response->json('deleted_at'));
        $this->assertTrue($post->fresh()->trashed());
    }

    public function test_trashing_a_post_cascades_to_its_blocks_and_revisions(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        Revision::snapshotOf($post);

        $this->deleteJson("/editor/posts/{$post->id}")->assertOk();

        $this->assertSame(0, Block::where('post_id', $post->id)->count());
        $this->assertSame(1, Block::withTrashed()->where('post_id', $post->id)->count());
        $this->assertSame(0, Revision::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::withTrashed()->where('post_id', $post->id)->count());
    }

    public function test_trashing_an_unknown_post_is_404(): void
    {
        $this->deleteJson('/editor/posts/999999')->assertNotFound();
    }

    public function test_trashing_an_already_trashed_post_is_404(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->delete();

        // The default (non-trashed) scope can no longer find it — same posture as any other
        // findOrFail() in this codebase.
        $this->deleteJson("/editor/posts/{$post->id}")->assertNotFound();
    }

    public function test_restoring_a_trashed_post_brings_it_and_its_blocks_and_revisions_back(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        Revision::snapshotOf($post);
        $post->delete();

        $response = $this->postJson("/editor/posts/{$post->id}/restore");

        $response->assertOk();
        $this->assertSame($post->id, $response->json('id'));
        $this->assertFalse($response->json('trashed'));
        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(1, Block::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::where('post_id', $post->id)->count());
    }

    public function test_restoring_an_unknown_post_is_404(): void
    {
        $this->postJson('/editor/posts/999999/restore')->assertNotFound();
    }

    public function test_restoring_a_post_that_is_not_trashed_is_422(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->postJson("/editor/posts/{$post->id}/restore");

        $response->assertStatus(422);
        $this->assertFalse($post->fresh()->trashed());
    }

    public function test_trashed_listing_returns_only_trashed_posts_with_the_documented_fields(): void
    {
        $active = Post::create(['title_en' => 'Active', 'status' => 'draft']);
        $trashed = Post::create(['title_en' => 'Trashed', 'status' => 'draft', 'slug' => 'trashed-post', 'author_id' => 7]);
        $trashed->delete();

        $response = $this->getJson('/editor/posts/trashed');

        $response->assertOk();
        $rows = $response->json('posts');
        $this->assertCount(1, $rows);
        $this->assertSame($trashed->id, $rows[0]['id']);
        $this->assertSame('Trashed', $rows[0]['title']);
        $this->assertSame('trashed-post', $rows[0]['slug']);
        $this->assertSame('post', $rows[0]['type']);
        $this->assertSame(7, $rows[0]['author']);
        $this->assertNotNull($rows[0]['deleted_at']);
        $this->assertNotContains($active->id, array_column($rows, 'id'));
    }

    public function test_trashed_listing_is_empty_when_nothing_is_trashed(): void
    {
        Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->getJson('/editor/posts/trashed');

        $response->assertOk();
        $this->assertSame([], $response->json('posts'));
    }

    public function test_trash_restore_and_trashed_listing_are_denied_for_a_non_admin_actor(): void
    {
        $post = Post::create(['title_en' => 'Owned By No One', 'status' => 'draft', 'author_id' => null]);
        $trashedPost = Post::create(['title_en' => 'Y', 'status' => 'draft']);
        $trashedPost->delete();

        $this->app['env'] = 'testing';
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(999, 'author'));

        $this->deleteJson("/editor/posts/{$post->id}")->assertStatus(403);
        $this->postJson("/editor/posts/{$trashedPost->id}/restore")->assertStatus(403);
        $this->getJson('/editor/posts/trashed')->assertStatus(403);

        $this->assertFalse($post->fresh()->trashed());
        $this->assertTrue($trashedPost->fresh()->trashed());
    }

    public function test_trash_and_restore_succeed_for_an_admin_actor(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->app['env'] = 'testing';
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(1, 'admin'));

        $this->deleteJson("/editor/posts/{$post->id}")->assertOk();
        $this->assertTrue($post->fresh()->trashed());

        $this->postJson("/editor/posts/{$post->id}/restore")->assertOk();
        $this->assertFalse($post->fresh()->trashed());
    }
}

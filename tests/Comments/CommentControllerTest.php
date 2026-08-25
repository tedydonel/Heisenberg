<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Comments;

use Heisenberg\Adapters\NullPostCommentProvider;
use Heisenberg\Contracts\PostCommentProvider;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the PUBLIC comments HTTP API (routes/comments.php:
 * `heisenberg.comments.thread`/`.store`, CommentController). Runs against the
 * REAL ConfigRoleGate + PostPolicy — see MediaAuthorizationTest for why that
 * matters more than faking the gate here: this class also pins the
 * PostPolicy::view() "a published post is publicly visible" addition these
 * endpoints depend on.
 */
class CommentControllerTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        // store() is throttled (routes/comments.php, throttle:20,1). Testbench defaults to the
        // `database` cache driver with no `cache` table, which the throttle middleware would hit
        // before the route — same fix AiCompletionEndpointTest applies for its own throttled routes.
        config(['cache.default' => 'array']);
    }

    private function publishedPost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'A published post', 'status' => 'published'], $overrides));
    }

    private function draftPost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'A draft post', 'status' => 'draft'], $overrides));
    }

    /** Bypasses submit()'s validation to set up fixtures directly — status is deliberately not fillable. */
    private function makeComment(Post $post, string $status, ?int $parentId = null, array $overrides = []): Comment
    {
        $comment = new Comment(array_merge([
            'post_id' => $post->getKey(),
            'parent_id' => $parentId,
            'author_name' => 'Fixture Author',
            'body' => 'Fixture body',
        ], $overrides));
        $comment->status = $status;
        $comment->save();

        return $comment;
    }

    public function test_thread_returns_200_with_the_nested_shape_on_a_published_post(): void
    {
        $post = $this->publishedPost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'Top level']);
        $reply = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id, ['body' => 'A reply']);

        $response = $this->getJson("/heisenberg/posts/{$post->id}/comments");

        $response->assertOk();
        $response->assertJson([
            'count' => 2,
            'allow_comments' => true,
            'can_comment' => true,
        ]);
        $this->assertIsInt($response->json('max_depth'));
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($top->id, $items[0]['id']);
        $this->assertSame('Top level', $items[0]['body']);
        $this->assertIsString($items[0]['created_at']);
        $this->assertCount(1, $items[0]['replies']);
        $this->assertSame($reply->id, $items[0]['replies'][0]['id']);
        $this->assertSame([], $items[0]['replies'][0]['replies']);
    }

    public function test_thread_is_forbidden_for_a_guest_on_a_draft(): void
    {
        $post = $this->draftPost();

        $this->getJson("/heisenberg/posts/{$post->id}/comments")->assertStatus(403);
    }

    public function test_thread_sort_oldest_flips_top_level_order(): void
    {
        $post = $this->publishedPost();
        $first = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'first']);
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'second']);

        $newest = $this->getJson("/heisenberg/posts/{$post->id}/comments?sort=newest")->json('items');
        $this->assertSame($second->id, $newest[0]['id']);
        $this->assertSame($first->id, $newest[1]['id']);

        $oldest = $this->getJson("/heisenberg/posts/{$post->id}/comments?sort=oldest")->json('items');
        $this->assertSame($first->id, $oldest[0]['id']);
        $this->assertSame($second->id, $oldest[1]['id']);
    }

    public function test_thread_reflects_allow_comments_false(): void
    {
        $post = $this->publishedPost();
        $post->allow_comments = false;
        $post->save();

        $response = $this->getJson("/heisenberg/posts/{$post->id}/comments");

        $response->assertOk();
        $this->assertFalse($response->json('allow_comments'));
        $this->assertFalse($response->json('can_comment'));
    }

    public function test_guest_can_submit_a_pending_comment(): void
    {
        $post = $this->publishedPost();

        $response = $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'Jane Guest',
            'body' => 'Nice post!',
        ]);

        $response->assertCreated();
        $response->assertJson(['ok' => true, 'status' => 'pending']);
        $this->assertSame('Jane Guest', $response->json('comment.author_name'));

        $row = Comment::find($response->json('comment.id'));
        $this->assertNotNull($row);
        $this->assertNull($row->author_id);
        $this->assertSame(Comment::STATUS_PENDING, $row->status);
    }

    public function test_authenticated_actor_can_submit_a_comment_falling_back_to_a_default_name(): void
    {
        $post = $this->publishedPost();
        $actor = new FakeActor(5, 'author'); // not a moderator; no ->name property (null-safe read)
        $this->actingAs($actor);

        $response = $this->postJson("/heisenberg/posts/{$post->id}/comments", ['body' => 'Hello there']);

        $response->assertCreated();
        $this->assertSame('Member', $response->json('comment.author_name'));
        $row = Comment::find($response->json('comment.id'));
        $this->assertSame(5, $row->author_id);
        $this->assertSame(Comment::STATUS_PENDING, $row->status);
    }

    public function test_guest_store_is_forbidden_when_guests_are_disabled(): void
    {
        config(['heisenberg.comments.allow_guests' => false]);
        $post = $this->publishedPost();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'Jane',
            'body' => 'Nice post!',
        ])->assertStatus(403);

        $this->assertSame(0, Comment::count());
    }

    public function test_store_is_forbidden_when_the_post_disallows_comments(): void
    {
        $post = $this->publishedPost();
        $post->allow_comments = false;
        $post->save();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'Jane',
            'body' => 'Nice post!',
        ])->assertStatus(403);

        $this->assertSame(0, Comment::count());
    }

    public function test_store_missing_body_is_422(): void
    {
        $post = $this->publishedPost();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", ['author_name' => 'Jane'])
            ->assertStatus(422);
    }

    public function test_store_missing_author_name_for_a_guest_is_422(): void
    {
        $post = $this->publishedPost();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", ['body' => 'Nice post!'])
            ->assertStatus(422);
    }

    public function test_store_rejects_a_reply_past_the_configured_max_depth(): void
    {
        config(['heisenberg.comments.max_depth' => 3]);
        $post = $this->publishedPost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED);          // depth 0
        $child = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id); // depth 1
        $grandchild = $this->makeComment($post, Comment::STATUS_APPROVED, $child->id); // depth 2

        $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'X', 'body' => 'too deep', 'parent_id' => $grandchild->id,
        ])->assertStatus(422);
    }

    public function test_store_rejects_an_invalid_parent(): void
    {
        $post = $this->publishedPost();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'X', 'body' => 'ghost parent', 'parent_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_a_moderators_own_comment_is_auto_approved(): void
    {
        $post = $this->publishedPost();
        $this->actingAs(new FakeActor(1, 'editor')); // 'comments.moderate' tier

        $response = $this->postJson("/heisenberg/posts/{$post->id}/comments", ['body' => 'Moderator note']);

        $response->assertCreated();
        $response->assertJson(['status' => 'approved']);
        $this->assertSame(Comment::STATUS_APPROVED, Comment::find($response->json('comment.id'))->status);
    }

    public function test_store_returns_404_when_comments_are_disabled_entirely(): void
    {
        $this->app->instance(PostCommentProvider::class, new NullPostCommentProvider());
        $post = $this->publishedPost();

        $this->postJson("/heisenberg/posts/{$post->id}/comments", [
            'author_name' => 'Jane', 'body' => 'anyone home?',
        ])->assertStatus(404);
    }
}

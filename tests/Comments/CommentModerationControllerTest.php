<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Comments;

use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the comments moderation HTTP API (routes/comments.php:
 * `heisenberg.comments.moderation.*`, CommentModerationController + CommentPolicy),
 * against the REAL ConfigRoleGate + config('heisenberg.roles') `comments.moderate`
 * tier (admin/editor) — same posture as MediaAuthorizationTest / CategoryControllerTest.
 */
class CommentModerationControllerTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge(['title_en' => 'Moderated post', 'status' => 'published'], $overrides));
    }

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

    private function actingAsModerator(string $role = 'editor'): FakeActor
    {
        $actor = new FakeActor(1, $role);
        $this->actingAs($actor);

        return $actor;
    }

    public function test_a_non_moderator_actor_is_denied_every_action(): void
    {
        $post = $this->makePost();
        $comment = $this->makeComment($post, Comment::STATUS_PENDING);
        $this->actingAs(new FakeActor(1, 'viewer'));

        $this->getJson('/editor/comments')->assertStatus(403);
        $this->getJson('/editor/comments/data')->assertStatus(403);
        $this->patchJson("/editor/comments/{$comment->id}", ['status' => 'approved'])->assertStatus(403);
        $this->deleteJson("/editor/comments/{$comment->id}")->assertStatus(403);
        $this->postJson("/editor/comments/{$comment->id}/reply", ['body' => 'nope'])->assertStatus(403);

        $this->assertSame(Comment::STATUS_PENDING, $comment->fresh()->status);
    }

    public function test_index_defaults_to_pending_and_reports_counts(): void
    {
        $post = $this->makePost();
        $pending = $this->makeComment($post, Comment::STATUS_PENDING, null, ['body' => 'pending one']);
        $this->makeComment($post, Comment::STATUS_APPROVED);
        $this->makeComment($post, Comment::STATUS_SPAM);
        $this->makeComment($post, Comment::STATUS_TRASH);
        $this->actingAsModerator();

        $response = $this->getJson('/editor/comments/data');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($pending->id, $data[0]['id']);
        $this->assertSame($post->id, $data[0]['post_id']);
        $this->assertSame('Moderated post', $data[0]['post_title']);
        $this->assertArrayHasKey('reply_count', $data[0]);
        $this->assertSame(['pending' => 1, 'approved' => 1, 'spam' => 1, 'trash' => 1], $response->json('counts'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_index_status_filter(): void
    {
        $post = $this->makePost();
        $approved = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'approved one']);
        $this->makeComment($post, Comment::STATUS_PENDING);
        $this->actingAsModerator();

        $response = $this->getJson('/editor/comments/data?status=approved');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($approved->id, $data[0]['id']);
    }

    public function test_index_q_filter_matches_body_or_author_name(): void
    {
        $post = $this->makePost();
        $match = $this->makeComment($post, Comment::STATUS_PENDING, null, ['body' => 'a UNIQUEWORD in the body']);
        $this->makeComment($post, Comment::STATUS_PENDING, null, ['body' => 'nothing special here']);
        $this->actingAsModerator();

        $response = $this->getJson('/editor/comments/data?q=UNIQUEWORD');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($match->id, $data[0]['id']);
    }

    public function test_update_approves_a_pending_comment(): void
    {
        $post = $this->makePost();
        $comment = $this->makeComment($post, Comment::STATUS_PENDING);
        $this->actingAsModerator();

        $response = $this->patchJson("/editor/comments/{$comment->id}", ['status' => 'approved']);

        $response->assertOk();
        $response->assertJson(['id' => $comment->id, 'status' => 'approved']);
        $this->assertSame(Comment::STATUS_APPROVED, $comment->fresh()->status);
    }

    public function test_update_rejects_an_invalid_status_with_422(): void
    {
        $post = $this->makePost();
        $comment = $this->makeComment($post, Comment::STATUS_PENDING);
        $this->actingAsModerator();

        $this->patchJson("/editor/comments/{$comment->id}", ['status' => 'not-a-real-status'])
            ->assertStatus(422);

        $this->assertSame(Comment::STATUS_PENDING, $comment->fresh()->status);
    }

    public function test_destroy_hard_deletes_and_cascades_replies(): void
    {
        $post = $this->makePost();
        $parent = $this->makeComment($post, Comment::STATUS_APPROVED);
        $reply = $this->makeComment($post, Comment::STATUS_APPROVED, $parent->id);
        $this->actingAsModerator();

        $response = $this->deleteJson("/editor/comments/{$parent->id}");

        $response->assertOk();
        $response->assertJson(['deleted' => true]);
        $this->assertNull(Comment::find($parent->id));
        $this->assertNull(Comment::find($reply->id));
    }

    public function test_reply_approves_a_pending_parent_and_creates_an_approved_reply(): void
    {
        $post = $this->makePost();
        $parent = $this->makeComment($post, Comment::STATUS_PENDING);
        $moderator = $this->actingAsModerator('admin');

        $response = $this->postJson("/editor/comments/{$parent->id}/reply", ['body' => 'Thanks for reading!']);

        $response->assertCreated();
        $this->assertSame(Comment::STATUS_APPROVED, $parent->fresh()->status);
        $this->assertSame($parent->id, $response->json('parent_id'));
        $this->assertSame(Comment::STATUS_APPROVED, Comment::find($response->json('id'))->status);
        $this->assertSame($moderator->id, Comment::find($response->json('id'))->author_id);
    }

    public function test_reply_missing_body_is_422(): void
    {
        $post = $this->makePost();
        $parent = $this->makeComment($post, Comment::STATUS_APPROVED);
        $this->actingAsModerator();

        $this->postJson("/editor/comments/{$parent->id}/reply", [])->assertStatus(422);
    }

    public function test_page_renders_the_moderation_view_for_a_moderator(): void
    {
        $this->actingAsModerator();

        $response = $this->get('/editor/comments');

        $response->assertOk();
        $html = $response->getContent();

        // Status tabs.
        foreach (['pending', 'approved', 'spam', 'trash', 'all'] as $tab) {
            $this->assertStringContainsString('data-hb-tab="' . $tab . '"', $html);
        }
        // The data URL the page's script fetches from.
        $this->assertStringContainsString(route('heisenberg.comments.moderation.index'), $html);
        // A couple of the action affordances the row template wires up.
        $this->assertStringContainsString('data-hb-search', $html);
        $this->assertStringContainsString('data-hb-row-template', $html);
    }
}

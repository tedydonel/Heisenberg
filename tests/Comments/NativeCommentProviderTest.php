<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Comments;

use Heisenberg\Adapters\NativeCommentProvider;
use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Native comments domain coverage: the migration/model/relations, and
 * {@see NativeCommentProvider}'s thread()/count()/submit() behavior against
 * {@see Comment} (config('heisenberg.tables.comments')). Mirrors
 * PostTaxonomyRelationsTest/CategoryModelTest's style for this namespace.
 */
class NativeCommentProviderTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    private function makePost(): Post
    {
        return Post::create(['title_en' => 'A post', 'status' => 'draft']);
    }

    private function provider(): NativeCommentProvider
    {
        return new NativeCommentProvider();
    }

    /** Bypasses submit()'s validation to set up fixtures directly -- status is deliberately not fillable. */
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

    // -- Migration / model / relations --

    public function test_the_migration_creates_the_comments_table_with_the_expected_columns(): void
    {
        $table = config('heisenberg.tables.comments', 'heisenberg_comments');

        $this->assertTrue(Schema::hasTable($table));
        $this->assertTrue(Schema::hasColumns($table, [
            'id', 'post_id', 'parent_id', 'author_id', 'author_name',
            'author_email', 'body', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_comment_model_crud_and_relations_round_trip(): void
    {
        $post = $this->makePost();
        $parent = $this->makeComment($post, Comment::STATUS_APPROVED);
        $reply = $this->makeComment($post, Comment::STATUS_APPROVED, $parent->id, ['body' => 'A reply']);

        $this->assertSame($post->id, $parent->fresh()->post->id);
        $this->assertSame($parent->id, $reply->fresh()->parent->id);
        $this->assertTrue($parent->fresh()->replies->contains('id', $reply->id));
        $this->assertCount(2, $post->fresh()->comments);
    }

    public function test_depth_walks_the_parent_chain(): void
    {
        $post = $this->makePost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED);
        $child = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id);
        $grandchild = $this->makeComment($post, Comment::STATUS_APPROVED, $child->id);

        $this->assertSame(0, $top->depth());
        $this->assertSame(1, $child->depth());
        $this->assertSame(2, $grandchild->depth());
    }

    public function test_deleting_a_comment_cascades_to_its_replies(): void
    {
        $post = $this->makePost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED);
        $reply = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id);

        $top->delete();

        $this->assertNull(Comment::find($reply->id));
    }

    // -- thread() --

    public function test_thread_only_surfaces_approved_top_level_comments(): void
    {
        $post = $this->makePost();
        $approved = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'approved']);
        $this->makeComment($post, Comment::STATUS_PENDING, null, ['body' => 'pending']);
        $this->makeComment($post, Comment::STATUS_SPAM, null, ['body' => 'spam']);
        $this->makeComment($post, Comment::STATUS_TRASH, null, ['body' => 'trash']);

        $thread = $this->provider()->thread($post);

        $this->assertSame(1, $thread['count']);
        $this->assertCount(1, $thread['items']);
        $this->assertSame($approved->id, $thread['items'][0]['id']);
    }

    public function test_thread_nests_approved_replies_under_their_parent(): void
    {
        $post = $this->makePost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'top']);
        $replyA = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id, ['body' => 'reply A']);
        $replyA->forceFill(['created_at' => now()->subMinute()])->save(); // strictly older than replyB, avoids same-second ties
        $replyB = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id, ['body' => 'reply B']);
        $grandchild = $this->makeComment($post, Comment::STATUS_APPROVED, $replyA->id, ['body' => 'grandchild']);

        $thread = $this->provider()->thread($post);

        $this->assertCount(1, $thread['items']);
        $item = $thread['items'][0];
        $this->assertSame($top->id, $item['id']);
        $this->assertCount(2, $item['replies']);
        $this->assertSame($replyA->id, $item['replies'][0]['id']); // oldest first
        $this->assertSame($replyB->id, $item['replies'][1]['id']);
        $this->assertCount(1, $item['replies'][0]['replies']);
        $this->assertSame($grandchild->id, $item['replies'][0]['replies'][0]['id']);
        $this->assertSame(4, $thread['count']); // top + 2 replies + 1 grandchild, all approved
    }

    public function test_thread_does_not_surface_replies_of_an_unapproved_parent(): void
    {
        $post = $this->makePost();
        $pendingParent = $this->makeComment($post, Comment::STATUS_PENDING, null, ['body' => 'pending top']);
        $this->makeComment($post, Comment::STATUS_APPROVED, $pendingParent->id, ['body' => 'orphaned reply']);

        $thread = $this->provider()->thread($post);

        $this->assertSame([], $thread['items']);
        // The reply is approved but never renders because its parent doesn't -- the
        // count still reflects it as an approved row, per the contract's definition.
        $this->assertSame(1, $thread['count']);
    }

    public function test_thread_orders_top_level_by_sort_order_while_replies_stay_oldest_first(): void
    {
        $post = $this->makePost();
        $first = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'first']);
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second = $this->makeComment($post, Comment::STATUS_APPROVED, null, ['body' => 'second']);

        $newest = $this->provider()->thread($post, 'newest');
        $this->assertSame($second->id, $newest['items'][0]['id']);
        $this->assertSame($first->id, $newest['items'][1]['id']);

        $oldest = $this->provider()->thread($post, 'oldest');
        $this->assertSame($first->id, $oldest['items'][0]['id']);
        $this->assertSame($second->id, $oldest['items'][1]['id']);
    }

    public function test_count_reports_zero_for_a_post_with_no_approved_comments(): void
    {
        $post = $this->makePost();
        $this->makeComment($post, Comment::STATUS_PENDING);

        $this->assertSame(0, $this->provider()->count($post));
    }

    // -- submit() --

    public function test_submit_defaults_a_new_top_level_comment_to_pending(): void
    {
        config(['heisenberg.comments.auto_approve' => false]);
        $post = $this->makePost();

        $result = $this->provider()->submit($post, ['author_name' => 'Jane', 'body' => 'Nice post!']);

        $this->assertTrue($result['ok']);
        $this->assertSame('pending', $result['status']);
        $this->assertSame('Jane', $result['comment']['author_name']);
        $this->assertSame([], $result['comment']['replies']);
        $this->assertSame(Comment::STATUS_PENDING, Comment::find($result['comment']['id'])->status);
    }

    public function test_submit_auto_approves_when_the_config_default_is_true(): void
    {
        config(['heisenberg.comments.auto_approve' => true]);
        $post = $this->makePost();

        $result = $this->provider()->submit($post, ['author_name' => 'Jane', 'body' => 'Nice post!']);

        $this->assertSame('approved', $result['status']);
        $this->assertSame(Comment::STATUS_APPROVED, Comment::find($result['comment']['id'])->status);
    }

    public function test_submit_auto_approves_when_the_input_overrides_the_config_default(): void
    {
        config(['heisenberg.comments.auto_approve' => false]);
        $post = $this->makePost();

        $result = $this->provider()->submit($post, [
            'author_name' => 'Moderator', 'body' => 'Approved reply', 'auto_approve' => true,
        ]);

        $this->assertSame('approved', $result['status']);
    }

    public function test_submit_rejects_a_reply_at_or_past_the_configured_max_depth(): void
    {
        config(['heisenberg.comments.max_depth' => 3]);
        $post = $this->makePost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED);          // depth 0
        $child = $this->makeComment($post, Comment::STATUS_APPROVED, $top->id); // depth 1
        $grandchild = $this->makeComment($post, Comment::STATUS_APPROVED, $child->id); // depth 2

        // A reply to the depth-2 grandchild would sit at depth 3 (>= max_depth 3) -- rejected.
        $result = $this->provider()->submit($post, [
            'parent_id' => $grandchild->id, 'author_name' => 'X', 'body' => 'too deep',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('max-depth', $result['error']);

        // A reply to the depth-1 child sits at depth 2 (< 3) -- allowed.
        $allowed = $this->provider()->submit($post, [
            'parent_id' => $child->id, 'author_name' => 'X', 'body' => 'still fine',
        ]);
        $this->assertTrue($allowed['ok']);
    }

    public function test_submit_rejects_a_parent_belonging_to_a_different_post(): void
    {
        $postA = $this->makePost();
        $postB = $this->makePost();
        $parentOnB = $this->makeComment($postB, Comment::STATUS_APPROVED);

        $result = $this->provider()->submit($postA, [
            'parent_id' => $parentOnB->id, 'author_name' => 'X', 'body' => 'cross-post reply',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('invalid-parent', $result['error']);
    }

    public function test_submit_rejects_a_trashed_parent(): void
    {
        $post = $this->makePost();
        $trashedParent = $this->makeComment($post, Comment::STATUS_TRASH);

        $result = $this->provider()->submit($post, [
            'parent_id' => $trashedParent->id, 'author_name' => 'X', 'body' => 'reply to trash',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid-parent', $result['error']);
    }

    public function test_submit_rejects_a_spam_parent(): void
    {
        $post = $this->makePost();
        $spamParent = $this->makeComment($post, Comment::STATUS_SPAM);

        $result = $this->provider()->submit($post, [
            'parent_id' => $spamParent->id, 'author_name' => 'X', 'body' => 'reply to spam',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid-parent', $result['error']);
    }

    public function test_submit_rejects_a_nonexistent_parent(): void
    {
        $post = $this->makePost();

        $result = $this->provider()->submit($post, [
            'parent_id' => 999999, 'author_name' => 'X', 'body' => 'ghost parent',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid-parent', $result['error']);
    }
}

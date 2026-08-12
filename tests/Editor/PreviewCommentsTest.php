<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Comment;
use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Coverage for the preview page's native comments section (PreviewController::showPost(),
 * preview.blade.php's `.hb-preview-comments`). The submit endpoint itself
 * (routes/comments.php `heisenberg.comments.store`, CommentController) is a separate
 * agent's concern and isn't exercised here — this file only pins what showPost() renders
 * server-side from PostCommentProvider::thread().
 */
class PreviewCommentsTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(string $status = 'published', ?bool $allowComments = null): Post
    {
        $post = Post::create([
            'title_en' => 'A Post With Comments',
            'status' => $status,
            'locale' => 'en',
        ]);

        if ($allowComments !== null) {
            // allow_comments is deliberately not fillable -- direct property assignment,
            // same posture as Comment::status (see NativeCommentProviderTest's makeComment()).
            $post->allow_comments = $allowComments;
            $post->save();
        }

        return $post;
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

    public function test_saved_post_preview_renders_the_comments_section_with_a_threaded_reply_and_the_count(): void
    {
        $post = $this->makePost();
        $top = $this->makeComment($post, Comment::STATUS_APPROVED, null, [
            'author_name' => 'Top Author',
            'body' => 'Top level comment',
        ]);
        $this->makeComment($post, Comment::STATUS_APPROVED, $top->id, [
            'author_name' => 'Reply Author',
            'body' => 'A nested reply',
        ]);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        // The section header markup itself -- unlike a bare "hb-preview-comments" class-name
        // substring, this never appears inside the page's <style> block, so it's a reliable
        // "the section actually rendered" marker.
        $this->assertStringContainsString('<h2 class="hb-preview-comments__title">Comments</h2>', $html);
        $this->assertStringContainsString('Top Author', $html);
        $this->assertStringContainsString('Top level comment', $html);
        $this->assertStringContainsString('Reply Author', $html);
        $this->assertStringContainsString('A nested reply', $html);
        // count badge: 2 approved comments (1 top-level + 1 reply)
        $this->assertStringContainsString('data-hb-comments-count>2<', $html);
        // the submit endpoint's real route, not a stub
        $this->assertStringContainsString(route('heisenberg.comments.store', $post->id), $html);
    }

    public function test_saved_post_preview_omits_the_comments_section_when_allow_comments_is_false(): void
    {
        $post = $this->makePost(allowComments: false);
        $this->makeComment($post, Comment::STATUS_APPROVED, null, ['author_name' => 'Should Not Appear']);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        $this->assertStringNotContainsString('<h2 class="hb-preview-comments__title">Comments</h2>', $html);
        $this->assertStringNotContainsString('Should Not Appear', $html);
    }

    public function test_saved_post_preview_never_renders_pending_comments(): void
    {
        $post = $this->makePost();
        $this->makeComment($post, Comment::STATUS_PENDING, null, ['author_name' => 'Pending Author']);

        $html = (string) $this->get("/editor/{$post->id}/preview")->assertOk()->getContent();

        // Section still renders (allow_comments defaults to allowed) but shows the empty state,
        // and the pending author never reaches the page.
        $this->assertStringContainsString('<h2 class="hb-preview-comments__title">Comments</h2>', $html);
        $this->assertStringContainsString('No comments yet', $html);
        $this->assertStringNotContainsString('Pending Author', $html);
        $this->assertStringContainsString('data-hb-comments-count>0<', $html);
    }

    public function test_session_flow_preview_never_ships_a_comments_section(): void
    {
        // show() has no post to attach comments to -- PreviewController leaves $comments null
        // for this flow entirely (see its own docblock). Seeded the way every other session-flow
        // test does (POST the doc, then GET) -- withSession() can't drive the cookie session
        // driver this environment uses.
        $this->postJson('/editor/preview', ['title' => 'A Draft', 'blocks' => []])
            ->assertOk()
            ->assertJson(['stored' => true]);

        $html = (string) $this->get(route('heisenberg.editor.preview'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<h2 class="hb-preview-comments__title">Comments</h2>', $html);
    }
}

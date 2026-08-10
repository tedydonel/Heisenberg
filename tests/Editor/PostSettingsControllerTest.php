<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for PostSettingsController (routes/editor.php: PUT /editor/posts/{post}/layout
 * and /discussion) — the Page layout (page_padding_x/page_padding_y) and Discussion
 * (allow_comments) inspector sections built 2026-08-03. Same local-dev authorization bypass
 * pattern as PostPersistenceTest; reuses that file's own FakeActor (same namespace).
 *
 * The critical thing under test isn't just "the value round-trips" — it's that hitting these
 * endpoints does NOT go anywhere near PostController::update()'s block-tree replacement (see
 * PostSettingsController's own docblock on why a shared endpoint would be unsafe here).
 */
class PostSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── layout ────────────────────────────────────────────────────────────

    public function test_page_padding_can_be_saved_and_read_back(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->putJson("/editor/posts/{$post->id}/layout", [
            'page_padding_x' => 80,
            'page_padding_y' => 120,
        ]);

        $response->assertOk();
        $this->assertSame(80, $response->json('page_padding_x'));
        $this->assertSame(120, $response->json('page_padding_y'));
        $this->assertSame(80, $post->fresh()->page_padding_x);
        $this->assertSame(120, $post->fresh()->page_padding_y);
    }

    public function test_saving_layout_does_not_touch_the_posts_block_tree(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph'], 'order' => 0]);

        $this->putJson("/editor/posts/{$post->id}/layout", ['page_padding_x' => 40, 'page_padding_y' => 40])->assertOk();

        $this->assertCount(1, $post->fresh()->blocks);
    }

    public function test_layout_padding_out_of_range_is_rejected_with_422(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->putJson("/editor/posts/{$post->id}/layout", ['page_padding_x' => -5, 'page_padding_y' => 40]);

        $response->assertStatus(422);
        $this->assertNull($post->fresh()->page_padding_x);
    }

    // ── discussion ────────────────────────────────────────────────────────

    public function test_allow_comments_can_be_toggled_off_then_back_on(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $off = $this->putJson("/editor/posts/{$post->id}/discussion", ['allow_comments' => false]);
        $off->assertOk();
        $this->assertFalse($off->json('allow_comments'));
        $this->assertFalse((bool) $post->fresh()->allow_comments);

        $on = $this->putJson("/editor/posts/{$post->id}/discussion", ['allow_comments' => true]);
        $on->assertOk();
        $this->assertTrue($on->json('allow_comments'));
        $this->assertTrue((bool) $post->fresh()->allow_comments);
    }

    // ── authorization denied for an unauthorized actor ──────────────────

    public function test_layout_and_discussion_are_denied_for_an_actor_who_cannot_update_the_post(): void
    {
        $post = Post::create(['title_en' => 'Owned By No One', 'status' => 'draft', 'author_id' => null]);

        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(999, 'author'));

        $this->putJson("/editor/posts/{$post->id}/layout", ['page_padding_x' => 40, 'page_padding_y' => 40])->assertStatus(403);
        $this->putJson("/editor/posts/{$post->id}/discussion", ['allow_comments' => false])->assertStatus(403);

        $this->assertNull($post->fresh()->page_padding_x);
        $this->assertNull($post->fresh()->allow_comments);
    }
}

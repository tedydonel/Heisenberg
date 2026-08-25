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

    /** @return \Heisenberg\Models\PublicFile */
    private function imageRow()
    {
        return \Heisenberg\Models\PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/featured-' . uniqid('', true) . '.jpg',
            'original_name' => 'featured.jpg',
            'stored_name' => 'featured.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    public function test_featured_image_sets_and_reads_back(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $file = $this->imageRow();

        $response = $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => $file->id]);

        $response->assertOk();
        $this->assertSame($file->id, $response->json('featured_image_id'));
        $this->assertSame($file->id, $post->fresh()->featured_image_id);
        $this->assertSame($file->id, $post->fresh()->featuredImage?->id);
    }

    public function test_featured_image_null_clears(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $file = $this->imageRow();
        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => $file->id])->assertOk();

        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => null])->assertOk();

        $this->assertNull($post->fresh()->featured_image_id);
    }

    public function test_featured_image_unknown_id_is_422(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => 987654])->assertStatus(422);

        $this->assertNull($post->fresh()->featured_image_id);
    }

    /** The same safety claim the layout test makes: one scalar FK, zero block-tree contact. */
    public function test_featured_image_does_not_touch_the_posts_block_tree(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph'], 'order' => 0]);
        $file = $this->imageRow();

        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => $file->id])->assertOk();

        $this->assertCount(1, $post->fresh()->blocks);
    }

    /** Deleting the media file must clear the reference (nullOnDelete), never break the post. */
    public function test_deleting_the_media_file_nulls_the_reference(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $file = $this->imageRow();
        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => $file->id])->assertOk();

        // Hard DB delete — exercises the FK cascade itself, not the soft-delete flow.
        $file->forceDelete();

        $this->assertNull($post->fresh()->featured_image_id);
        $this->assertNotNull(Post::find($post->id), 'the post itself must survive');
    }

    public function test_toc_entries_can_be_set_and_read_back_in_order(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [
                ['label' => 'Introduction', 'anchor' => 'introduction'],
                ['label' => 'Deep Dive', 'anchor' => 'deep-dive'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame([
            ['label' => 'Introduction', 'anchor' => 'introduction'],
            ['label' => 'Deep Dive', 'anchor' => 'deep-dive'],
        ], $response->json('entries'));

        $stored = $post->fresh()->tocEntries()->orderBy('order')->get();
        $this->assertCount(2, $stored);
        $this->assertSame('Introduction', $stored[0]->label);
        $this->assertSame(0, $stored[0]->order);
        $this->assertSame('Deep Dive', $stored[1]->label);
        $this->assertSame(1, $stored[1]->order);
    }

    /** Replace-all semantics: a second save fully replaces the first, never appends. */
    public function test_toc_entries_are_replaced_wholesale_on_a_second_save(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => 'First', 'anchor' => 'first']],
        ])->assertOk();

        $response = $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [
                ['label' => 'Second', 'anchor' => 'second'],
                ['label' => 'Third', 'anchor' => 'third'],
            ],
        ]);

        $response->assertOk();
        $this->assertCount(2, $post->fresh()->tocEntries);
        $this->assertSame(['second', 'third'], $post->fresh()->tocEntries()->orderBy('order')->pluck('anchor')->all());
    }

    /** An empty entries array is the documented way to remove a post's TOC. */
    public function test_toc_entries_can_be_cleared_with_an_empty_array(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => 'First', 'anchor' => 'first']],
        ])->assertOk();

        $response = $this->putJson("/editor/posts/{$post->id}/toc", ['entries' => []]);

        $response->assertOk();
        $this->assertSame([], $response->json('entries'));
        $this->assertCount(0, $post->fresh()->tocEntries);
    }

    public function test_toc_entry_missing_label_is_rejected_with_422(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => '', 'anchor' => 'ok']],
        ]);

        $response->assertStatus(422);
        $this->assertCount(0, $post->fresh()->tocEntries);
    }

    public function test_toc_entry_anchor_must_be_a_legal_id_shape(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $response = $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => 'Bad Anchor', 'anchor' => '1-starts-with-digit']],
        ]);

        $response->assertStatus(422);
        $this->assertCount(0, $post->fresh()->tocEntries);
    }

    public function test_toc_entries_missing_key_is_rejected_with_422(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->putJson("/editor/posts/{$post->id}/toc", [])->assertStatus(422);
    }

    /** Same safety claim the layout/featured-image tests make: zero block-tree contact. */
    public function test_toc_save_does_not_touch_the_posts_block_tree(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph'], 'order' => 0]);

        $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => 'Section', 'anchor' => 'section']],
        ])->assertOk();

        $this->assertCount(1, $post->fresh()->blocks);
    }

    /** Deleting the post must take its TOC entries with it (cascadeOnDelete). */
    public function test_deleting_the_post_hard_removes_its_toc_entries(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $this->putJson("/editor/posts/{$post->id}/toc", [
            'entries' => [['label' => 'Section', 'anchor' => 'section']],
        ])->assertOk();
        $tocTable = config('heisenberg.tables.toc_entries', 'heisenberg_post_toc_entries');

        $post->forceDelete();

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table($tocTable)->where('post_id', $post->id)->count());
    }

    public function test_layout_and_discussion_are_denied_for_an_actor_who_cannot_update_the_post(): void
    {
        $post = Post::create(['title_en' => 'Owned By No One', 'status' => 'draft', 'author_id' => null]);

        $this->app['env'] = 'testing';
        // The Taxonomy FakeActor, not the same-namespace one PostPersistenceTest
        // declares inline — depending on that one couples this file to test
        // load order (it only exists once PostPersistenceTest has been loaded).
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(999, 'author'));

        $this->putJson("/editor/posts/{$post->id}/layout", ['page_padding_x' => 40, 'page_padding_y' => 40])->assertStatus(403);
        $this->putJson("/editor/posts/{$post->id}/discussion", ['allow_comments' => false])->assertStatus(403);
        $this->putJson("/editor/posts/{$post->id}/featured-image", ['featured_image_id' => null])->assertStatus(403);
        $this->putJson("/editor/posts/{$post->id}/toc", ['entries' => [['label' => 'X', 'anchor' => 'x']]])->assertStatus(403);

        $this->assertNull($post->fresh()->page_padding_x);
        $this->assertNull($post->fresh()->allow_comments);
        $this->assertCount(0, $post->fresh()->tocEntries);
    }
}

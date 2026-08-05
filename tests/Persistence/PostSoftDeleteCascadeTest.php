<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The soft-delete/restore cascade mismatch (Post::delete()/restore()): the
 * blocks/revisions FK cascades at the DB level, but that only fires on a real
 * SQL DELETE. A SoftDeletes `delete()` is an UPDATE, so without the override in
 * Post, a trashed post's blocks stayed live forever — only forceDelete() (already
 * covered by BlockPersistenceTest) actually cascaded. This exercises the OTHER
 * path: a plain soft delete()/restore().
 */
class PostSoftDeleteCascadeTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_soft_deleting_a_post_trashes_its_blocks(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        $post->blocks()->create(['type' => 'heading', 'content' => [], 'order' => 1]);

        $post->delete();

        $this->assertTrue($post->fresh()->trashed());
        $this->assertSame(0, Block::where('post_id', $post->id)->count(), 'blocks must no longer be visible to the default (non-trashed) scope');
        $this->assertSame(2, Block::withTrashed()->where('post_id', $post->id)->count(), 'blocks must still physically exist (soft-deleted, not gone)');
        $this->assertSame(
            2,
            Block::onlyTrashed()->where('post_id', $post->id)->where('deleted_batch_id', $post->fresh()->deleted_batch_id)->count()
        );
    }

    public function test_soft_deleting_a_post_trashes_its_revisions(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        Revision::snapshotOf($post);

        $post->delete();

        $this->assertSame(0, Revision::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::withTrashed()->where('post_id', $post->id)->count());
    }

    public function test_restoring_a_post_restores_its_blocks_and_revisions(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        Revision::snapshotOf($post);

        $post->delete();
        $post->fresh()->restore();

        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(1, Block::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::where('post_id', $post->id)->count());
    }

    public function test_restoring_a_post_does_not_resurrect_a_block_trashed_independently(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $independentlyTrashed = $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        $independentlyTrashed->delete(); // trashed on its own, no deleted_batch_id from a post-level cascade

        $post->delete();
        $post->fresh()->restore();

        $this->assertTrue($independentlyTrashed->fresh()->trashed(), 'a block trashed before the post cascade must stay trashed after restore');
    }

    public function test_force_deleting_an_already_soft_deleted_post_still_cascades(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);

        $post->delete();
        $post->fresh()->forceDelete();

        $this->assertSame(0, Block::withTrashed()->where('post_id', $post->id)->count(), 'a genuine force-delete must still remove children at the DB level');
    }
}

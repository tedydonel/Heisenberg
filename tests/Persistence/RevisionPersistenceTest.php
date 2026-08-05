<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Revision snapshots (blueprint §2.3.5): a point-in-time copy of a post's block
 * tree + rendered HTML/title/excerpt, independent of the live `blocks` rows.
 * NOT wired into any controller/save path — {@see Revision::snapshotOf()} is the
 * model-level primitive a future save/autosave/publish flow will call.
 */
class RevisionPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_a_revision_snapshot_round_trips_the_block_tree(): void
    {
        $post = Post::create([
            'title_en' => 'The Quiet Craft',
            'excerpt_en' => 'An excerpt',
            'rendered_html_en' => '<p>Hi</p>',
            'status' => 'draft',
        ]);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Hi']], 'order' => 0]);
        $post->blocks()->create(['type' => 'heading', 'content' => ['name' => 'heisenberg/heading'], 'order' => 1]);

        $revision = Revision::snapshotOf($post, 'manual', 42);

        $this->assertSame($post->id, $revision->post_id);
        $this->assertSame(42, $revision->author_id);
        $this->assertSame('manual', $revision->revision_type);
        $this->assertSame('<p>Hi</p>', $revision->rendered_html_en);
        $this->assertSame('The Quiet Craft', $revision->title_en);
        $this->assertSame('An excerpt', $revision->excerpt_en);
        $this->assertCount(2, $revision->content_blocks);
        $this->assertSame('paragraph', $revision->content_blocks[0]['type']);
        $this->assertSame(['name' => 'heisenberg/paragraph', 'attributes' => ['content' => 'Hi']], $revision->content_blocks[0]['content']);

        // Round-trips through the database (json array cast), not just in memory.
        $fromDb = Revision::query()->findOrFail($revision->id);
        $this->assertSame('paragraph', $fromDb->content_blocks[0]['type']);
        $this->assertSame('heading', $fromDb->content_blocks[1]['type']);
    }

    public function test_a_revision_survives_independently_of_the_live_blocks_it_was_snapshotted_from(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $block = $post->blocks()->create(['type' => 'paragraph', 'content' => ['x' => 1], 'order' => 0]);

        $revision = Revision::snapshotOf($post);
        $block->forceDelete();

        $revision->refresh();
        $this->assertCount(1, $revision->content_blocks);
        $this->assertSame(['x' => 1], $revision->content_blocks[0]['content']);
    }

    public function test_an_unrecognized_revision_type_falls_back_to_manual(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $revision = Revision::snapshotOf($post, 'not-a-real-type');

        $this->assertSame('manual', $revision->revision_type);
    }

    public function test_post_revisions_relation_orders_newest_first(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $older = Revision::snapshotOf($post);
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $newer = Revision::snapshotOf($post);

        $ids = $post->revisions()->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_revisions_table_name_comes_from_config(): void
    {
        $this->assertSame(config('heisenberg.tables.revisions'), (new Revision())->getTable());
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Post;
use Heisenberg\Models\TocEntry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * {@see TocEntry}: the post's AUTHORED table of contents.
 *
 * Cascade posture per Post's own docblock (tocEntries()): unlike blocks/revisions,
 * toc_entries is deliberately NOT part of the deleted_batch_id soft-delete cascade
 * — the migration gives it no `deleted_at` column at all, only a DB-level
 * `cascadeOnDelete()` FK (migration 2026_08_10_000003). So:
 *  - a plain (soft) Post::delete() must leave toc entries completely untouched
 *    (they have nothing to soft-delete into — the post row itself just gets
 *    `deleted_at` set, its toc rows are unaffected and stay queryable);
 *  - Post::restore() has nothing to do for toc entries, for the same reason;
 *  - only a genuine SQL DELETE (Post::forceDelete()) cascades and removes them,
 *    at the DB level, regardless of whether the post was soft-deleted first.
 */
class TocEntryTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    private function makePost(): Post
    {
        return Post::create(['title_en' => 'TOC Host', 'status' => 'draft']);
    }

    public function test_fillable_fields(): void
    {
        $post = $this->makePost();

        $entry = TocEntry::create([
            'post_id' => $post->id,
            'label' => 'Introduction',
            'anchor' => 'introduction',
            'order' => 0,
        ]);

        $this->assertSame($post->id, $entry->post_id);
        $this->assertSame('Introduction', $entry->label);
        $this->assertSame('introduction', $entry->anchor);
        $this->assertSame(0, $entry->order);
    }

    public function test_order_is_cast_to_an_integer(): void
    {
        $post = $this->makePost();

        $entry = TocEntry::create([
            'post_id' => $post->id,
            'label' => 'A',
            'anchor' => 'a',
            'order' => '3',
        ]);

        $this->assertSame(3, $entry->fresh()->order);
        $this->assertIsInt($entry->fresh()->order);
    }

    public function test_post_relation_resolves_the_owning_post(): void
    {
        $post = $this->makePost();
        $entry = TocEntry::create(['post_id' => $post->id, 'label' => 'A', 'anchor' => 'a', 'order' => 0]);

        $this->assertTrue($entry->post->is($post));
    }

    public function test_a_posts_toc_entries_are_ordered_by_the_order_column(): void
    {
        $post = $this->makePost();
        TocEntry::create(['post_id' => $post->id, 'label' => 'Third', 'anchor' => 'third', 'order' => 2]);
        TocEntry::create(['post_id' => $post->id, 'label' => 'First', 'anchor' => 'first', 'order' => 0]);
        TocEntry::create(['post_id' => $post->id, 'label' => 'Second', 'anchor' => 'second', 'order' => 1]);

        $labels = $post->tocEntries()->get()->pluck('label')->all();

        $this->assertSame(['First', 'Second', 'Third'], $labels);
    }

    public function test_table_name_defaults_to_heisenberg_post_toc_entries(): void
    {
        $this->assertSame('heisenberg_post_toc_entries', (new TocEntry())->getTable());
    }

    public function test_table_name_honours_a_config_override(): void
    {
        config(['heisenberg.tables.toc_entries' => 'custom_toc_table']);

        $this->assertSame('custom_toc_table', (new TocEntry())->getTable());
    }

    // ------------------------------------------------------------------
    // Cascade behaviour on the owning post's soft-delete / restore / force-delete
    // ------------------------------------------------------------------

    public function test_soft_deleting_the_post_leaves_toc_entries_completely_untouched(): void
    {
        $post = $this->makePost();
        TocEntry::create(['post_id' => $post->id, 'label' => 'A', 'anchor' => 'a', 'order' => 0]);
        TocEntry::create(['post_id' => $post->id, 'label' => 'B', 'anchor' => 'b', 'order' => 1]);

        $post->delete();

        $this->assertTrue($post->fresh()->trashed());
        $this->assertSame(2, TocEntry::where('post_id', $post->id)->count(), 'toc entries have no deleted_at column and no batch cascade — they must remain fully visible');
    }

    public function test_restoring_the_post_has_nothing_to_do_and_toc_entries_stay_intact(): void
    {
        $post = $this->makePost();
        TocEntry::create(['post_id' => $post->id, 'label' => 'A', 'anchor' => 'a', 'order' => 0]);

        $post->delete();
        $post->fresh()->restore();

        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(1, TocEntry::where('post_id', $post->id)->count());
    }

    public function test_force_deleting_the_post_cascades_and_removes_toc_entries_at_the_db_level(): void
    {
        $post = $this->makePost();
        TocEntry::create(['post_id' => $post->id, 'label' => 'A', 'anchor' => 'a', 'order' => 0]);
        TocEntry::create(['post_id' => $post->id, 'label' => 'B', 'anchor' => 'b', 'order' => 1]);

        $post->forceDelete();

        $this->assertSame(0, TocEntry::where('post_id', $post->id)->count());
    }

    public function test_force_deleting_an_already_soft_deleted_post_still_cascades_toc_entries(): void
    {
        $post = $this->makePost();
        TocEntry::create(['post_id' => $post->id, 'label' => 'A', 'anchor' => 'a', 'order' => 0]);

        $post->delete();
        $post->fresh()->forceDelete();

        $this->assertSame(0, TocEntry::where('post_id', $post->id)->count());
    }
}

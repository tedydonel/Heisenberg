<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Model-level coverage for Tag (migration 2026_07_28_000004): auto-slug on
 * create, numeric-suffix collision handling, and the posts() BelongsToMany
 * relation via the `heisenberg_post_tag` pivot (migration 2026_07_28_000005).
 */
class TagModelTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_a_tag_auto_assigns_a_slug_from_its_name(): void
    {
        $tag = Tag::create(['name_en' => 'Process Notes']);

        $this->assertSame('process-notes', $tag->slug);
    }

    public function test_a_colliding_slug_gets_a_numeric_suffix(): void
    {
        $first = Tag::create(['name_en' => 'Craft']);
        $second = Tag::create(['name_en' => 'Craft']);

        $this->assertSame('craft', $first->slug);
        $this->assertSame('craft-2', $second->slug);
    }

    public function test_a_slug_held_by_a_trashed_tag_is_still_reserved(): void
    {
        $original = Tag::create(['name_en' => 'Archived']);
        $original->delete();
        $this->assertTrue($original->fresh()->trashed());

        $newcomer = Tag::create(['name_en' => 'Archived']);

        $this->assertSame('archived-2', $newcomer->slug);
    }

    public function test_the_database_level_unique_index_rejects_a_raw_duplicate_insert(): void
    {
        $table = config('heisenberg.tables.tags');

        DB::table($table)->insert([
            'name_en' => 'Dup', 'slug' => 'dup-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table($table)->insert([
            'name_en' => 'Dup Again', 'slug' => 'dup-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_posts_relation_round_trips_through_the_pivot(): void
    {
        $post = Post::create(['title_en' => 'Tagged Post', 'status' => 'draft']);
        $tag = Tag::create(['name_en' => 'Featured']);

        $tag->posts()->attach($post->id);

        $this->assertTrue($tag->fresh()->posts->contains('id', $post->id));
        $this->assertTrue($post->fresh()->tags->contains('id', $tag->id));
    }

    public function test_deleting_a_tag_detaches_it_from_every_post_first(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $tag = Tag::create(['name_en' => 'Removable']);
        $tag->posts()->attach($post->id);

        // Mirrors blueprint TaxonomyService::deleteTag: detach before delete.
        $tag->posts()->detach();
        $tag->delete();

        $this->assertSame(0, DB::table(config('heisenberg.tables.post_tag'))->where('tag_id', $tag->id)->count());
    }
}

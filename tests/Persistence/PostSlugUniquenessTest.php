<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Persistence;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Slug-collision handling (migration 2026_07_28_000001 + Post::booted()).
 * `slug` used to be a plain non-unique index with zero collision detection —
 * two posts could silently share a slug.
 */
class PostSlugUniquenessTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_a_colliding_slug_gets_a_numeric_suffix(): void
    {
        $first = Post::create(['title_en' => 'The Quiet Craft', 'status' => 'draft']);
        $second = Post::create(['title_en' => 'The Quiet Craft', 'status' => 'draft']);
        $third = Post::create(['title_en' => 'The Quiet Craft', 'status' => 'draft']);

        $this->assertSame('the-quiet-craft', $first->slug);
        $this->assertSame('the-quiet-craft-2', $second->slug);
        $this->assertSame('the-quiet-craft-3', $third->slug);
    }

    public function test_an_explicitly_provided_slug_is_also_deduplicated(): void
    {
        Post::create(['title_en' => 'Alpha', 'slug' => 'shared', 'status' => 'draft']);
        $second = Post::create(['title_en' => 'Beta', 'slug' => 'shared', 'status' => 'draft']);

        $this->assertSame('shared-2', $second->slug);
    }

    public function test_the_same_slug_is_allowed_across_different_locales(): void
    {
        $en = Post::create(['title_en' => 'Bonjour', 'locale' => 'en', 'status' => 'draft']);
        $fr = Post::create(['title_en' => 'Bonjour', 'locale' => 'fr', 'status' => 'draft']);

        // Both keep the un-suffixed slug — locale is part of the uniqueness scope.
        $this->assertSame('bonjour', $en->slug);
        $this->assertSame('bonjour', $fr->slug);
    }

    public function test_a_slug_held_by_a_trashed_post_is_still_reserved(): void
    {
        $original = Post::create(['title_en' => 'Archived Piece', 'status' => 'draft']);
        $original->delete();
        $this->assertTrue($original->fresh()->trashed());

        $newcomer = Post::create(['title_en' => 'Archived Piece', 'status' => 'draft']);

        $this->assertSame('archived-piece-2', $newcomer->slug);
    }

    public function test_the_database_level_unique_index_rejects_a_raw_duplicate_insert(): void
    {
        // Bypass Post::booted()'s collision handling entirely (a raw insert) to
        // prove the DB constraint itself — not just the app-level logic — holds.
        $table = config('heisenberg.tables.posts');

        DB::table($table)->insert([
            'locale' => 'en', 'title_en' => 'Dup', 'slug' => 'dup-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table($table)->insert([
            'locale' => 'en', 'title_en' => 'Dup Again', 'slug' => 'dup-slug',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

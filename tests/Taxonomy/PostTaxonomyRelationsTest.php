<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Post::categories()/tags() relation coverage — and, specifically, that NEITHER participates in
 * Post's deleted_batch_id cascade soft-delete/restore mechanism (Post::delete()/restore()),
 * unlike blocks()/revisions(). The blueprint calls categories/tags "independent taxonomies" a
 * post merely references, not owned content — so a trashed (or restored) post's category/tag
 * associations must be completely unaffected.
 *
 * Categories became BelongsToMany (2026-08-03, `heisenberg_category_post` pivot) — see
 * Post::categories()'s own docblock and docs/BLUEPRINT.md's `[TARGET]` deviation note for §2.3.3.
 * This class mirrors that shape's tests against tags() throughout, since both relations are now
 * structurally identical.
 */
class PostTaxonomyRelationsTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_a_post_has_many_categories_via_the_pivot(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $categoryA = Category::create(['name_en' => 'Essays']);
        $categoryB = Category::create(['name_en' => 'Field Notes']);

        $post->categories()->attach([$categoryA->id, $categoryB->id]);

        $this->assertCount(2, $post->fresh()->categories);
        $this->assertTrue($post->categories->pluck('id')->contains($categoryA->id));
        $this->assertTrue($categoryA->posts->contains('id', $post->id));
    }

    public function test_a_post_has_many_tags_via_the_pivot(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $tagA = Tag::create(['name_en' => 'Alpha']);
        $tagB = Tag::create(['name_en' => 'Beta']);

        $post->tags()->attach([$tagA->id, $tagB->id]);

        $this->assertCount(2, $post->fresh()->tags);
        $this->assertTrue($post->tags->pluck('id')->contains($tagA->id));
    }

    public function test_soft_deleting_a_post_leaves_its_categories_and_tags_untouched(): void
    {
        $category = Category::create(['name_en' => 'Essays']);
        $tag = Tag::create(['name_en' => 'Featured']);
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->categories()->attach($category->id);
        $post->tags()->attach($tag->id);

        $post->delete();

        $fresh = $post->fresh();
        $this->assertTrue($fresh->trashed());
        $this->assertSame(1, DB::table(config('heisenberg.tables.category_post'))->where('post_id', $post->id)->count(), 'the category pivot row must survive a soft delete untouched');
        $this->assertSame(1, DB::table(config('heisenberg.tables.post_tag'))->where('post_id', $post->id)->count(), 'the tag pivot row must survive a soft delete untouched');
    }

    public function test_restoring_a_post_still_has_its_original_categories_and_tags(): void
    {
        $category = Category::create(['name_en' => 'Essays']);
        $tag = Tag::create(['name_en' => 'Featured']);
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->categories()->attach($category->id);
        $post->tags()->attach($tag->id);

        $post->delete();
        $post->fresh()->restore();

        $restored = $post->fresh();
        $this->assertFalse($restored->trashed());
        $this->assertTrue($restored->categories->contains('id', $category->id));
        $this->assertTrue($restored->tags->contains('id', $tag->id));
    }

    public function test_force_deleting_a_post_removes_its_category_pivot_rows_via_the_fk_cascade(): void
    {
        $category = Category::create(['name_en' => 'Essays']);
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->categories()->attach($category->id);
        $postId = $post->id;

        $post->forceDelete();

        $this->assertSame(0, DB::table(config('heisenberg.tables.category_post'))->where('post_id', $postId)->count());
        // The category itself is untouched — only the pivot row is gone.
        $this->assertNotNull($category->fresh());
    }

    public function test_force_deleting_a_post_removes_its_tag_pivot_rows_via_the_fk_cascade(): void
    {
        $tag = Tag::create(['name_en' => 'Featured']);
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->tags()->attach($tag->id);
        $postId = $post->id;

        $post->forceDelete();

        $this->assertSame(0, DB::table(config('heisenberg.tables.post_tag'))->where('post_id', $postId)->count());
        // The tag itself is untouched — only the pivot row is gone.
        $this->assertNotNull($tag->fresh());
    }

    public function test_force_deleting_a_category_removes_its_pivot_rows_via_the_fk_cascade_leaving_the_post_intact(): void
    {
        $category = Category::create(['name_en' => 'Essays']);
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->categories()->attach($category->id);

        $category->forceDelete();

        $this->assertSame(0, DB::table(config('heisenberg.tables.category_post'))->where('category_id', $category->id)->count());
        $this->assertCount(0, $post->fresh()->categories);
        $this->assertNotNull($post->fresh());
    }
}

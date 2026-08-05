<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Category;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Model-level coverage for Category (migration 2026_07_28_000003):
 * auto-slug on create, numeric-suffix collision handling (mirroring
 * PostSlugUniquenessTest's coverage of Post::uniqueSlug()), the
 * self-parenting tree, and the SoftDeletes deviation from
 * docs/BLUEPRINT.md §2.4's "(no SD)" annotation for `blog_categories`.
 */
class CategoryModelTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable;

    public function test_a_category_auto_assigns_a_slug_from_its_name(): void
    {
        $category = Category::create(['name_en' => 'Field Notes']);

        $this->assertSame('field-notes', $category->slug);
    }

    public function test_a_colliding_slug_gets_a_numeric_suffix(): void
    {
        $first = Category::create(['name_en' => 'Design']);
        $second = Category::create(['name_en' => 'Design']);
        $third = Category::create(['name_en' => 'Design']);

        $this->assertSame('design', $first->slug);
        $this->assertSame('design-2', $second->slug);
        $this->assertSame('design-3', $third->slug);
    }

    public function test_an_explicitly_provided_slug_is_also_deduplicated(): void
    {
        Category::create(['name_en' => 'Alpha', 'slug' => 'shared']);
        $second = Category::create(['name_en' => 'Beta', 'slug' => 'shared']);

        $this->assertSame('shared-2', $second->slug);
    }

    public function test_a_slug_held_by_a_trashed_category_is_still_reserved(): void
    {
        $original = Category::create(['name_en' => 'Archived Topic']);
        $original->delete();
        $this->assertTrue($original->fresh()->trashed());

        $newcomer = Category::create(['name_en' => 'Archived Topic']);

        $this->assertSame('archived-topic-2', $newcomer->slug);
    }

    public function test_an_explicitly_changed_slug_on_update_is_deduplicated(): void
    {
        Category::create(['name_en' => 'Existing', 'slug' => 'taken']);
        $other = Category::create(['name_en' => 'Other', 'slug' => 'other']);

        $other->slug = 'taken';
        $other->save();

        $this->assertSame('taken-2', $other->fresh()->slug);
    }

    public function test_the_database_level_unique_index_rejects_a_raw_duplicate_insert(): void
    {
        $table = config('heisenberg.tables.categories');

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

    public function test_the_self_parenting_tree_relations_round_trip(): void
    {
        $root = Category::create(['name_en' => 'Root']);
        $child = Category::create(['name_en' => 'Child', 'parent_id' => $root->id]);
        $grandchild = Category::create(['name_en' => 'Grandchild', 'parent_id' => $child->id]);

        $this->assertSame($root->id, $child->parent->id);
        $this->assertTrue($root->children->contains('id', $child->id));
        $this->assertSame([$child->id, $root->id], $grandchild->getAncestorIds());
        $this->assertSame([$child->id, $grandchild->id], $root->getDescendantIds());
    }

    public function test_deleting_a_parent_category_nulls_its_children_via_the_db_fk(): void
    {
        $root = Category::create(['name_en' => 'Root']);
        $child = Category::create(['name_en' => 'Child', 'parent_id' => $root->id]);

        $root->forceDelete();

        $this->assertNull($child->fresh()->parent_id);
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Category;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the category REST API (routes/editor.php:
 * /editor/categories[/{category}]) — CategoryController + Store/
 * UpdateCategoryRequest + CategoryPolicy. Same local-dev authorization bypass
 * pattern as PostPersistenceTest: force APP_ENV to 'local' so an
 * unauthenticated request exercises the SAME bypass (src/Adapters/
 * LocalDevRoleGate.php) that makes a fresh `testbench serve` session usable
 * out of the box, and disable CSRF for the same reason that test class does.
 */
class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    // A plain `use SkipsWhenMysqlUnreachable;` would silently be shadowed by
    // this class's own setUp() override below (a class method always wins
    // over a same-named trait method, and `parent::` inside THIS class's
    // setUp() would then skip the trait's mysql-reachability check entirely,
    // going straight to TestCase::setUp()). Alias the trait's setUp() under a
    // different name instead, and call it explicitly.
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable(); // checks reachability, then calls parent::setUp() itself

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── CRUD round trip ──────────────────────────────────────────────────

    public function test_store_then_index_then_update_then_destroy_round_trips(): void
    {
        $store = $this->postJson('/editor/categories', ['name_en' => 'Essays']);
        $store->assertCreated();
        $this->assertSame('essays', $store->json('category.slug'));
        $categoryId = $store->json('category.id');

        $index = $this->getJson('/editor/categories');
        $index->assertOk();
        $this->assertCount(1, $index->json('categories'));

        $update = $this->putJson("/editor/categories/{$categoryId}", ['name_en' => 'Essays', 'description_en' => 'Longer pieces']);
        $update->assertOk();
        $this->assertSame('Longer pieces', $update->json('category.description_en'));

        $destroy = $this->deleteJson("/editor/categories/{$categoryId}");
        $destroy->assertOk();
        $this->assertTrue(Category::withTrashed()->find($categoryId)->trashed());
    }

    public function test_store_rejects_a_missing_name_with_422(): void
    {
        $response = $this->postJson('/editor/categories', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name_en', $response->json('errors'));
    }

    // ── slug collision handling through the API ─────────────────────────

    public function test_a_colliding_name_gets_a_deduplicated_slug_through_the_api(): void
    {
        $first = $this->postJson('/editor/categories', ['name_en' => 'Design']);
        $second = $this->postJson('/editor/categories', ['name_en' => 'Design']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('design', $first->json('category.slug'));
        $this->assertSame('design-2', $second->json('category.slug'));
    }

    // ── cycle prevention ─────────────────────────────────────────────────

    public function test_a_category_cannot_be_assigned_as_its_own_parent(): void
    {
        $category = Category::create(['name_en' => 'Root']);

        $response = $this->putJson("/editor/categories/{$category->id}", ['parent_id' => $category->id]);

        $response->assertStatus(422);
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_a_descendant_cannot_be_assigned_as_the_parent(): void
    {
        $root = Category::create(['name_en' => 'Root']);
        $child = Category::create(['name_en' => 'Child', 'parent_id' => $root->id]);

        $response = $this->putJson("/editor/categories/{$root->id}", ['parent_id' => $child->id]);

        $response->assertStatus(422);
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_destroying_a_category_promotes_its_children_to_root(): void
    {
        $root = Category::create(['name_en' => 'Root']);
        $child = Category::create(['name_en' => 'Child', 'parent_id' => $root->id]);

        $this->deleteJson("/editor/categories/{$root->id}")->assertOk();

        $this->assertNull($child->fresh()->parent_id);
    }

    // ── authorization denied for an unauthorized actor ──────────────────

    public function test_update_and_delete_are_denied_for_an_authors_tier_actor(): void
    {
        $category = Category::create(['name_en' => 'Essays']);

        // A REAL, authenticated actor is never affected by LocalDevRoleGate in
        // any environment — moving outside 'local' just makes that explicit.
        // `employee_l1` is `authors`-tier (allowed to view/create) but never
        // `admins` (required for update/delete) — blueprint §10.2's
        // "Category/Tag.update/delete: super_admin/admin only" row.
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));

        $this->putJson("/editor/categories/{$category->id}", ['name_en' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/editor/categories/{$category->id}")->assertStatus(403);
        $this->assertSame('Essays', $category->fresh()->name_en);
    }

    public function test_create_and_view_are_allowed_for_an_authors_tier_actor(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));

        $this->postJson('/editor/categories', ['name_en' => 'Allowed'])->assertCreated();
        $this->getJson('/editor/categories')->assertOk();
    }

    public function test_an_admin_may_update_and_delete(): void
    {
        $category = Category::create(['name_en' => 'Essays']);

        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->putJson("/editor/categories/{$category->id}", ['name_en' => 'Renamed'])->assertOk();
        $this->deleteJson("/editor/categories/{$category->id}")->assertOk();
    }
}

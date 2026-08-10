<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for the tag REST API (routes/editor.php:
 * /editor/tags[/{tag}]) — TagController + Store/UpdateTagRequest + TagPolicy.
 * Same local-dev authorization bypass pattern as CategoryControllerTest/
 * PostPersistenceTest.
 */
class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    // See CategoryControllerTest for why the trait's setUp() must be aliased
    // rather than left to a plain `use` (this class's own setUp() override
    // below would otherwise silently shadow it).
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── CRUD round trip ──────────────────────────────────────────────────

    public function test_store_then_index_then_update_then_destroy_round_trips(): void
    {
        $store = $this->postJson('/editor/tags', ['name_en' => 'Interviews']);
        $store->assertCreated();
        $this->assertSame('interviews', $store->json('tag.slug'));
        $tagId = $store->json('tag.id');

        $index = $this->getJson('/editor/tags');
        $index->assertOk();
        $this->assertCount(1, $index->json('tags'));

        $update = $this->putJson("/editor/tags/{$tagId}", ['name_en' => 'Interviews', 'name_fr' => 'Entretiens']);
        $update->assertOk();
        $this->assertSame('Entretiens', $update->json('tag.name_fr'));

        $destroy = $this->deleteJson("/editor/tags/{$tagId}");
        $destroy->assertOk();
        $this->assertTrue(Tag::withTrashed()->find($tagId)->trashed());
    }

    public function test_store_rejects_a_missing_name_with_422(): void
    {
        $response = $this->postJson('/editor/tags', []);

        $response->assertStatus(422);
        $this->assertArrayHasKey('name_en', $response->json('errors'));
    }

    // ── slug collision handling through the API ─────────────────────────

    public function test_a_colliding_name_gets_a_deduplicated_slug_through_the_api(): void
    {
        $first = $this->postJson('/editor/tags', ['name_en' => 'Craft']);
        $second = $this->postJson('/editor/tags', ['name_en' => 'Craft']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('craft', $first->json('tag.slug'));
        $this->assertSame('craft-2', $second->json('tag.slug'));
    }

    // ── destroy detaches from posts first ───────────────────────────────

    public function test_destroying_a_tag_detaches_it_from_every_post(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $tag = Tag::create(['name_en' => 'Removable']);
        $post->tags()->attach($tag->id);

        $this->deleteJson("/editor/tags/{$tag->id}")->assertOk();

        $this->assertCount(0, $post->fresh()->tags);
    }

    // ── authorization denied for an unauthorized actor ──────────────────

    public function test_update_and_delete_are_denied_for_an_authors_tier_actor(): void
    {
        $tag = Tag::create(['name_en' => 'Featured']);

        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'author'));

        $this->putJson("/editor/tags/{$tag->id}", ['name_en' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/editor/tags/{$tag->id}")->assertStatus(403);
        $this->assertSame('Featured', $tag->fresh()->name_en);
    }

    public function test_an_admin_may_update_and_delete(): void
    {
        $tag = Tag::create(['name_en' => 'Featured']);

        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));

        $this->putJson("/editor/tags/{$tag->id}", ['name_en' => 'Renamed'])->assertOk();
        $this->deleteJson("/editor/tags/{$tag->id}")->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Taxonomy;

use Heisenberg\Models\Category;
use Heisenberg\Models\Post;
use Heisenberg\Models\Tag;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Acceptance tests for attaching/detaching taxonomy to a post
 * (PostCategoryController / PostTagController) — the surface routes/editor.php wires at
 * POST/DELETE /editor/posts/{post}/categories/{category} and /tags/{tag}. Both authorize against
 * PostPolicy::update() (registered against Post::class already), not a bespoke taxonomy ability —
 * see those controllers' docblocks. Category attach/detach became POST/DELETE-per-item (2026-08-03,
 * mirroring tags exactly) now that Post::categories() is BelongsToMany — see that method's docblock.
 */
class PostTaxonomyAttachDetachTest extends TestCase
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

    public function test_a_category_can_be_attached_then_detached_from_a_post(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $category = Category::create(['name_en' => 'Essays']);

        $attach = $this->postJson("/editor/posts/{$post->id}/categories/{$category->id}");
        $attach->assertOk();
        $this->assertCount(1, $attach->json('categories'));
        $this->assertSame($category->id, $attach->json('categories.0.id'));

        // Attaching the same category again is a no-op (syncWithoutDetaching), not a duplicate row.
        $this->postJson("/editor/posts/{$post->id}/categories/{$category->id}")->assertOk();
        $this->assertCount(1, $post->fresh()->categories);

        $detach = $this->deleteJson("/editor/posts/{$post->id}/categories/{$category->id}");
        $detach->assertOk();
        $this->assertCount(0, $detach->json('categories'));
        $this->assertCount(0, $post->fresh()->categories);
    }

    public function test_a_post_can_carry_more_than_one_category_at_once(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $essays = Category::create(['name_en' => 'Essays']);
        $notes = Category::create(['name_en' => 'Field Notes']);

        $this->postJson("/editor/posts/{$post->id}/categories/{$essays->id}")->assertOk();
        $this->postJson("/editor/posts/{$post->id}/categories/{$notes->id}")->assertOk();

        $this->assertCount(2, $post->fresh()->categories);
    }

    public function test_attaching_a_nonexistent_category_404s(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->postJson("/editor/posts/{$post->id}/categories/999999")->assertNotFound();
    }

    public function test_a_tag_can_be_attached_then_detached_from_a_post(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $tag = Tag::create(['name_en' => 'Featured']);

        $attach = $this->postJson("/editor/posts/{$post->id}/tags/{$tag->id}");
        $attach->assertOk();
        $this->assertCount(1, $attach->json('tags'));
        $this->assertSame($tag->id, $attach->json('tags.0.id'));

        // Attaching the same tag again is a no-op (syncWithoutDetaching), not a duplicate row.
        $this->postJson("/editor/posts/{$post->id}/tags/{$tag->id}")->assertOk();
        $this->assertCount(1, $post->fresh()->tags);

        $detach = $this->deleteJson("/editor/posts/{$post->id}/tags/{$tag->id}");
        $detach->assertOk();
        $this->assertCount(0, $detach->json('tags'));
        $this->assertCount(0, $post->fresh()->tags);
    }

    public function test_attaching_a_nonexistent_tag_404s(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->postJson("/editor/posts/{$post->id}/tags/999999")->assertNotFound();
    }

    public function test_attach_and_detach_are_denied_for_an_actor_who_cannot_update_the_post(): void
    {
        // Owned by no one (author_id null via the local-dev guest bypass).
        $post = Post::create(['title_en' => 'Owned By No One', 'status' => 'draft', 'author_id' => null]);
        $category = Category::create(['name_en' => 'Essays']);
        $tag = Tag::create(['name_en' => 'Featured']);

        // A REAL, authenticated, non-owning, non-admin actor — PostPolicy::update()
        // denies it (same rule PostPersistenceTest's own authorization test exercises).
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(999, 'author'));

        $this->postJson("/editor/posts/{$post->id}/categories/{$category->id}")->assertStatus(403);
        $this->postJson("/editor/posts/{$post->id}/tags/{$tag->id}")->assertStatus(403);

        $this->assertCount(0, $post->fresh()->categories);
        $this->assertCount(0, $post->fresh()->tags);
    }

    public function test_the_posts_own_author_may_attach_and_detach(): void
    {
        $this->app['env'] = 'testing';
        $author = new FakeActor(42, 'author');
        $this->actingAs($author);

        $post = Post::create(['title_en' => 'Mine', 'status' => 'draft', 'author_id' => 42]);
        $category = Category::create(['name_en' => 'Essays']);

        $this->postJson("/editor/posts/{$post->id}/categories/{$category->id}")->assertOk();
        $this->assertCount(1, $post->fresh()->categories);
    }
}

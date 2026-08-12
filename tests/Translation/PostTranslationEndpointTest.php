<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * POST /editor/posts/{post}/translations (PostTranslationController, docs/content-translation.md
 * §4, Wave T2a) — create-or-update-translation. See that controller's own docblock for the full
 * create-vs-update contract this pins.
 */
class PostTranslationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function sourceWithContent(): Post
    {
        $post = Post::create([
            'title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft',
            'excerpt_en' => 'A short summary.',
            'slug' => 'hello-world',
        ]);
        $post->page_padding_x = 64;
        $post->page_padding_y = 72;
        $post->allow_comments = false;
        $post->save();

        $post->blocks()->create(['type' => 'heading', 'content' => ['name' => 'heisenberg/heading', 'attributes' => ['text' => 'Hello']], 'order' => 0]);
        $post->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['text' => 'World']], 'order' => 1]);

        $post->tocEntries()->create(['label' => 'Intro', 'anchor' => 'intro', 'order' => 0]);
        $post->tocEntries()->create(['label' => 'Body', 'anchor' => 'body', 'order' => 1]);

        return $post->fresh(['blocks', 'tocEntries']);
    }

    // ── create ────────────────────────────────────────────────────────────

    public function test_translating_an_email_document_produces_an_email_sibling(): void
    {
        $source = $this->sourceWithContent();
        $source->type = 'email';
        $source->save();

        $response = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);

        $response->assertStatus(201);
        $sibling = Post::query()->findOrFail($response->json('post_id'));
        $this->assertSame('email', $sibling->type);
    }

    public function test_create_copies_blocks_toc_and_settings_and_stamps_the_source_version(): void
    {
        $source = $this->sourceWithContent();

        $response = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);

        $response->assertStatus(201);
        $this->assertSame('fr', $response->json('locale'));
        $this->assertSame('draft', $response->json('status'));
        $postId = $response->json('post_id');
        $this->assertIsInt($postId);

        $sibling = Post::query()->with(['blocks', 'tocEntries'])->findOrFail($postId);

        $this->assertSame($source->translation_group_id, $sibling->translation_group_id);
        $this->assertSame('fr', $sibling->locale);
        $this->assertSame('draft', $sibling->status);
        $this->assertSame($source->content_version, $sibling->translated_from_version);

        // title/excerpt: the TARGET (fr) column carries a copy of the source's own text.
        $this->assertSame('Hello World', $sibling->title_fr);
        $this->assertSame('A short summary.', $sibling->excerpt_fr);
        // title_en is NOT NULL regardless of the row's own locale — mirrors the source's.
        $this->assertSame('Hello World', $sibling->title_en);

        // layout/discussion settings copied.
        $this->assertSame(64, $sibling->page_padding_x);
        $this->assertSame(72, $sibling->page_padding_y);
        $this->assertFalse((bool) $sibling->allow_comments);

        // blocks: same count, same content, order preserved.
        $this->assertCount(2, $sibling->blocks);
        $this->assertSame(
            $source->blocks->pluck('content')->all(),
            $sibling->blocks->sortBy('order')->values()->pluck('content')->all(),
        );

        // toc entries copied.
        $this->assertCount(2, $sibling->tocEntries);
        $this->assertSame(['intro', 'body'], $sibling->tocEntries->sortBy('order')->pluck('anchor')->values()->all());
    }

    /**
     * Shared-slug invariant (docs/content-translation.md §1, owner decision 2026-08-11): a
     * translation always gets the source's EXACT slug — no numeric-suffixing. An unrelated post
     * already holding that exact text in the target locale refuses the WHOLE translation with a
     * 422, BEFORE anything is written, rather than silently drifting the sibling onto a
     * different slug than its source.
     */
    public function test_create_is_422_when_the_exact_slug_collides_with_an_unrelated_post(): void
    {
        $source = $this->sourceWithContent(); // slug: hello-world, locale en
        // A pre-existing, unrelated fr post already holds the same slug text.
        Post::create(['title_en' => 'Other', 'title_fr' => 'Autre', 'locale' => 'fr', 'status' => 'draft', 'slug' => 'hello-world']);

        $response = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);

        $response->assertStatus(422);
        $this->assertNull($source->sibling('fr'));
    }

    public function test_create_slug_always_matches_the_source_exactly_when_no_collision(): void
    {
        $source = $this->sourceWithContent();

        $response = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);

        $response->assertStatus(201);
        $sibling = Post::findOrFail($response->json('post_id'));
        $this->assertSame('hello-world', $sibling->slug);
    }

    // ── 409 on an existing, un-flagged create ────────────────────────────

    public function test_create_is_409_when_the_sibling_already_exists(): void
    {
        $source = $this->sourceWithContent();
        $first = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);
        $first->assertStatus(201);
        $existingId = $first->json('post_id');

        $second = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr']);

        $second->assertStatus(409);
        $this->assertSame($existingId, $second->json('post_id'));
    }

    // ── update:true overwrite ────────────────────────────────────────────

    public function test_update_true_overwrites_blocks_and_bumps_version_but_preserves_the_siblings_own_fields(): void
    {
        $source = $this->sourceWithContent();
        $created = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr'])->json();
        $sibling = Post::findOrFail($created['post_id']);

        // The translator has since made this row genuinely its own.
        $sibling->title_fr = 'Bonjour le monde (traduit)';
        $sibling->slug = 'bonjour-le-monde';
        $sibling->status = 'published';
        $sibling->published_at = now();
        $sibling->save();

        // The source moves on.
        $source->blocks()->create(['type' => 'paragraph', 'content' => ['name' => 'heisenberg/paragraph', 'attributes' => ['text' => 'New paragraph']], 'order' => 2]);
        $source->bumpContentVersion();
        $sourceFresh = $source->fresh();

        $response = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr', 'update' => true]);

        $response->assertStatus(200);
        $this->assertSame($sibling->id, $response->json('post_id'));

        $updated = $sibling->fresh(['blocks']);
        $this->assertCount(3, $updated->blocks);
        $this->assertSame($sourceFresh->content_version, $updated->translated_from_version);

        // Preserved — never overwritten by an update:true re-translation.
        $this->assertSame('Bonjour le monde (traduit)', $updated->title_fr);
        $this->assertSame('bonjour-le-monde', $updated->slug);
        $this->assertSame('published', $updated->status);
    }

    public function test_update_true_captures_a_revision_of_the_siblings_blocks_before_overwriting(): void
    {
        $source = $this->sourceWithContent();
        $created = $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr'])->json();
        $sibling = Post::findOrFail($created['post_id']);
        $this->assertCount(0, $sibling->fresh()->revisions);

        $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr', 'update' => true])->assertOk();

        $this->assertCount(1, $sibling->fresh()->revisions);
    }

    // ── validation ────────────────────────────────────────────────────────

    public function test_translating_into_the_sources_own_locale_is_422(): void
    {
        $source = $this->sourceWithContent(); // locale: en

        $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'en'])->assertStatus(422);
    }

    public function test_an_unconfigured_locale_is_422(): void
    {
        $source = $this->sourceWithContent();

        $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'de'])->assertStatus(422);
    }

    // ── authorization ─────────────────────────────────────────────────────

    public function test_an_actor_who_cannot_update_the_source_post_is_denied_with_403(): void
    {
        $source = Post::create(['title_en' => 'Owned By No One', 'locale' => 'en', 'status' => 'draft', 'author_id' => null]);

        $this->app['env'] = 'testing';
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(999, 'viewer'));

        $this->postJson("/editor/posts/{$source->id}/translations", ['locale' => 'fr'])->assertStatus(403);
        $this->assertNull($source->sibling('fr'));
    }
}

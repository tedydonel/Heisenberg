<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The shared-slug invariant (docs/content-translation.md §1, owner decision 2026-08-11): a
 * translation group presents as ONE post, so every row in the group carries the IDENTICAL slug —
 * locale comes from the host's own URL prefix, never from the slug text. Covers
 * PostController::applySlug()'s group-wide rename propagation + all-or-nothing atomicity, and
 * Post::booted()'s `updating` hook's group-wide empty-slug regeneration.
 *
 * The CREATION side of the invariant (a new sibling copies the source's exact slug; an unrelated
 * collision refuses the whole translation) is covered by PostTranslationEndpointTest (HTTP) and
 * TranslationToolsTest (MCP), not here.
 */
class SharedSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function registry(): BlockRegistryService
    {
        return app(BlockRegistryService::class);
    }

    /** @return array{0: Post, 1: Post} [en, fr], same group, same starting slug. */
    private function group(): array
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);

        // Fresh from the DB so content_version reads its real (schema-default 0) value — Eloquent
        // never back-fills a column's DB default into the in-memory model after create().
        return [$en->fresh(), $fr->fresh()];
    }

    /** @param array<string, mixed> $overrides */
    private function envelope(array $overrides): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => $this->registry()->computeHash(),
            'computedStyles' => '',
            'autosave' => false,
            'blocks' => [],
        ], $overrides);
    }

    // ── rename propagation ──────────────────────────────────────────────

    public function test_renaming_the_en_post_propagates_the_new_slug_to_its_fr_sibling(): void
    {
        [$en, $fr] = $this->group();

        $response = $this->putJson("/editor/posts/{$en->id}", $this->envelope([
            'title_en' => 'Hello World', 'locale' => 'en',
            'content_version' => $en->content_version, 'slug' => 'renamed-post',
        ]));

        $response->assertOk();
        $this->assertSame('renamed-post', $response->json('post.slug'));
        $this->assertSame('renamed-post', $en->fresh()->slug);
        $this->assertSame('renamed-post', $fr->fresh()->slug);
    }

    public function test_renaming_the_fr_post_propagates_the_new_slug_to_its_en_sibling(): void
    {
        [$en, $fr] = $this->group();

        $response = $this->putJson("/editor/posts/{$fr->id}", $this->envelope([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr',
            'content_version' => $fr->content_version, 'slug' => 'renamed-post',
        ]));

        $response->assertOk();
        $this->assertSame('renamed-post', $response->json('post.slug'));
        $this->assertSame('renamed-post', $fr->fresh()->slug);
        $this->assertSame('renamed-post', $en->fresh()->slug);
    }

    public function test_rename_collision_in_a_siblings_locale_rejects_the_whole_rename_naming_the_language(): void
    {
        [$en, $fr] = $this->group();
        // An unrelated fr post already holds the candidate slug — en itself has no collision.
        Post::create(['title_en' => 'Other', 'title_fr' => 'Autre', 'locale' => 'fr', 'status' => 'draft', 'slug' => 'taken-in-fr']);

        $response = $this->putJson("/editor/posts/{$en->id}", $this->envelope([
            'title_en' => 'Hello World', 'locale' => 'en',
            'content_version' => $en->content_version, 'slug' => 'taken-in-fr',
        ]));

        $response->assertStatus(422);
        $this->assertStringContainsString('fr', (string) $response->json('message'));
    }

    public function test_rename_collision_leaves_neither_the_post_nor_the_sibling_changed(): void
    {
        [$en, $fr] = $this->group();
        Post::create(['title_en' => 'Other', 'title_fr' => 'Autre', 'locale' => 'fr', 'status' => 'draft', 'slug' => 'taken-in-fr']);

        $this->putJson("/editor/posts/{$en->id}", $this->envelope([
            'title_en' => 'Hello World', 'locale' => 'en',
            'content_version' => $en->content_version, 'slug' => 'taken-in-fr',
        ]))->assertStatus(422);

        $this->assertSame('hello-world', $en->fresh()->slug, 'the post itself must not change on a rejected rename');
        $this->assertSame('hello-world', $fr->fresh()->slug, 'the sibling must be untouched by a rejected rename — no partial write');
    }

    public function test_a_slug_free_in_its_own_locale_but_colliding_in_a_siblings_locale_is_still_rejected(): void
    {
        // The candidate is free in en (the post's own locale) but already used by an unrelated
        // post in fr (its sibling's locale) — proving the own-locale check alone is insufficient.
        [$en, $fr] = $this->group();
        Post::create(['title_en' => 'Blocker', 'title_fr' => 'Bloqueur', 'locale' => 'fr', 'status' => 'draft', 'slug' => 'blocked']);

        $response = $this->putJson("/editor/posts/{$en->id}", $this->envelope([
            'title_en' => 'Hello World', 'locale' => 'en',
            'content_version' => $en->content_version, 'slug' => 'blocked',
        ]));

        $response->assertStatus(422);
        $this->assertSame('hello-world', $fr->fresh()->slug);
    }

    // ── group-wide empty-slug regeneration ───────────────────────────────

    public function test_emptying_the_slug_regenerates_from_title_and_propagates_to_the_sibling(): void
    {
        [$en, $fr] = $this->group();

        $en->title_en = 'Retitled Post';
        $en->slug = '';
        $en->save();

        $this->assertSame('retitled-post', $en->fresh()->slug);
        $this->assertSame('retitled-post', $fr->fresh()->slug);
    }

    public function test_group_wide_regeneration_avoids_a_slug_already_taken_in_either_locale(): void
    {
        [$en, $fr] = $this->group();
        // Already taken in fr — the regeneration must still avoid it, even though it's checking
        // from the en row, because the winning slug is about to land on the fr sibling too.
        Post::create(['title_en' => 'Blocker', 'locale' => 'fr', 'status' => 'draft', 'slug' => 'retitled-post']);

        $en->title_en = 'Retitled Post';
        $en->slug = '';
        $en->save();

        $fresh = $en->fresh();
        $this->assertNotSame('retitled-post', $fresh->slug);
        $this->assertStringStartsWith('retitled-post-', $fresh->slug);
        $this->assertSame($fresh->slug, $fr->fresh()->slug, 'both rows must land on the SAME regenerated slug');
    }

    public function test_regeneration_on_an_untranslated_post_is_unaffected(): void
    {
        $solo = Post::create(['title_en' => 'Solo Post', 'locale' => 'en', 'status' => 'draft', 'slug' => 'solo-post']);

        $solo->title_en = 'Renamed Solo Post';
        $solo->slug = '';
        $solo->save();

        $this->assertSame('renamed-solo-post', $solo->fresh()->slug);
    }
}

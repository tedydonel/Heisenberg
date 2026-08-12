<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Models\Post;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The AI/MCP translation surface (docs/content-translation.md §6, Wave T2b):
 * `create_translation` (both surfaces, AUTHORS tier), `get_post`'s `translations`
 * map, and the taxonomy `update_category`/`update_tag` tools. Calls
 * {@see McpToolRegistry} directly rather than round-tripping through the HTTP
 * MCP endpoint ({@see McpServerTest} already covers that transport/auth layer) —
 * this file is about the tool CONTRACTS themselves, on both surfaces.
 */
class TranslationToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $args */
    private function callTool(string $name, array $args, string $surface = McpToolRegistry::SURFACE_EXTERNAL): array
    {
        $result = app(McpToolRegistry::class)->call($name, $args, McpToolRegistry::TIER_AUTHORS, $surface);

        return [
            'isError' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $args */
    private function toolData(string $name, array $args, string $surface = McpToolRegistry::SURFACE_EXTERNAL): array
    {
        $call = $this->callTool($name, $args, $surface);
        $this->assertFalse($call['isError'], $call['text']);

        return (array) json_decode($call['text'], true);
    }

    // ── create_translation ──────────────────────────────────────────

    public function test_create_translation_is_offered_on_both_surfaces(): void
    {
        $registry = app(McpToolRegistry::class);
        $editorNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR), 'name');
        $externalNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL), 'name');

        $this->assertContains('create_translation', $editorNames);
        $this->assertContains('create_translation', $externalNames);
    }

    public function test_create_translation_creates_a_draft_sibling_with_blocks_and_group(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => "[p]\n  One\n[/p]\n"]);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'],
            'target_locale' => 'fr',
            'title' => 'Bonjour',
            'code' => "[p]\n  Un\n[/p]\n",
        ]);

        $this->assertSame('fr', $result['locale']);
        $this->assertSame('draft', $result['status']);
        $this->assertFalse($result['outdated']);

        $sourcePost = Post::query()->findOrFail($source['id']);
        $sibling = Post::query()->findOrFail($result['post_id']);

        // Shared-slug invariant (docs/content-translation.md §1): the response echoes the real,
        // shared slug, which always equals the source's own.
        $this->assertSame($sourcePost->slug, $result['slug']);
        $this->assertSame($sourcePost->slug, $sibling->slug);
        $this->assertSame($sourcePost->translation_group_id, $sibling->translation_group_id);
        $this->assertSame('fr', $sibling->locale);
        $this->assertSame('Bonjour', $sibling->title_fr);
        $this->assertSame('draft', $sibling->status);
        $this->assertSame(1, $sibling->blocks()->count());
        $this->assertSame('heisenberg/paragraph', $sibling->blocks()->orderBy('order')->first()->content['name']);
        $this->assertSame((int) $sourcePost->content_version, $sibling->translated_from_version);
    }

    public function test_create_translation_of_an_email_document_produces_an_email_sibling(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Newsletter', 'code' => "[p]\n  One\n[/p]\n", 'type' => 'email']);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'],
            'target_locale' => 'fr',
            'title' => 'Infolettre',
            'code' => "[p]\n  Un\n[/p]\n",
        ]);

        $sibling = \Heisenberg\Models\Post::query()->findOrFail($result['post_id']);
        $this->assertSame('email', $sibling->type);
    }

    public function test_create_translation_copies_featured_settings_from_the_source(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $this->toolData('set_page_layout', ['post_id' => $source['id'], 'page_padding_x' => 40, 'page_padding_y' => 20]);
        $this->toolData('set_discussion', ['post_id' => $source['id'], 'allow_comments' => false]);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]',
        ]);

        $sibling = Post::query()->findOrFail($result['post_id']);
        $this->assertSame(40, $sibling->page_padding_x);
        $this->assertSame(20, $sibling->page_padding_y);
        $this->assertFalse($sibling->allow_comments);
    }

    public function test_create_translation_updates_an_existing_sibling_without_touching_status(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => "[p]\n  One\n[/p]\n"]);
        $first = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => "[p]\n  Un\n[/p]\n",
        ]);

        $sibling = Post::query()->findOrFail($first['post_id']);
        $sibling->status = 'published';
        $sibling->save();

        $this->toolData('update_post', ['id' => $source['id'], 'code' => "[p]\n  One, updated\n[/p]\n"]);
        $sourceAfterEdit = Post::query()->findOrFail($source['id']);

        // Editor surface: a human is driving, allowed to update a published sibling.
        $second = $this->toolData('create_translation', [
            'post_id' => $source['id'],
            'target_locale' => 'fr',
            'title' => 'Bonjour !',
            'code' => "[p]\n  Un\n[/p]\n\n[p]\n  Deux\n[/p]\n",
        ], McpToolRegistry::SURFACE_EDITOR);

        $this->assertSame($first['post_id'], $second['post_id']);
        $this->assertSame('published', $second['status']); // status untouched by the tool

        $sibling->refresh();
        $this->assertSame('published', $sibling->status);
        $this->assertSame('Bonjour !', $sibling->title_fr);
        $this->assertSame(2, $sibling->blocks()->count());
        $this->assertSame((int) $sourceAfterEdit->content_version, $sibling->translated_from_version);
    }

    public function test_external_surface_refuses_to_update_a_published_sibling(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $first = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]',
        ]);

        $sibling = Post::query()->findOrFail($first['post_id']);
        $sibling->status = 'published';
        $sibling->save();

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Nope', 'code' => '[p]Nope[/p]',
        ], McpToolRegistry::SURFACE_EXTERNAL);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('draft', $call['text']);

        $sibling->refresh();
        $this->assertSame('Bonjour', $sibling->title_fr);
        $this->assertSame('published', $sibling->status);
    }

    public function test_invalid_target_locale_is_refused(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'de', 'title' => 'X', 'code' => '[p]x[/p]',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('target_locale', $call['text']);
    }

    public function test_target_locale_matching_the_source_is_refused(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]', 'locale' => 'en']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'en', 'title' => 'X', 'code' => '[p]x[/p]',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('differ', $call['text']);
    }

    public function test_unparseable_shortcode_is_refused_with_line_numbers(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'X', 'code' => "[nope]x[/nope]",
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('line 1', $call['text']);
    }

    public function test_an_unknown_source_post_is_refused(): void
    {
        $call = $this->callTool('create_translation', [
            'post_id' => 999999, 'target_locale' => 'fr', 'title' => 'X', 'code' => '[p]x[/p]',
        ]);

        $this->assertTrue($call['isError']);
    }

    /**
     * Shared-slug invariant (docs/content-translation.md §1, owner decision 2026-08-11): a
     * translation always gets the source's EXACT slug — no numeric-suffixing. An unrelated post
     * already holding that exact text in the target locale refuses the WHOLE translation with an
     * error, rather than silently drifting the sibling onto a different slug than its source.
     */
    public function test_translation_slug_collision_with_an_unrelated_post_is_refused(): void
    {
        Post::create(['title_en' => 'Existing FR', 'locale' => 'fr', 'slug' => 'hello', 'status' => 'draft']);
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]', 'slug' => 'hello']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('hello', $call['text']);
        $this->assertNull(Post::query()->find($source['id'])->sibling('fr'));
    }

    /**
     * The `slug` ARGUMENT is accepted for wire-compat only and otherwise ignored (§1): a new
     * sibling always gets the source's own exact slug, regardless of what the caller passed —
     * and the response's `slug` field reports that real, shared value so the caller learns the
     * truth rather than assuming its input won.
     */
    public function test_explicit_slug_argument_is_ignored_the_source_slug_is_used(): void
    {
        Post::create(['title_en' => 'Taken', 'locale' => 'fr', 'slug' => 'taken', 'status' => 'draft']);
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $sourcePost = Post::query()->findOrFail($source['id']);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]', 'slug' => 'taken',
        ]);

        $sibling = Post::query()->findOrFail($result['post_id']);
        $this->assertSame($sourcePost->slug, $sibling->slug);
        $this->assertNotSame('taken', $sibling->slug);
        $this->assertSame($sourcePost->slug, $result['slug']);
    }

    /**
     * An invalid-shaped `slug` argument is likewise just ignored (never applied, so never
     * validated) — the translation still succeeds off the source's own slug.
     */
    public function test_an_invalid_shaped_slug_argument_is_silently_ignored(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $sourcePost = Post::query()->findOrFail($source['id']);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]', 'slug' => 'Not Valid!',
        ]);

        $sibling = Post::query()->findOrFail($result['post_id']);
        $this->assertSame($sourcePost->slug, $sibling->slug);
    }

    // ── get_post translations ───────────────────────────────────────

    public function test_get_post_returns_the_translations_map(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $fetched = $this->toolData('get_post', ['id' => $source['id']]);
        $this->assertSame($source['id'], $fetched['translations']['en']['post_id']);
        $this->assertSame('source', $fetched['translations']['en']['status']);
        $this->assertNull($fetched['translations']['fr']['post_id']);
        $this->assertSame('missing', $fetched['translations']['fr']['status']);

        $translation = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]x[/p]',
        ]);

        $fetchedAgain = $this->toolData('get_post', ['id' => $source['id']]);
        $this->assertSame($translation['post_id'], $fetchedAgain['translations']['fr']['post_id']);
        $this->assertSame('draft', $fetchedAgain['translations']['fr']['status']);

        // And from the sibling's own point of view, it reports itself as the source.
        $fromSibling = $this->toolData('get_post', ['id' => $translation['post_id']]);
        $this->assertSame('source', $fromSibling['translations']['fr']['status']);
        $this->assertSame($source['id'], $fromSibling['translations']['en']['post_id']);
    }

    // ── update_category / update_tag ────────────────────────────────

    public function test_update_category_sets_bilingual_fields_and_leaves_others_untouched(): void
    {
        $created = $this->toolData('create_category', ['name_en' => 'News']);

        $updated = $this->toolData('update_category', [
            'category_id' => $created['id'], 'name_fr' => 'Actualités', 'description_en' => 'Latest news',
        ]);

        $this->assertSame('News', $updated['name_en']);
        $this->assertSame('Actualités', $updated['name_fr']);
        $this->assertSame('Latest news', $updated['description_en']);
    }

    public function test_update_category_requires_at_least_one_field(): void
    {
        $created = $this->toolData('create_category', ['name_en' => 'News']);

        $call = $this->callTool('update_category', ['category_id' => $created['id']]);

        $this->assertTrue($call['isError']);
    }

    public function test_update_category_rejects_a_name_over_the_column_cap(): void
    {
        $created = $this->toolData('create_category', ['name_en' => 'News']);

        $call = $this->callTool('update_category', ['category_id' => $created['id'], 'name_en' => str_repeat('a', 256)]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('255', $call['text']);
    }

    public function test_update_category_rejects_an_unknown_id(): void
    {
        $call = $this->callTool('update_category', ['category_id' => 999999, 'name_fr' => 'X']);

        $this->assertTrue($call['isError']);
    }

    public function test_update_tag_sets_bilingual_fields(): void
    {
        $created = $this->toolData('create_tag', ['name_en' => 'Featured']);

        $updated = $this->toolData('update_tag', ['tag_id' => $created['id'], 'name_fr' => 'En vedette']);

        $this->assertSame('Featured', $updated['name_en']);
        $this->assertSame('En vedette', $updated['name_fr']);
    }

    public function test_update_tag_requires_at_least_one_field(): void
    {
        $created = $this->toolData('create_tag', ['name_en' => 'Featured']);

        $call = $this->callTool('update_tag', ['tag_id' => $created['id']]);

        $this->assertTrue($call['isError']);
    }

    public function test_update_tag_rejects_a_name_over_the_column_cap(): void
    {
        $created = $this->toolData('create_tag', ['name_en' => 'Featured']);

        $call = $this->callTool('update_tag', ['tag_id' => $created['id'], 'name_fr' => str_repeat('b', 256)]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('255', $call['text']);
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Models\Post;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The MCP translation surface (docs/content-translation.md §0/§6) and the taxonomy
 * `update_category`/`update_tag` tools.
 *
 * `create_translation` and `get_post`'s `translations` map were rebuilt here for the single-row
 * translation model: a translation is no longer a sibling `heisenberg_posts` row — it is
 * locale-suffixed attribute variants on the SAME row. `create_translation` now writes
 * `title_<locale>`/`excerpt_<locale>` directly and folds a translated `code` document into the
 * post's EXISTING blocks by position (never replacing the tree); `get_post`'s `translations` map
 * reports per-locale completeness ({@see \Heisenberg\Services\TranslationStatusService}) instead
 * of a sibling post_id/status pair. `set_featured_image`'s "fatals on every call" coverage lives
 * in {@see SeoMediaToolsTest} (it is unrelated to translation — a plain content tool that merely
 * happened to break from the same sibling-API removal).
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

    public function test_create_translation_is_offered_on_both_surfaces(): void
    {
        $registry = app(McpToolRegistry::class);
        $editorNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR), 'name');
        $externalNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL), 'name');

        $this->assertContains('create_translation', $editorNames);
        $this->assertContains('create_translation', $externalNames);
    }

    public function test_invalid_target_locale_is_refused(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'de', 'title' => 'X',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('target_locale', $call['text']);
    }

    public function test_target_locale_matching_the_posts_own_home_locale_is_refused(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]', 'locale' => 'en']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'en', 'title' => 'X',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('differ', $call['text']);
    }

    public function test_unparseable_code_is_refused_with_line_numbers(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'code' => '[nope]x[/nope]',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('line 1', $call['text']);
    }

    public function test_an_unknown_post_is_refused(): void
    {
        $call = $this->callTool('create_translation', [
            'post_id' => 999999, 'target_locale' => 'fr', 'title' => 'X',
        ]);

        $this->assertTrue($call['isError']);
    }

    public function test_requires_at_least_one_of_title_excerpt_code(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
        ]);

        $this->assertTrue($call['isError']);
    }

    public function test_title_and_excerpt_write_to_locale_suffixed_columns_without_touching_english(): void
    {
        $source = $this->toolData('create_post', [
            'title' => 'Hello', 'code' => '[p]x[/p]', 'excerpt_en' => 'An English summary',
        ]);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
            'title' => 'Bonjour', 'excerpt' => 'Un résumé',
        ]);

        $this->assertSame($source['id'], $result['post_id']);
        $this->assertSame('fr', $result['locale']);

        $post = Post::query()->findOrFail($source['id']);
        $this->assertSame('Bonjour', $post->title_fr);
        $this->assertSame('Un résumé', $post->excerpt_fr);
        // The English columns are untouched by a French translation call.
        $this->assertSame('Hello', $post->title_en);
        $this->assertSame('An English summary', $post->excerpt_en);
    }

    public function test_code_folds_translated_text_into_existing_blocks_by_position_without_touching_english(): void
    {
        $source = $this->toolData('create_post', [
            'title' => 'Hello', 'code' => "[p]One[/p]\n\n[p]Two[/p]\n",
        ]);

        $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
            'code' => "[p]Un[/p]\n\n[p]Deux[/p]\n",
        ]);

        $blocks = Post::query()->findOrFail($source['id'])->blocks()->orderBy('order')->get();
        $this->assertSame(2, $blocks->count());

        $first = $blocks[0]->content['attributes'];
        $second = $blocks[1]->content['attributes'];

        $this->assertSame('One', $first['content']);
        $this->assertSame('Un', $first['content_fr']);
        $this->assertSame('Two', $second['content']);
        $this->assertSame('Deux', $second['content_fr']);
    }

    public function test_code_fold_bumps_content_version_and_snapshots_a_revision(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);
        $before = (int) Post::query()->findOrFail($source['id'])->content_version;

        $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'code' => '[p]Un[/p]',
        ]);

        $post = Post::query()->findOrFail($source['id']);
        $this->assertGreaterThan($before, $post->content_version);
        $this->assertSame(1, $post->revisions()->count());
    }

    public function test_a_block_count_mismatch_is_refused_and_stored_blocks_are_untouched(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
            'code' => "[p]Un[/p]\n\n[p]Deux[/p]\n",
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('block count differs', $call['text']);

        $stored = Post::query()->findOrFail($source['id'])->blocks()->orderBy('order')->get();
        $this->assertSame(1, $stored->count());
        $this->assertSame('One', $stored[0]->content['attributes']['content']);
        $this->assertArrayNotHasKey('content_fr', $stored[0]->content['attributes']);
    }

    public function test_a_block_name_mismatch_at_a_position_is_refused(): void
    {
        $source = $this->toolData('create_post', [
            'title' => 'Hello', 'code' => "[h2]Title[/h2]\n\n[p]Body[/p]\n",
        ]);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
            // Both paragraphs where the source has a heading then a paragraph — same COUNT,
            // different NAME at position 0.
            'code' => "[p]Titre[/p]\n\n[p]Corps[/p]\n",
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('block name mismatch', $call['text']);
    }

    public function test_returns_the_target_locales_completeness(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);

        $result = $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr',
            'title' => 'Bonjour', 'code' => '[p]Un[/p]',
        ]);

        $this->assertSame($source['id'], $result['post_id']);
        $this->assertSame('fr', $result['locale']);
        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['blocks_translated']);
        $this->assertSame(1, $result['blocks_total']);
    }

    public function test_no_draft_only_restriction_on_the_external_surface(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);
        Post::query()->whereKey($source['id'])->update(['status' => 'published']);

        $call = $this->callTool('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour',
        ], McpToolRegistry::SURFACE_EXTERNAL);

        $this->assertFalse($call['isError'], $call['text']);
        // Still published — create_translation never touches lifecycle status.
        $this->assertSame('published', Post::query()->findOrFail($source['id'])->status);
    }

    public function test_get_post_translations_map_reports_per_locale_completeness(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);
        $this->toolData('create_translation', [
            'post_id' => $source['id'], 'target_locale' => 'fr', 'title' => 'Bonjour', 'code' => '[p]Un[/p]',
        ]);

        $result = $this->toolData('get_post', ['id' => $source['id']]);

        $this->assertArrayHasKey('en', $result['translations']);
        $this->assertArrayHasKey('fr', $result['translations']);
        $this->assertTrue($result['translations']['en']['is_default']);
        $this->assertTrue($result['translations']['en']['complete']);
        $this->assertFalse($result['translations']['fr']['is_default']);
        $this->assertTrue($result['translations']['fr']['complete']);
        $this->assertSame(1, $result['translations']['fr']['blocks_translated']);
        $this->assertSame(1, $result['translations']['fr']['blocks_total']);
    }

    public function test_get_post_translations_map_reports_incomplete_before_any_translation(): void
    {
        $source = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]One[/p]']);

        $result = $this->toolData('get_post', ['id' => $source['id']]);

        $this->assertFalse($result['translations']['fr']['title']);
        $this->assertFalse($result['translations']['fr']['complete']);
    }

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

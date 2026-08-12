<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The AI/MCP SEO + media surface (docs/seo-system.md §6, Wave A1): `get_seo`/`update_seo`/
 * `analyze_seo`, and media's `update_media`/`set_featured_image`. Calls {@see McpToolRegistry}
 * directly rather than round-tripping through the HTTP MCP endpoint ({@see McpServerTest}
 * already covers that transport/auth layer) — this file is about the tool CONTRACTS
 * themselves, on both surfaces.
 */
class SeoMediaToolsTest extends TestCase
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

    private function imageFile(): PublicFile
    {
        return PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/seo-' . uniqid('', true) . '.jpg',
            'original_name' => 'photo.jpg',
            'stored_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 2048,
        ]);
    }

    private function docFile(): PublicFile
    {
        return PublicFile::create([
            'type' => 'pdf',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/doc-' . uniqid('', true) . '.pdf',
            'original_name' => 'brief.pdf',
            'stored_name' => 'brief.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
        ]);
    }

    // ── get_seo / update_seo tool registration ──────────────────────

    public function test_seo_and_media_tools_are_offered_on_both_surfaces(): void
    {
        $registry = app(McpToolRegistry::class);
        $editorNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR), 'name');
        $externalNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL), 'name');

        foreach (['get_seo', 'update_seo', 'analyze_seo', 'update_media', 'set_featured_image'] as $tool) {
            $this->assertContains($tool, $editorNames, "{$tool} missing on editor surface");
            $this->assertContains($tool, $externalNames, "{$tool} missing on external surface");
        }
    }

    // ── get_seo ────────────────────────────────────────────────────

    public function test_get_seo_returns_has_seo_false_when_no_row_exists(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $result = $this->toolData('get_seo', ['post_id' => $post['id']]);

        $this->assertFalse($result['has_seo']);
        $this->assertNull($result['seo']);
    }

    public function test_get_seo_returns_the_populated_row(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $postModel = Post::query()->findOrFail($post['id']);

        SeoMeta::create([
            'able_type' => $postModel->getMorphClass(),
            'able_id' => $postModel->getKey(),
            'meta_title_en' => 'A Great Title',
            'meta_description_en' => 'A great description of the page.',
            'robots' => 'index, follow',
            'in_sitemap' => true,
        ]);

        $result = $this->toolData('get_seo', ['post_id' => $post['id']]);

        $this->assertTrue($result['has_seo']);
        $this->assertSame('A Great Title', $result['seo']['meta_title_en']);
        $this->assertSame('A great description of the page.', $result['seo']['meta_description_en']);
        $this->assertTrue($result['seo']['in_sitemap']);
    }

    // ── update_seo ─────────────────────────────────────────────────

    public function test_update_seo_creates_a_row_with_locale_neutral_and_localized_fields(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]', 'locale' => 'en']);

        $result = $this->toolData('update_seo', [
            'post_id' => $post['id'],
            'meta_title' => 'Hello World',
            'meta_description' => 'A description for search results.',
            'og_image' => 'https://example.com/og.png',
            'canonical_url' => 'https://example.com/hello',
            'robots' => 'index, follow',
            'focus_keyphrase' => 'hello world',
            'in_sitemap' => true,
            'schema_type' => 'Article',
            'schema_data' => ['author' => 'Jane'],
        ]);

        $this->assertTrue($result['has_seo']);
        $this->assertSame('Hello World', $result['seo']['meta_title_en']);
        $this->assertSame('A description for search results.', $result['seo']['meta_description_en']);
        $this->assertSame('https://example.com/og.png', $result['seo']['og_image']);
        $this->assertSame('https://example.com/hello', $result['seo']['canonical_url']);
        $this->assertSame('index, follow', $result['seo']['robots']);
        $this->assertSame('hello world', $result['seo']['focus_keyphrase_en']);
        $this->assertTrue($result['seo']['in_sitemap']);
        $this->assertSame('Article', $result['seo']['schema_type']);
        $this->assertSame(['author' => 'Jane'], $result['seo']['schema_data']);
    }

    public function test_update_seo_updates_the_existing_row_in_place(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $this->toolData('update_seo', ['post_id' => $post['id'], 'meta_title' => 'First']);

        $second = $this->toolData('update_seo', ['post_id' => $post['id'], 'meta_title' => 'Second']);

        $this->assertSame('Second', $second['seo']['meta_title_en']);
        $this->assertSame(1, SeoMeta::query()->count());
    }

    public function test_update_seo_locale_routes_fields_to_the_fr_columns(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]', 'locale' => 'en']);

        $result = $this->toolData('update_seo', [
            'post_id' => $post['id'],
            'locale' => 'fr',
            'meta_title' => 'Bonjour le monde',
            'meta_description' => 'Une description en francais.',
            'focus_keyphrase' => 'bonjour',
        ]);

        $this->assertSame('Bonjour le monde', $result['seo']['meta_title_fr']);
        $this->assertSame('Une description en francais.', $result['seo']['meta_description_fr']);
        $this->assertSame('bonjour', $result['seo']['focus_keyphrase_fr']);
        $this->assertNull($result['seo']['meta_title_en']);
    }

    public function test_update_seo_defaults_locale_to_the_posts_own_locale(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Bonjour', 'code' => '[p]x[/p]', 'locale' => 'fr']);

        $result = $this->toolData('update_seo', ['post_id' => $post['id'], 'meta_title' => 'Titre']);

        $this->assertSame('Titre', $result['seo']['meta_title_fr']);
        $this->assertNull($result['seo']['meta_title_en']);
    }

    public function test_update_seo_requires_at_least_one_field(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('update_seo', ['post_id' => $post['id']]);

        $this->assertTrue($call['isError']);
    }

    public function test_update_seo_rejects_a_field_over_the_column_cap(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('update_seo', ['post_id' => $post['id'], 'meta_title' => str_repeat('a', 256)]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('255', $call['text']);
    }

    public function test_update_seo_accepts_valid_robots_token_combinations(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $result = $this->toolData('update_seo', ['post_id' => $post['id'], 'robots' => 'noindex, nofollow']);

        $this->assertSame('noindex, nofollow', $result['seo']['robots']);
    }

    public function test_update_seo_rejects_an_invalid_robots_token(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('update_seo', ['post_id' => $post['id'], 'robots' => 'index, sometimes']);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('robots', $call['text']);
    }

    public function test_update_seo_rejects_a_non_boolean_in_sitemap(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('update_seo', ['post_id' => $post['id'], 'in_sitemap' => 'yes']);

        $this->assertTrue($call['isError']);
    }

    public function test_update_seo_rejects_non_array_schema_data(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('update_seo', ['post_id' => $post['id'], 'schema_data' => 'not-an-object']);

        $this->assertTrue($call['isError']);
    }

    public function test_update_seo_rejects_an_unknown_post(): void
    {
        $call = $this->callTool('update_seo', ['post_id' => 999999, 'meta_title' => 'X']);

        $this->assertTrue($call['isError']);
    }

    // ── analyze_seo ────────────────────────────────────────────────

    public function test_analyze_seo_returns_the_analyzer_shape(): void
    {
        $post = $this->toolData('create_post', [
            'title' => 'Hello World',
            'code' => "[p]\n  " . str_repeat('word ', 320) . "\n[/p]\n",
        ]);
        $this->toolData('update_seo', [
            'post_id' => $post['id'],
            'meta_title' => 'Hello World — A Great Read For Everyone Today',
            'meta_description' => str_repeat('a', 120),
        ]);

        $result = $this->toolData('analyze_seo', ['post_id' => $post['id']]);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('rating', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertIsInt($result['score']);
        $this->assertContains($result['rating'], ['poor', 'needs-work', 'good', 'excellent']);
        $this->assertNotEmpty($result['checks']);
        $this->assertArrayHasKey('id', $result['checks'][0]);
        $this->assertArrayHasKey('status', $result['checks'][0]);
    }

    // ── update_media ───────────────────────────────────────────────

    public function test_update_media_sets_alt_caption_and_credit_both_locales(): void
    {
        $file = $this->imageFile();

        $result = $this->toolData('update_media', [
            'file_id' => $file->id,
            'alt_text_en' => 'A red bicycle',
            'alt_text_fr' => 'Un velo rouge',
            'caption_en' => 'A bicycle leaning on a wall.',
            'caption_fr' => 'Un velo appuye contre un mur.',
            'credit' => 'Jane Doe',
        ]);

        $this->assertSame('A red bicycle', $result['alt_text_en']);
        $this->assertSame('Un velo rouge', $result['alt_text_fr']);
        $this->assertSame('A bicycle leaning on a wall.', $result['caption_en']);
        $this->assertSame('Un velo appuye contre un mur.', $result['caption_fr']);
        $this->assertSame('Jane Doe', $result['credit']);
        $this->assertSame($file->id, $result['id']);
        $this->assertNotSame('', $result['url']);

        $file->refresh();
        $this->assertSame('A red bicycle', $file->alt_text_en);
    }

    public function test_update_media_rejects_alt_text_over_255_characters(): void
    {
        $file = $this->imageFile();

        $call = $this->callTool('update_media', ['file_id' => $file->id, 'alt_text_en' => str_repeat('a', 256)]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('255', $call['text']);
    }

    public function test_update_media_rejects_caption_over_500_characters(): void
    {
        $file = $this->imageFile();

        $call = $this->callTool('update_media', ['file_id' => $file->id, 'caption_en' => str_repeat('a', 501)]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('500', $call['text']);
    }

    public function test_update_media_requires_at_least_one_field(): void
    {
        $file = $this->imageFile();

        $call = $this->callTool('update_media', ['file_id' => $file->id]);

        $this->assertTrue($call['isError']);
    }

    public function test_update_media_rejects_an_unknown_file(): void
    {
        $call = $this->callTool('update_media', ['file_id' => 999999, 'alt_text_en' => 'X']);

        $this->assertTrue($call['isError']);
    }

    // ── set_featured_image ────────────────────────────────────────

    public function test_set_featured_image_sets_and_clears(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $file = $this->imageFile();

        $set = $this->toolData('set_featured_image', ['post_id' => $post['id'], 'file_id' => $file->id]);
        $this->assertSame($file->id, $set['featured_image_id']);
        $this->assertSame($file->id, Post::query()->findOrFail($post['id'])->featured_image_id);

        $cleared = $this->toolData('set_featured_image', ['post_id' => $post['id']]);
        $this->assertNull($cleared['featured_image_id']);
        $this->assertNull(Post::query()->findOrFail($post['id'])->featured_image_id);
    }

    public function test_set_featured_image_rejects_a_missing_file(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);

        $call = $this->callTool('set_featured_image', ['post_id' => $post['id'], 'file_id' => 999999]);

        $this->assertTrue($call['isError']);
    }

    public function test_set_featured_image_rejects_a_non_image_file(): void
    {
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $doc = $this->docFile();

        $call = $this->callTool('set_featured_image', ['post_id' => $post['id'], 'file_id' => $doc->id]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('image', $call['text']);
    }

    public function test_set_featured_image_is_offered_on_both_surfaces_with_no_draft_only_restriction(): void
    {
        // Mirrors set_page_layout/set_discussion's posture (no `surface` key — see
        // McpToolRegistry::tools()): the external surface may set a featured image on a
        // PUBLISHED post too, unlike create_post/update_post/create_translation's draft-only
        // stance for content writes.
        $post = $this->toolData('create_post', ['title' => 'Hello', 'code' => '[p]x[/p]']);
        $postModel = Post::query()->findOrFail($post['id']);
        $postModel->status = 'published';
        $postModel->save();
        $file = $this->imageFile();

        $result = $this->toolData(
            'set_featured_image',
            ['post_id' => $post['id'], 'file_id' => $file->id],
            McpToolRegistry::SURFACE_EXTERNAL,
        );

        $this->assertSame($file->id, $result['featured_image_id']);
    }
}

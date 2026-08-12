<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Models\Post;
use Heisenberg\Models\PublicFile;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Owner-reported gap (docs/content-translation.md §1, "Featured image is group-wide too",
 * 2026-08-12): a featured image is language-neutral — the same photo illustrates the article in
 * every locale, same posture as the shared-slug invariant. Setting (or clearing) it on ANY row in
 * a translation group must propagate to every sibling, so an author who translates a post before
 * it has a featured image never has to set the same image twice.
 *
 * Covers PostSettingsController::updateFeaturedImage() (PUT /editor/posts/{post}/featured-image)
 * and the MCP `set_featured_image` tool. The creation-time copy (PostTranslationController::
 * createSibling()) and the `update: true` re-translation flow are covered by
 * PostTranslationEndpointTest — this file is about propagation from an already-existing group.
 */
class FeaturedImagePropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** @return array{0: Post, 1: Post} [en, fr], same translation group. */
    private function group(): array
    {
        $en = Post::create(['title_en' => 'Hello World', 'locale' => 'en', 'status' => 'draft', 'slug' => 'hello-world']);
        $fr = Post::create([
            'title_en' => 'Hello World', 'title_fr' => 'Bonjour le monde', 'locale' => 'fr', 'status' => 'draft',
            'translation_group_id' => $en->translation_group_id, 'slug' => 'hello-world',
        ]);

        return [$en->fresh(), $fr->fresh()];
    }

    private function imageRow(): PublicFile
    {
        return PublicFile::create([
            'type' => 'jpg',
            'disk' => 'uploads',
            'stored_path' => 'media/2026/08/featured-' . uniqid('', true) . '.jpg',
            'original_name' => 'featured.jpg',
            'stored_name' => 'featured.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    // ── HTTP endpoint: set propagates, both directions ──────────────────────

    public function test_setting_on_the_source_propagates_to_the_sibling(): void
    {
        [$en, $fr] = $this->group();
        $file = $this->imageRow();

        $response = $this->putJson("/editor/posts/{$en->id}/featured-image", ['featured_image_id' => $file->id]);

        $response->assertOk();
        $this->assertSame($file->id, $en->fresh()->featured_image_id);
        $this->assertSame($file->id, $fr->fresh()->featured_image_id, 'the sibling must pick up the same image');
    }

    public function test_setting_on_the_sibling_propagates_to_the_source(): void
    {
        [$en, $fr] = $this->group();
        $file = $this->imageRow();

        $response = $this->putJson("/editor/posts/{$fr->id}/featured-image", ['featured_image_id' => $file->id]);

        $response->assertOk();
        $this->assertSame($file->id, $fr->fresh()->featured_image_id);
        $this->assertSame($file->id, $en->fresh()->featured_image_id, 'propagation must not be one-directional');
    }

    // ── HTTP endpoint: clear propagates too ──────────────────────────────────

    public function test_clearing_on_the_source_propagates_to_the_sibling(): void
    {
        [$en, $fr] = $this->group();
        $file = $this->imageRow();
        $this->putJson("/editor/posts/{$en->id}/featured-image", ['featured_image_id' => $file->id])->assertOk();
        $this->assertSame($file->id, $fr->fresh()->featured_image_id);

        $response = $this->putJson("/editor/posts/{$en->id}/featured-image", ['featured_image_id' => null]);

        $response->assertOk();
        $this->assertNull($en->fresh()->featured_image_id);
        $this->assertNull($fr->fresh()->featured_image_id, 'clearing must propagate group-wide too, not just setting');
    }

    // ── no siblings: still works, no crash ───────────────────────────────────

    public function test_an_untranslated_post_is_unaffected_by_propagation(): void
    {
        $solo = Post::create(['title_en' => 'Solo Post', 'locale' => 'en', 'status' => 'draft', 'slug' => 'solo-post']);
        $file = $this->imageRow();

        $response = $this->putJson("/editor/posts/{$solo->id}/featured-image", ['featured_image_id' => $file->id]);

        $response->assertOk();
        $this->assertSame($file->id, $solo->fresh()->featured_image_id);
    }

    // ── MCP tool: same propagation ───────────────────────────────────────────

    /** @param array<string, mixed> $args */
    private function callMcpTool(string $name, array $args): array
    {
        $result = app(McpToolRegistry::class)->call($name, $args, McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL);

        return (array) json_decode((string) ($result['content'][0]['text'] ?? ''), true);
    }

    public function test_mcp_set_featured_image_propagates_to_the_sibling(): void
    {
        [$en, $fr] = $this->group();
        $file = $this->imageRow();

        $result = $this->callMcpTool('set_featured_image', ['post_id' => $en->id, 'file_id' => $file->id]);

        $this->assertSame($file->id, $result['featured_image_id']);
        $this->assertSame($file->id, $en->fresh()->featured_image_id);
        $this->assertSame($file->id, $fr->fresh()->featured_image_id, 'the MCP tool must not create the drift the UI now prevents');
    }

    public function test_mcp_set_featured_image_clear_propagates_to_the_sibling(): void
    {
        [$en, $fr] = $this->group();
        $file = $this->imageRow();
        $this->callMcpTool('set_featured_image', ['post_id' => $en->id, 'file_id' => $file->id]);

        $result = $this->callMcpTool('set_featured_image', ['post_id' => $en->id]);

        $this->assertNull($result['featured_image_id']);
        $this->assertNull($fr->fresh()->featured_image_id);
    }

    public function test_mcp_set_featured_image_on_an_untranslated_post_does_not_crash(): void
    {
        $solo = Post::create(['title_en' => 'Solo Post', 'locale' => 'en', 'status' => 'draft', 'slug' => 'solo-post']);
        $file = $this->imageRow();

        $result = $this->callMcpTool('set_featured_image', ['post_id' => $solo->id, 'file_id' => $file->id]);

        $this->assertSame($file->id, $result['featured_image_id']);
    }
}

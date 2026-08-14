<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Models\Block;
use Heisenberg\Models\Post;
use Heisenberg\Models\Revision;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `trash_post` / `restore_post` (McpToolRegistry) — the MCP counterpart of
 * PostTrashControllerTest, both surfaces (see docs/ai-capability-matrix.md's Reversibility
 * section for why these hold no draft-only/editor-only restriction). Calls McpToolRegistry
 * directly, same posture as SeoMediaToolsTest.
 *
 * Both tools are double-gated: the AUTHORS tool tier (asserted here via `callTool`'s own
 * tier argument), THEN PostPolicy::delete()/restore() against the CALLING actor
 * (Auth::user() or a GuestActor) — same as the HTTP endpoint. `$this->app['env'] = 'local'`
 * exercises the LocalDevRoleGate bypass (same posture PostTrashControllerTest's happy-path
 * tests use) for the tests that aren't specifically about authorization.
 */
class PostTrashToolsTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
    }

    // ── registration ──────────────────────────────────────────────────────

    public function test_trash_post_and_restore_post_are_offered_on_both_surfaces(): void
    {
        $registry = app(McpToolRegistry::class);
        $editorNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR), 'name');
        $externalNames = array_column($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EXTERNAL), 'name');

        foreach (['trash_post', 'restore_post'] as $tool) {
            $this->assertContains($tool, $editorNames, "{$tool} missing on editor surface");
            $this->assertContains($tool, $externalNames, "{$tool} missing on external surface");
        }
    }

    public function test_trash_post_requires_at_least_the_authors_tier(): void
    {
        $result = app(McpToolRegistry::class)->call('trash_post', ['post_id' => 999999], McpToolRegistry::TIER_READ);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('authors', $result['content'][0]['text']);
    }

    // ── trash_post ───────────────────────────────────────────────────────

    public function test_trash_post_soft_deletes_and_cascades_blocks_and_revisions(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        Revision::snapshotOf($post);

        $result = $this->toolData('trash_post', ['post_id' => $post->id]);

        $this->assertSame($post->id, $result['post_id']);
        $this->assertTrue($result['trashed']);
        $this->assertNotNull($result['deleted_at']);
        $this->assertTrue($post->fresh()->trashed());
        $this->assertSame(0, Block::where('post_id', $post->id)->count());
        $this->assertSame(1, Block::withTrashed()->where('post_id', $post->id)->count());
        $this->assertSame(0, Revision::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::withTrashed()->where('post_id', $post->id)->count());
    }

    public function test_trash_post_rejects_an_unknown_post(): void
    {
        $call = $this->callTool('trash_post', ['post_id' => 999999]);

        $this->assertTrue($call['isError']);
    }

    public function test_trashed_posts_disappear_from_list_posts(): void
    {
        $visible = Post::create(['title_en' => 'Visible', 'status' => 'draft']);
        $trashed = Post::create(['title_en' => 'Trashed', 'status' => 'draft']);
        $this->toolData('trash_post', ['post_id' => $trashed->id]);

        $result = $this->toolData('list_posts', []);
        $ids = array_column($result, 'id');

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($trashed->id, $ids);
    }

    // ── restore_post ─────────────────────────────────────────────────────

    public function test_restore_post_undoes_trash_post_and_brings_back_blocks_and_revisions(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $post->blocks()->create(['type' => 'paragraph', 'content' => [], 'order' => 0]);
        Revision::snapshotOf($post);
        $this->toolData('trash_post', ['post_id' => $post->id]);

        $result = $this->toolData('restore_post', ['post_id' => $post->id]);

        $this->assertSame($post->id, $result['post_id']);
        $this->assertFalse($result['trashed']);
        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(1, Block::where('post_id', $post->id)->count());
        $this->assertSame(1, Revision::where('post_id', $post->id)->count());
    }

    public function test_restore_post_rejects_an_unknown_post(): void
    {
        $call = $this->callTool('restore_post', ['post_id' => 999999]);

        $this->assertTrue($call['isError']);
    }

    public function test_restore_post_rejects_a_post_that_is_not_trashed(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $call = $this->callTool('restore_post', ['post_id' => $post->id]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('not trashed', $call['text']);
    }

    // ── authorization: the acting user, not just the tool tier ──────────

    public function test_trash_post_and_restore_post_are_refused_for_a_non_admin_actor_even_though_the_tool_tier_allows_it(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);
        $trashedPost = Post::create(['title_en' => 'Y', 'status' => 'draft']);
        $trashedPost->delete();

        $this->app['env'] = 'testing';
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(999, 'author'));

        $trashCall = $this->callTool('trash_post', ['post_id' => $post->id]);
        $this->assertTrue($trashCall['isError']);
        $this->assertFalse($post->fresh()->trashed());

        $restoreCall = $this->callTool('restore_post', ['post_id' => $trashedPost->id]);
        $this->assertTrue($restoreCall['isError']);
        $this->assertTrue($trashedPost->fresh()->trashed());
    }

    public function test_trash_post_and_restore_post_succeed_for_an_admin_actor(): void
    {
        $post = Post::create(['title_en' => 'X', 'status' => 'draft']);

        $this->app['env'] = 'testing';
        $this->actingAs(new \Heisenberg\Tests\Taxonomy\FakeActor(1, 'admin'));

        $this->toolData('trash_post', ['post_id' => $post->id]);
        $this->assertTrue($post->fresh()->trashed());

        $this->toolData('restore_post', ['post_id' => $post->id]);
        $this->assertFalse($post->fresh()->trashed());
    }
}

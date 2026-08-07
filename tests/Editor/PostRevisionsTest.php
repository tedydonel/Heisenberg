<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Contracts\RoleGate;
use Heisenberg\Models\Revision;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The revision pipeline end-to-end through the real HTTP surface: every UPDATE
 * snapshots the pre-save tree (autosaves keep one rolling row, manual saves
 * accumulate capped), the read endpoints serve the history newest-first and a
 * revision's blocks in the exact client shape replaceDoc() consumes, and a
 * revision id belonging to another post is a 404 (IDOR posture).
 */
class PostRevisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 7]));
        $this->app->instance(RoleGate::class, new class implements RoleGate
        {
            public function is(Authenticatable $user, string $tier): bool
            {
                return true;
            }

            public function isAny(Authenticatable $user, array $tiers): bool
            {
                return true;
            }

            public function rolesOf(Authenticatable $user): array
            {
                return ['authors', 'admins'];
            }

            public function systemActor(): ?Authenticatable
            {
                return null;
            }
        });
    }

    /** A minimal valid save payload with one paragraph carrying $content. */
    private function payload(string $content, array $extra = []): array
    {
        return array_merge([
            'schemaVersion' => 1,
            'registryHash' => app(BlockRegistryService::class)->computeHash(),
            'title_en' => 'Revisions',
            'locale' => 'en',
            'blocks' => [[
                'id' => 'hb1',
                'name' => 'heisenberg/paragraph',
                'schemaVersion' => '1.0.0',
                'attributes' => ['content' => $content],
                'supports' => [],
                'innerBlocks' => [],
            ]],
        ], $extra);
    }

    /** @return array{0: int, 1: int} [postId, contentVersion] */
    private function createPost(string $content = 'v1'): array
    {
        $response = $this->postJson('/editor/posts', $this->payload($content))->assertCreated();

        return [(int) $response->json('post.id'), (int) $response->json('post.content_version')];
    }

    private function updatePost(int $id, int $version, string $content, bool $autosave = false): int
    {
        $response = $this->putJson("/editor/posts/{$id}", $this->payload($content, [
            'content_version' => $version,
            'autosave' => $autosave,
        ]))->assertOk();

        return (int) $response->json('post.content_version');
    }

    public function test_a_manual_update_snapshots_the_previous_tree(): void
    {
        [$id, $version] = $this->createPost('v1');
        $this->assertSame(0, Revision::query()->where('post_id', $id)->count(), 'a create has no old tree to snapshot');

        $this->updatePost($id, $version, 'v2');

        $revisions = Revision::query()->where('post_id', $id)->get();
        $this->assertCount(1, $revisions);
        $this->assertSame('manual', $revisions[0]->revision_type);
        $this->assertSame('v1', $revisions[0]->content_blocks[0]['content']['attributes']['content']);
    }

    public function test_autosaves_keep_one_rolling_snapshot(): void
    {
        [$id, $version] = $this->createPost('v1');
        $version = $this->updatePost($id, $version, 'v2', autosave: true);
        $version = $this->updatePost($id, $version, 'v3', autosave: true);
        $this->updatePost($id, $version, 'v4', autosave: true);

        $autosaves = Revision::query()->where('post_id', $id)->where('revision_type', 'auto_save')->get();
        $this->assertCount(1, $autosaves, 'each autosave replaces the previous rolling row');
        $this->assertSame('v3', $autosaves[0]->content_blocks[0]['content']['attributes']['content']);
    }

    public function test_manual_history_is_capped(): void
    {
        config()->set('heisenberg.revisions.keep', 3);
        [$id, $version] = $this->createPost('v1');
        foreach (range(2, 7) as $i) {
            $version = $this->updatePost($id, $version, "v{$i}");
        }

        $kept = Revision::query()->where('post_id', $id)->orderByDesc('id')->pluck('content_blocks');
        $this->assertCount(3, $kept);
        // Newest three snapshots are of v6, v5, v4 (each snapshot is the PRE-save tree).
        $this->assertSame('v6', $kept[0][0]['content']['attributes']['content']);
        $this->assertSame('v4', $kept[2][0]['content']['attributes']['content']);
    }

    public function test_index_lists_newest_first_with_metadata(): void
    {
        [$id, $version] = $this->createPost('v1');
        $version = $this->updatePost($id, $version, 'v2');
        $this->updatePost($id, $version, 'v3');

        $response = $this->getJson("/editor/posts/{$id}/revisions")->assertOk();
        $rows = $response->json('revisions');

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['id'] > $rows[1]['id'], 'newest first');
        foreach (['id', 'created_at', 'revision_type', 'title', 'blocks_count'] as $key) {
            $this->assertArrayHasKey($key, $rows[0]);
        }
        $this->assertSame(1, $rows[0]['blocks_count']);
    }

    public function test_show_returns_blocks_in_the_client_shape(): void
    {
        [$id, $version] = $this->createPost('v1');
        $this->updatePost($id, $version, 'v2');
        $revisionId = (int) Revision::query()->where('post_id', $id)->value('id');

        $blocks = $this->getJson("/editor/posts/{$id}/revisions/{$revisionId}")->assertOk()->json('blocks');

        $this->assertCount(1, $blocks);
        $this->assertSame('heisenberg/paragraph', $blocks[0]['name']);
        $this->assertSame('v1', $blocks[0]['attributes']['content']);
    }

    public function test_a_revision_of_another_post_is_a_404(): void
    {
        [$idA, $versionA] = $this->createPost('a1');
        $this->updatePost($idA, $versionA, 'a2');
        [$idB] = $this->createPost('b1');
        $foreign = (int) Revision::query()->where('post_id', $idA)->value('id');

        $this->getJson("/editor/posts/{$idB}/revisions/{$foreign}")->assertNotFound();
    }
}

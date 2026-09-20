<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Editor;

use Heisenberg\Models\Post;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * PostLiveController::status() — the lightweight poll behind LIVE EDITOR UPDATES for
 * externally-authored content (see that controller's own docblock for the SSE-vs-polling
 * design rationale). Covers: the happy path a clean poll takes, the conditional-GET (ETag /
 * If-None-Match -> 304) path that keeps a repeat poll cheap, the version actually reflecting an
 * "external" write (simulated here via the same PostController::update() an MCP write would
 * ultimately also go through — both bump Post::content_version identically), the
 * degrade-safely cases (missing/trashed post -> 404, feature disabled via config -> a harmless
 * `{enabled:false}` body), and that the same PostPolicy::view gate PostController::show() uses
 * also protects this endpoint.
 *
 * Same local-dev authorization bypass posture as EditorSaveWiringTest/PostTrashControllerTest.
 */
class PostLiveControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'local';
        $this->withoutCsrfProtection();
    }

    private function makePost(): Post
    {
        // ->fresh() re-reads the row so content_version reflects the DB column default (0)
        // rather than the null Eloquent otherwise holds for an attribute never explicitly set.
        return Post::create(['title_en' => 'Live Refresh Fixture', 'status' => 'draft'])->fresh();
    }

    public function test_status_endpoint_returns_the_current_content_version_and_an_etag(): void
    {
        $post = $this->makePost();

        $response = $this->getJson("/editor/posts/{$post->id}/live-status");

        $response->assertOk();
        $response->assertJson(['content_version' => $post->content_version]);
        $this->assertNotNull($response->headers->get('ETag'));
    }

    public function test_a_repeat_poll_with_the_matching_etag_gets_a_bodyless_304(): void
    {
        $post = $this->makePost();

        $first = $this->getJson("/editor/posts/{$post->id}/live-status");
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->getJson("/editor/posts/{$post->id}/live-status", ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    public function test_an_external_write_bumps_the_version_the_next_poll_sees(): void
    {
        $post = $this->makePost();
        $before = $this->getJson("/editor/posts/{$post->id}/live-status")->json('content_version');

        // Simulate the write an MCP update_post call ultimately performs too — both paths funnel
        // through the same PostController::save() -> Post::bumpContentVersion().
        $this->putJson("/editor/posts/{$post->id}", [
            'schemaVersion' => 1,
            'registryHash' => app(BlockRegistryService::class)->computeHash(),
            'content_version' => $post->content_version,
            'blocks' => [],
            'title_en' => 'Changed Elsewhere',
        ])->assertOk();

        $after = $this->getJson("/editor/posts/{$post->id}/live-status")->json('content_version');

        $this->assertGreaterThan($before, $after);
    }

    public function test_status_endpoint_404s_for_a_nonexistent_post(): void
    {
        $this->getJson('/editor/posts/999999/live-status')->assertNotFound();
    }

    public function test_status_endpoint_404s_for_a_trashed_post_so_the_client_stops_polling(): void
    {
        $post = $this->makePost();
        $post->delete();

        $this->getJson("/editor/posts/{$post->id}/live-status")->assertNotFound();
    }

    public function test_disabling_the_feature_via_config_short_circuits_to_a_harmless_payload(): void
    {
        config(['heisenberg.editor.live_refresh.enabled' => false]);
        $post = $this->makePost();

        $response = $this->getJson("/editor/posts/{$post->id}/live-status");

        $response->assertOk();
        $response->assertJson(['enabled' => false]);
        $this->assertNull($response->json('content_version'));
    }

    public function test_status_endpoint_is_gated_by_the_same_view_policy_as_the_full_post_endpoint(): void
    {
        // A draft post is invisible to a plain guest under PostPolicy::view() once the local-dev
        // bypass is switched off — same policy PostController::show() itself is gated by.
        config(['heisenberg.allow_anonymous_in_local' => false]);
        $post = $this->makePost();

        $status = $this->getJson("/editor/posts/{$post->id}/live-status");
        $full = $this->getJson("/editor/posts/{$post->id}");

        $this->assertSame($full->getStatusCode(), $status->getStatusCode());
        $status->assertStatus(403);
    }
}

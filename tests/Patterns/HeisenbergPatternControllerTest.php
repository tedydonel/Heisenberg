<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Patterns;

use Heisenberg\Models\Pattern;
use Heisenberg\Services\BlockRegistryService;
use Heisenberg\Services\BlocksPayloadService;
use Heisenberg\Tests\Persistence\SkipsWhenMysqlUnreachable;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Acceptance tests for the saved-pattern API (routes/editor.php: GET/POST/DELETE
 * /editor/patterns) — HeisenbergPatternController + Pattern.
 *
 * Authorization posture, per the controller's own (current) docblock: every
 * action is gated on the `authors` tier (config `heisenberg.roles.authors` =
 * ['admin','editor','author']) via `denyUnlessAuthor()` — `RoleGate::is($actor,
 * 'authors')` wrapped in the SAME `LocalDevRoleGate` local-only anonymous
 * bypass every other editor write uses (SavedThemeController, ThemeController,
 * the media library — see MediaAuthorizationTest, which this suite's
 * guest/local-bypass tests mirror). A denied request gets a 403 JSON body
 * shaped `{"errors": [...]}`.
 *
 * Patterns remain install-wide with NO owner column (see Pattern's own
 * docblock) — the controller's docblock is explicit that this is deliberate:
 * "anyone who may author may curate the shared library." So once past the
 * authors-tier gate, any such actor may still save/delete any pattern,
 * including one a different authors-tier actor created — that is intended
 * shared-library behaviour, not an authorization gap (see
 * test_any_authors_tier_actor_may_curate_a_pattern_saved_by_another below).
 */
class HeisenbergPatternControllerTest extends TestCase
{
    use RefreshDatabase;
    use SkipsWhenMysqlUnreachable {
        SkipsWhenMysqlUnreachable::setUp as private skipIfMysqlUnreachable;
    }

    /** Mirrors HeisenbergPatternController::MAX_BLOCKS_BYTES (private to that class). */
    private const MAX_BLOCKS_BYTES = 512 * 1024;

    protected function setUp(): void
    {
        $this->skipIfMysqlUnreachable();

        $this->withoutCsrfProtection();
    }

    private function actingAsRole(int $id, string $role): FakeActor
    {
        $actor = new FakeActor($id, $role);
        $this->actingAs($actor);

        return $actor;
    }

    /** @return array<int, array<string, mixed>> */
    private function validBlocks(string $name = 'heisenberg/group'): array
    {
        return [[
            'id' => 'blk-1',
            'name' => $name,
            'schemaVersion' => 1,
            'attributes' => [],
            'supports' => [],
            'innerBlocks' => [],
        ]];
    }

    /**
     * A single-block `blocks` payload whose `json_encode()` byte length is
     * EXACTLY `$targetBytes` — built by padding an attribute with plain ASCII
     * (`x`, which needs no JSON escaping) so the padding length maps 1:1 onto
     * the encoded byte delta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocksOfEncodedSize(int $targetBytes): array
    {
        $blocks = [['id' => 'blk-1', 'name' => 'heisenberg/group', 'attributes' => ['note' => '']]];
        $baseline = strlen((string) json_encode($blocks));

        $needed = $targetBytes - $baseline;
        $this->assertGreaterThanOrEqual(0, $needed, 'target size smaller than the unpadded baseline');

        $blocks[0]['attributes']['note'] = str_repeat('x', $needed);

        $this->assertSame($targetBytes, strlen((string) json_encode($blocks)), 'padding helper must hit the exact target byte length');

        return $blocks;
    }

    private function assertIsAuthorizationDenial(TestResponse $response): void
    {
        $response->assertStatus(403)->assertJsonStructure(['errors']);
        $this->assertIsArray($response->json('errors'));
    }

    // ------------------------------------------------------------------
    // A true (unauthenticated) guest, outside `local`: denied everywhere.
    // ------------------------------------------------------------------

    public function test_a_true_guest_outside_local_is_denied_index(): void
    {
        $this->app['env'] = 'testing';

        $this->assertIsAuthorizationDenial($this->getJson('/editor/patterns'));
    }

    public function test_a_true_guest_outside_local_is_denied_store_and_nothing_is_written(): void
    {
        $this->app['env'] = 'testing';

        $this->assertIsAuthorizationDenial(
            $this->postJson('/editor/patterns', ['name' => 'Guest Pattern', 'blocks' => $this->validBlocks()])
        );

        $this->assertSame(0, Pattern::query()->count());
    }

    public function test_a_true_guest_outside_local_is_denied_destroy_and_nothing_is_deleted(): void
    {
        $this->app['env'] = 'testing';
        $pattern = Pattern::create(['name' => 'Untouchable', 'blocks' => $this->validBlocks()]);

        $this->assertIsAuthorizationDenial(
            $this->deleteJson('/editor/patterns', ['id' => $pattern->id])
        );

        $this->assertNotNull(Pattern::find($pattern->id));
    }

    // ------------------------------------------------------------------
    // Local-dev anonymous bypass (LocalDevRoleGate) — same posture as
    // SavedThemeController/ThemeController/the media library.
    // ------------------------------------------------------------------

    public function test_a_guest_in_local_env_with_the_bypass_enabled_is_allowed(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => true]);

        $this->getJson('/editor/patterns')->assertOk();

        $store = $this->postJson('/editor/patterns', ['name' => 'Local Guest Pattern', 'blocks' => $this->validBlocks()]);
        $store->assertOk()->assertJson(['saved' => true]);
        $id = $store->json('pattern.id');

        $this->deleteJson('/editor/patterns', ['id' => $id])->assertOk()->assertJson(['deleted' => true]);
        $this->assertNull(Pattern::find($id));
    }

    public function test_a_guest_in_local_env_with_the_bypass_disabled_is_denied(): void
    {
        $this->app['env'] = 'local';
        config(['heisenberg.allow_anonymous_in_local' => false]);

        $this->assertIsAuthorizationDenial($this->getJson('/editor/patterns'));

        $this->assertIsAuthorizationDenial(
            $this->postJson('/editor/patterns', ['name' => 'Nope', 'blocks' => $this->validBlocks()])
        );
        $this->assertSame(0, Pattern::query()->count());

        $pattern = Pattern::create(['name' => 'Still Untouchable', 'blocks' => $this->validBlocks()]);
        $this->assertIsAuthorizationDenial($this->deleteJson('/editor/patterns', ['id' => $pattern->id]));
        $this->assertNotNull(Pattern::find($pattern->id));
    }

    // ------------------------------------------------------------------
    // Role matrix: only the `authors` tier (admin/editor/author) may use any
    // of the three actions; everyone else (including a bare "viewer" and an
    // actor with no configured role at all) is denied all three.
    // ------------------------------------------------------------------

    /** @return iterable<string, array{0: string}> */
    public static function authorsTierRoleProvider(): iterable
    {
        yield 'admin' => ['admin'];
        yield 'editor' => ['editor'];
        yield 'author' => ['author'];
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonAuthorsTierRoleProvider(): iterable
    {
        yield 'viewer' => ['viewer'];
        yield 'no configured role' => [''];
    }

    #[DataProvider('authorsTierRoleProvider')]
    public function test_authors_tier_roles_can_index_store_and_destroy(string $role): void
    {
        $this->app['env'] = 'testing';
        $this->actingAsRole(1, $role);

        $this->getJson('/editor/patterns')->assertOk();

        $store = $this->postJson('/editor/patterns', ['name' => "Pattern for {$role}", 'blocks' => $this->validBlocks()]);
        $store->assertOk()->assertJson(['saved' => true]);
        $id = $store->json('pattern.id');

        $this->deleteJson('/editor/patterns', ['id' => $id])->assertOk()->assertJson(['deleted' => true]);
        $this->assertNull(Pattern::find($id));
    }

    #[DataProvider('nonAuthorsTierRoleProvider')]
    public function test_non_authors_tier_roles_are_denied_index_store_and_destroy(string $role): void
    {
        $this->app['env'] = 'testing';
        $this->actingAsRole(1, $role);

        $this->assertIsAuthorizationDenial($this->getJson('/editor/patterns'));

        $this->assertIsAuthorizationDenial(
            $this->postJson('/editor/patterns', ['name' => 'Nope', 'blocks' => $this->validBlocks()])
        );
        $this->assertSame(0, Pattern::query()->count());

        $pattern = Pattern::create(['name' => 'Protected', 'blocks' => $this->validBlocks()]);
        $this->assertIsAuthorizationDenial($this->deleteJson('/editor/patterns', ['id' => $pattern->id]));
        $this->assertNotNull(Pattern::find($pattern->id));
    }

    /**
     * Patterns are a shared, install-wide library (no owner column) — the
     * controller's own docblock says any authors-tier actor may curate any
     * pattern. This pins that as intended behaviour: a DIFFERENT authors-tier
     * actor than the one who saved it may still delete it.
     */
    public function test_any_authors_tier_actor_may_curate_a_pattern_saved_by_another(): void
    {
        $this->app['env'] = 'testing';

        $this->actingAsRole(1, 'author');
        $store = $this->postJson('/editor/patterns', ['name' => 'Shared Pattern', 'blocks' => $this->validBlocks()]);
        $store->assertOk();
        $id = $store->json('pattern.id');

        $this->actingAsRole(2, 'editor');
        $this->deleteJson('/editor/patterns', ['id' => $id])->assertOk()->assertJson(['deleted' => true]);

        $this->assertNull(Pattern::find($id));
    }

    // ------------------------------------------------------------------
    // CRUD happy path (authenticated authors-tier actor)
    // ------------------------------------------------------------------

    public function test_store_then_index_then_destroy_round_trips(): void
    {
        $this->actingAsRole(1, 'author');

        $store = $this->postJson('/editor/patterns', ['name' => 'My Pattern', 'blocks' => $this->validBlocks()]);
        $store->assertOk();
        $this->assertSame('My Pattern', $store->json('pattern.name'));
        $id = $store->json('pattern.id');

        $index = $this->getJson('/editor/patterns')->assertOk();
        $this->assertCount(1, $index->json('patterns'));
        $this->assertSame('My Pattern', $index->json('patterns.0.name'));

        $this->deleteJson('/editor/patterns', ['id' => $id])->assertOk()->assertJson(['deleted' => true, 'id' => $id]);
        $this->assertCount(0, $this->getJson('/editor/patterns')->json('patterns'));
    }

    public function test_index_is_ordered_by_name(): void
    {
        $this->actingAsRole(1, 'author');
        Pattern::create(['name' => 'Zebra', 'blocks' => $this->validBlocks()]);
        Pattern::create(['name' => 'Apple', 'blocks' => $this->validBlocks()]);

        $names = $this->getJson('/editor/patterns')->assertOk()->json('patterns.*.name');

        $this->assertSame(['Apple', 'Zebra'], $names);
    }

    // ------------------------------------------------------------------
    // Validation failures (authenticated authors-tier actor)
    // ------------------------------------------------------------------

    public function test_store_rejects_a_missing_name(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['blocks' => $this->validBlocks()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(0, Pattern::query()->count());
    }

    public function test_store_rejects_a_whitespace_only_name(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => '   ', 'blocks' => $this->validBlocks()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_a_name_over_120_characters(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => str_repeat('a', 121), 'blocks' => $this->validBlocks()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_accepts_a_name_at_exactly_120_characters(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => str_repeat('a', 120), 'blocks' => $this->validBlocks()])
            ->assertOk();
    }

    public function test_store_rejects_a_duplicate_name(): void
    {
        $this->actingAsRole(1, 'author');
        Pattern::create(['name' => 'Taken', 'blocks' => $this->validBlocks()]);

        $this->postJson('/editor/patterns', ['name' => 'Taken', 'blocks' => $this->validBlocks()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Pattern::query()->count());
    }

    public function test_store_rejects_a_non_array_blocks_payload(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_an_empty_blocks_array(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_a_missing_blocks_key_entirely(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_a_block_entry_without_a_name(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => [['id' => 'x']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_a_block_entry_with_an_empty_string_name(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => [['name' => '']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_a_block_entry_with_a_non_string_name(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => [['name' => 42]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_store_rejects_a_non_object_block_entry(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', ['name' => 'Bad', 'blocks' => ['not-an-object']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);
    }

    public function test_destroy_rejects_a_missing_id(): void
    {
        $this->actingAsRole(1, 'author');

        $this->deleteJson('/editor/patterns', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    public function test_destroy_rejects_an_unknown_id(): void
    {
        $this->actingAsRole(1, 'author');

        $this->deleteJson('/editor/patterns', ['id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    // ------------------------------------------------------------------
    // Blocks payload size cap (HeisenbergPatternController::MAX_BLOCKS_BYTES)
    // ------------------------------------------------------------------

    public function test_store_accepts_a_blocks_payload_just_under_the_size_cap(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', [
            'name' => 'Just Under Cap',
            'blocks' => $this->blocksOfEncodedSize(self::MAX_BLOCKS_BYTES - 1),
        ])->assertOk();

        $this->assertSame(1, Pattern::query()->count());
    }

    public function test_store_rejects_a_blocks_payload_over_the_size_cap(): void
    {
        $this->actingAsRole(1, 'author');

        $this->postJson('/editor/patterns', [
            'name' => 'Over Cap',
            'blocks' => $this->blocksOfEncodedSize(self::MAX_BLOCKS_BYTES + 1),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks']);

        $this->assertSame(0, Pattern::query()->count());
    }

    // ------------------------------------------------------------------
    // Does a saved pattern's `blocks` payload go through the same
    // BlocksPayloadService::validatePayload() gate a post save does?
    // ------------------------------------------------------------------

    /**
     * The controller's own docblock says this plainly ("every real validation …
     * is the runtime's job on insert — this controller never has to know what
     * makes a valid model"), and this proves it empirically: a block name that
     * does not exist in the live block registry at all is happily persisted
     * for an authors-tier actor (the authorization gate is orthogonal to this
     * shape-only validation).
     */
    public function test_store_accepts_a_block_name_that_does_not_exist_in_the_live_registry(): void
    {
        $this->actingAsRole(1, 'author');

        $response = $this->postJson('/editor/patterns', [
            'name' => 'Bogus Block Pattern',
            'blocks' => $this->validBlocks('definitely/not-a-real-registered-block'),
        ]);

        $response->assertOk()->assertJson(['saved' => true]);
        $this->assertSame(
            'definitely/not-a-real-registered-block',
            Pattern::query()->first()->blocks[0]['name']
        );
    }

    /**
     * ...but the SAME block content, fed through the real
     * BlocksPayloadService::validatePayload() gate that SavePostRequest runs on
     * every post save, is rejected. This is the gate a pattern's blocks WOULD
     * hit the next time the containing post (the one the pattern gets inserted
     * into, client-side, via hbEditor.insertPattern) is saved — so an invalid
     * pattern cannot silently make it into a rendered document; it is caught
     * one save later than a raw post body would be, not never.
     */
    public function test_the_same_bogus_block_would_be_rejected_by_the_post_save_validator(): void
    {
        $registry = $this->app->make(BlockRegistryService::class);
        $service = new BlocksPayloadService($registry);

        $envelope = [
            'schemaVersion' => 1,
            'registryHash' => $registry->computeHash(),
            'blocks' => $this->validBlocks('definitely/not-a-real-registered-block'),
        ];

        $result = $service->validatePayload($envelope);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('Unknown block name', implode(' | ', $result['errors']));
    }
}

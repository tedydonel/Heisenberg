<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Ai\ReasoningFilter;
use Heisenberg\Services\HeisenbergToolSource;
use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Tests\TestCase;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * What the editor's assistant can see and say.
 *
 * Two defects this pins down, both observed in the panel:
 *
 * 1. The assistant replied "paste the current shortcode so I can see the
 *    structure" — it had no view of the document it was being asked to edit.
 * 2. `<think>…</think>` was rendered verbatim in the response card. That tag
 *    comes from a serving template, not the model's choice, so no amount of
 *    prompting removes it; it has to be filtered at the boundary.
 */
class EditorAssistantTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-assistant-' . uniqid('', true) . '.json';
        config(['heisenberg.ai.settings_path' => $this->path, 'cache.default' => 'array']);

        putenv('HB_TEST_ASSISTANT=sk-test');
        $_ENV['HB_TEST_ASSISTANT'] = 'sk-test';
        $_SERVER['HB_TEST_ASSISTANT'] = 'sk-test';

        (new \Heisenberg\Services\AiSettingsRepository($this->path))->save([
            'providers' => [[
                'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic',
                'base_url' => 'https://api.anthropic.com', 'key_env' => 'HB_TEST_ASSISTANT',
            ]],
            'models' => [['id' => 'claude-opus-5', 'provider' => 'anthropic', 'enabled' => true, 'effort' => 'high']],
            'active_model' => 'anthropic:claude-opus-5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('HB_TEST_ASSISTANT');
        unset($_ENV['HB_TEST_ASSISTANT'], $_SERVER['HB_TEST_ASSISTANT']);
        parent::tearDown();
    }

    private function fakeReply(string $text): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ])]);
    }

    // ── reasoning tags ──────────────────────────────────────────────────────

    public function test_a_closed_think_block_is_stripped(): void
    {
        $this->assertSame(
            'Here is the heading.',
            ReasoningFilter::strip("<think>The user wants a heading. I should ask…</think>\nHere is the heading."),
        );
    }

    /** Mid-stream the thought is still being written; half of one is worse than none. */
    public function test_an_unclosed_think_block_swallows_the_remainder(): void
    {
        $this->assertSame('Answer.', ReasoningFilter::strip('Answer.<think>still thinking about'));
    }

    /** Some gateways trim the opener and emit only the closing tag. */
    public function test_a_stray_closing_tag_drops_everything_before_it(): void
    {
        $this->assertSame('The answer.', ReasoningFilter::strip('rambling reasoning</think>The answer.'));
    }

    public function test_ordinary_html_in_a_reply_survives(): void
    {
        $body = '[p]Body with <strong>bold</strong> text.[/p]';

        $this->assertSame($body, ReasoningFilter::strip($body));
    }

    public function test_the_completion_endpoint_never_returns_reasoning_tags(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply("<think>Let me consider the options…</think>[h2]Hello[/h2]");

        $this->postJson('/editor/ai/complete', ['prompt' => 'write a heading'])
            ->assertOk()
            ->assertJsonPath('text', '[h2]Hello[/h2]');
    }

    // ── document context ────────────────────────────────────────────────────

    /**
     * The document is sent on every turn, so the assistant never has to ask for
     * it — which is exactly what it did before.
     */
    public function test_the_current_document_is_sent_with_every_prompt(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'change the title',
            'context' => ['document' => "[h2]\n  Old title\n[/h2]\n", 'title' => 'My post'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $user = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString('Old title', $user);
            $this->assertStringContainsString('My post', $user);

            return true;
        });
    }

    public function test_an_empty_page_is_stated_rather_than_left_unsaid(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', ['prompt' => 'write an intro'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->data()['messages'][0]['content'], 'currently empty'));
    }

    public function test_the_system_prompt_forbids_asking_for_the_document(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['system'];

            $this->assertStringContainsString('Never ask the user to paste', $system);
            $this->assertStringContainsString('Never write <think>', $system);

            return true;
        });
    }

    // ── conversation memory ─────────────────────────────────────────────────

    /**
     * Continuing a conversation must restore its context to the MODEL, not just
     * the on-screen transcript. Prior turns ride along as real message history,
     * ahead of the current turn — and only the current turn carries the live
     * document, so reopening a thread doesn't replay stale snapshots.
     */
    public function test_prior_turns_are_replayed_to_the_model_before_the_current_one(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'now make it punchier',
            'history' => [
                ['role' => 'user', 'content' => 'write an intro about tea'],
                ['role' => 'assistant', 'content' => '[p]Tea is lovely.[/p]'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            $this->assertSame('user', $messages[0]['role']);
            $this->assertStringContainsString('write an intro about tea', $messages[0]['content']);
            $this->assertSame('assistant', $messages[1]['role']);
            $this->assertStringContainsString('Tea is lovely', $messages[1]['content']);
            // The live turn is last and is the one carrying the new prompt.
            $this->assertStringContainsString('now make it punchier', end($messages)['content']);

            return true;
        });
    }

    /** Tool rounds are transport, not transcript — a `tool` role is never replayed. */
    public function test_only_user_and_assistant_history_turns_are_replayed(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'continue',
            'history' => [
                ['role' => 'user', 'content' => 'keep'],
                ['role' => 'tool', 'content' => 'DROP THIS tool payload'],
                ['role' => 'assistant', 'content' => 'kept'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $roles = array_column($request->data()['messages'], 'role');

            $this->assertNotContains('tool', $roles);
            $this->assertStringNotContainsString('DROP THIS', json_encode($request->data()['messages']));

            return true;
        });
    }

    // ── conversation-aware suggestions ───────────────────────────────────────

    public function test_suggest_returns_the_models_json_array(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('["Add a call to action", "Shorten the intro", "Suggest a title"]');

        $response = $this->postJson('/editor/ai/suggest', [
            'history' => [['role' => 'user', 'content' => 'write an intro']],
            'locale' => 'en',
        ])->assertOk();

        $this->assertSame(
            ['Add a call to action', 'Shorten the intro', 'Suggest a title'],
            $response->json('suggestions'),
        );
    }

    /** No conversation, no suggestions — and no wasted provider call. */
    public function test_suggest_short_circuits_on_empty_history(): void
    {
        $this->app['env'] = 'local';
        Http::fake();

        $this->postJson('/editor/ai/suggest', ['history' => []])
            ->assertOk()->assertExactJson(['suggestions' => []]);

        Http::assertNothingSent();
    }

    /** A malformed reply yields no chips rather than an error. */
    public function test_suggest_is_best_effort_on_a_bad_reply(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('I could not think of any, sorry!');

        $this->postJson('/editor/ai/suggest', [
            'history' => [['role' => 'user', 'content' => 'hi']],
        ])->assertOk()->assertExactJson(['suggestions' => []]);
    }

    // ── platform tools ──────────────────────────────────────────────────────

    /**
     * The assistant inside the editor was the one client that could not reach
     * Heisenberg's own MCP tools. It now gets them in-process — no server, no
     * token, no socket.
     */
    public function test_the_assistant_is_offered_heisenbergs_own_tools(): void
    {
        $this->app['env'] = 'local';
        $this->fakeReply('ok');

        $this->postJson('/editor/ai/complete', ['prompt' => 'what blocks exist?'])->assertOk();

        Http::assertSent(function ($request) {
            $names = array_column($request->data()['tools'] ?? [], 'name');

            $this->assertContains('heisenberg__list_blocks', $names);
            $this->assertContains('heisenberg__describe_block', $names);
            $this->assertContains('heisenberg__render_preview', $names);

            return true;
        });
    }

    /**
     * The editor surface has ONE write path: write_canvas, straight into the
     * page in front of the user. The DB content-write tools (create_post,
     * insert_blocks and the rest) belong to the external MCP server — offering
     * them here is what made models "insert" content into the database while
     * the canvas stayed empty.
     */
    public function test_the_editor_surface_swaps_db_write_tools_for_the_live_canvas(): void
    {
        $source = new HeisenbergToolSource(app(McpToolRegistry::class));
        $names = array_column($source->tools(), 'name');

        $this->assertContains('heisenberg__write_canvas', $names);
        foreach ([
            'create_post', 'update_post', 'insert_blocks', 'update_block',
            'remove_block', 'move_block', 'duplicate_block',
        ] as $tool) {
            $this->assertNotContains(
                'heisenberg__' . $tool,
                $names,
                "the editor assistant must not be offered '{$tool}' — the canvas is its write path"
            );
        }

        // Hidden AND refused — hiding alone would be security by obscurity.
        $result = $source->call('heisenberg__create_post', ['title' => 'Draft', 'code' => '[p]x[/p]']);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not available on this surface', $result['content']);
    }

    /** The title tool: validated here, applied to the editor's title field by the panel. */
    public function test_set_page_title_is_offered_and_validates(): void
    {
        $source = new HeisenbergToolSource(app(McpToolRegistry::class));
        $names = array_column($source->tools(), 'name');
        $this->assertContains('heisenberg__set_page_title', $names);

        $ok = $source->call('heisenberg__set_page_title', ['title' => 'The Long Swim Home']);
        $this->assertFalse($ok['isError']);
        $this->assertStringContainsString('The Long Swim Home', $ok['content']);

        $empty = $source->call('heisenberg__set_page_title', ['title' => '   ']);
        $this->assertTrue($empty['isError']);
    }

    /** write_canvas validates through the live contracts and reports line-numbered errors. */
    public function test_write_canvas_validates_and_counts_blocks(): void
    {
        $source = new HeisenbergToolSource(app(McpToolRegistry::class));

        $ok = $source->call('heisenberg__write_canvas', ['code' => '[h2]Hi[/h2][p]Body[/p]']);
        $this->assertFalse($ok['isError']);
        $this->assertStringContainsString('"applied": true', $ok['content']);
        $this->assertStringContainsString('"blocks": 2', $ok['content']);

        $bad = $source->call('heisenberg__write_canvas', ['code' => '[not-a-block]x[/not-a-block]']);
        $this->assertTrue($bad['isError']);
        $this->assertStringContainsString('line 1', $bad['content']);

        $badMode = $source->call('heisenberg__write_canvas', ['code' => '[p]x[/p]', 'mode' => 'prepend']);
        $this->assertTrue($badMode['isError']);
        $this->assertStringContainsString('append', $badMode['content']);
    }

    /**
     * write_canvas cannot see the editor's current document (it lives in the browser, possibly
     * unsaved), so it cannot itself compare a translated call's structure against what's already
     * on the canvas — that fold/refuse rule is enforced client-side (block-runtime.blade.php's
     * foldTranslation, mirroring McpToolRegistry::foldTranslatedBlocks()). The tool's DESCRIPTION
     * still states the rule, since a model that skims tool descriptions rather than the system
     * prompt (EditorPrompt::locales()) needs to hit it there too.
     */
    public function test_write_canvas_description_teaches_the_translating_rule(): void
    {
        $registry = app(McpToolRegistry::class);
        $tool = collect($registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR))
            ->firstWhere('name', 'write_canvas');

        $this->assertNotNull($tool);
        $this->assertStringContainsString('TRANSLATING', $tool['description']);
        $this->assertStringContainsString('SAME block sequence', $tool['description']);
        $this->assertStringContainsString('mode="append" is refused while translating', $tool['description']);
    }

    public function test_a_local_tool_runs_in_process_without_any_mcp_server(): void
    {
        $source = new HeisenbergToolSource(app(McpToolRegistry::class));

        $result = $source->call('heisenberg__list_blocks', []);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('paragraph', $result['content']);
    }

    public function test_using_the_assistant_still_requires_the_authors_tier(): void
    {
        $this->app['env'] = 'testing';
        Http::fake();

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_an_authors_tier_actor_can_use_it(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'author'));
        $this->fakeReply('Done.');

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])
            ->assertOk()
            ->assertJsonPath('text', 'Done.');
    }
}

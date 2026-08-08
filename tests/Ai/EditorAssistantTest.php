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

            $this->assertStringContainsString('Never ask them to paste', $system);
            $this->assertStringContainsString('Never write <think>', $system);

            return true;
        });
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

    /** Authors tier: the assistant may draft, but publishing stays a human act. */
    public function test_the_assistant_cannot_reach_tools_above_its_tier(): void
    {
        $source = new HeisenbergToolSource(app(McpToolRegistry::class));
        $names = array_column($source->tools(), 'name');

        $this->assertContains('heisenberg__create_post', $names);
        $result = $source->call('heisenberg__create_post', ['title' => 'Draft', 'code' => '[p]x[/p]', 'status' => 'published']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not permitted', $result['content']);
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
        $this->actingAs(new FakeActor(1, 'employee_l1'));
        $this->fakeReply('Done.');

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])
            ->assertOk()
            ->assertJsonPath('text', 'Done.');
    }
}

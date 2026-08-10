<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Tests\TestCase;
use Heisenberg\Tests\Taxonomy\FakeActor;
use Illuminate\Support\Facades\Http;

/**
 * The two model-calling endpoints (POST /editor/ai/complete and /stream).
 *
 * Authorization here is `authors`, not `admins`: using the assistant is an
 * authoring act. It is emphatically not open, though — every call spends the
 * operator's API budget, so an anonymous visitor must not be able to make them.
 */
class AiCompletionEndpointTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-complete-' . uniqid('', true) . '.json';
        config([
            'heisenberg.ai.settings_path' => $this->path,
            // Testbench defaults to the `database` cache driver with no `cache`
            // table, which the throttle middleware would hit before the route.
            'cache.default' => 'array',
        ]);

        // One provider with one model in use — the shape the assistant reads:
        // the model names its provider, the provider names the API format.
        putenv('HB_TEST_ANTHROPIC=sk-ant-endpoint');
        $_ENV['HB_TEST_ANTHROPIC'] = 'sk-ant-endpoint';
        $_SERVER['HB_TEST_ANTHROPIC'] = 'sk-ant-endpoint';

        (new \Heisenberg\Services\AiSettingsRepository($this->path))->save([
            'providers' => [[
                'id' => 'anthropic', 'label' => 'Anthropic', 'format' => 'anthropic',
                'base_url' => 'https://api.anthropic.com', 'key_env' => 'HB_TEST_ANTHROPIC',
            ]],
            'models' => [['id' => 'claude-opus-5', 'provider' => 'anthropic', 'enabled' => true, 'effort' => 'high']],
            'active_model' => 'anthropic:claude-opus-5',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('HB_TEST_ANTHROPIC');
        unset($_ENV['HB_TEST_ANTHROPIC'], $_SERVER['HB_TEST_ANTHROPIC']);
        parent::tearDown();
    }

    private function fakeModel(string $text = 'Generated copy'): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $text]],
        ])]);
    }

    public function test_a_guest_in_a_non_local_environment_cannot_spend_the_api_budget(): void
    {
        $this->app['env'] = 'testing';

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_an_authors_tier_actor_may_use_the_assistant(): void
    {
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'employee_l1'));
        $this->fakeModel();

        $this->postJson('/editor/ai/complete', ['prompt' => 'Write an intro'])
            ->assertOk()
            ->assertJsonPath('text', 'Generated copy');
    }

    public function test_an_empty_prompt_is_rejected_before_any_provider_call(): void
    {
        $this->app['env'] = 'local';
        Http::fake();

        $this->postJson('/editor/ai/complete', ['prompt' => '   '])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_provider_error_surfaces_as_502_rather_than_a_500(): void
    {
        $this->app['env'] = 'local';
        Http::fake(['*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])
            ->assertStatus(502)
            ->assertJsonPath('text', '');
    }

    /**
     * The provider-error case above is an ordinary AiResponse::error() value —
     * this covers the other failure mode, an actual \Throwable escaping the
     * tool loop, which used to fall straight through to a raw 500.
     */
    public function test_an_unexpected_exception_surfaces_as_502_rather_than_a_500(): void
    {
        $this->app['env'] = 'local';

        $this->app->bind(\Heisenberg\Services\AiToolRunner::class, function () {
            return new class () extends \Heisenberg\Services\AiToolRunner {
                public function __construct()
                {
                }

                public function run(
                    \Heisenberg\Contracts\AiProvider $provider,
                    \Heisenberg\Ai\AiRequest $request,
                    ?array $byName = null,
                    ?array $tools = null,
                ): \Heisenberg\Ai\AiResponse {
                    throw new \RuntimeException('tool loop blew up');
                }
            };
        });

        $response = $this->postJson('/editor/ai/complete', ['prompt' => 'hi']);

        $response->assertStatus(502)->assertJsonPath('text', '');
        $this->assertNotNull($response->json('error'));
    }

    public function test_the_prompt_carries_the_selected_blocks_content_as_context(): void
    {
        $this->app['env'] = 'local';
        $this->fakeModel();

        $this->postJson('/editor/ai/complete', [
            'prompt' => 'Improve this',
            'context' => ['selection' => 'The quick brown fox.', 'blockName' => 'heisenberg/paragraph'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $user = $request->data()['messages'][0]['content'];

            $this->assertStringContainsString('Improve this', $user);
            $this->assertStringContainsString('The quick brown fox.', $user);

            return true;
        });
    }

    /**
     * The system prompt is generated from the live registry, so a contract added
     * tomorrow is offered to the model tomorrow.
     */
    public function test_the_system_prompt_teaches_the_shortcode_dialect_from_the_live_registry(): void
    {
        $this->app['env'] = 'local';
        $this->fakeModel();

        $this->postJson('/editor/ai/complete', ['prompt' => 'hi'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['system'];

            $this->assertStringContainsString('shortcode', $system);
            $this->assertStringContainsString('heading', $system);
            $this->assertStringContainsString('paragraph', $system);
            // Block-level output must be the dialect, not raw HTML — anything
            // else would bypass the block contracts entirely.
            $this->assertStringContainsString('Block-level HTML may not', $system);

            return true;
        });
    }

    public function test_the_stream_endpoint_emits_server_sent_events(): void
    {
        $this->app['env'] = 'local';
        Http::fake(['*' => Http::response(
            'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hi"}}' . "\n\n"
            . 'data: {"type":"message_stop"}' . "\n\n"
        )]);

        $response = $this->post('/editor/ai/stream', ['prompt' => 'hi']);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('"type":"text_delta"', $body);
        $this->assertStringContainsString('"text":"Hi"', $body);
        $this->assertStringContainsString('"type":"done"', $body);
    }

    /**
     * A streamed turn runs the same tool loop as a completion. Without it the
     * assistant silently lost every platform tool as soon as streaming was on.
     */
    public function test_a_streamed_turn_offers_the_platform_tools(): void
    {
        $this->app['env'] = 'local';
        Http::fake(['*' => Http::response('data: {"type":"message_stop"}' . "\n\n")]);

        $this->post('/editor/ai/stream', ['prompt' => 'hi'])->streamedContent();

        Http::assertSent(function ($request) {
            $names = array_column((array) ($request->data()['tools'] ?? []), 'name');

            $this->assertNotSame([], $names);
            $this->assertContains('heisenberg__list_blocks', $names);

            return true;
        });
    }

    /**
     * Every stream ends with an explicit terminator, even when the provider never
     * sent one — it is how the panel tells "the model finished" from "the
     * connection dropped", and the difference is a reply that looks cut off.
     */
    public function test_a_stream_always_ends_with_a_terminator(): void
    {
        $this->app['env'] = 'local';
        // A body with no frames at all: the upstream closed early.
        Http::fake(['*' => Http::response('')]);

        $body = $this->post('/editor/ai/stream', ['prompt' => 'hi'])->assertOk()->streamedContent();

        $this->assertStringContainsString('"type":"done"', $body);
    }

    /**
     * The stream must not 403 at the HTTP layer — an EventSource-style consumer
     * reads the body, so the refusal has to arrive as an error frame it can show.
     */
    public function test_an_unauthorized_stream_reports_the_denial_inside_the_stream(): void
    {
        $this->app['env'] = 'testing';
        Http::fake();

        $body = $this->post('/editor/ai/stream', ['prompt' => 'hi'])->assertOk()->streamedContent();

        $this->assertStringContainsString('"type":"error"', $body);
        Http::assertNothingSent();
    }
}

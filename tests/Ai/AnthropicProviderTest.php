<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Adapters\AnthropicProvider;
use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * AnthropicProvider, against a faked HTTP client.
 *
 * The request-shape assertions are the point. `temperature`, `top_p`, `top_k`
 * and `budget_tokens` are all rejected with a 400 by current models, and each
 * one is a plausible thing for a future edit to reintroduce — so each gets an
 * explicit "must not be present" test rather than being covered by inspection.
 */
class AnthropicProviderTest extends TestCase
{
    /**
     * The registry resolves the key (environment first, then the credential
     * store) and hands the adapter the result — an adapter never decides where a
     * credential comes from, so these tests inject one directly.
     */
    private function provider(array $overrides = []): AnthropicProvider
    {
        return new AnthropicProvider(array_merge([
            'id' => 'anthropic',
            'label' => 'Anthropic',
            'key' => 'sk-ant-test',
            'base_url' => 'https://api.anthropic.com',
        ], $overrides));
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            messages: [AiMessage::user('Write an intro')],
            system: 'You are the editor assistant.',
            model: 'claude-opus-5',
            effort: 'xhigh',
            maxTokens: 2048,
        );
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Hello world']],
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
        ])]);
    }

    public function test_a_completion_returns_the_models_text(): void
    {
        $this->fakeOk();

        $response = $this->provider()->complete($this->request());

        $this->assertFalse($response->isError());
        $this->assertSame('Hello world', $response->text);
        $this->assertSame('claude-opus-5', $response->model);
        $this->assertSame(4, $response->usage['output_tokens']);
    }

    public function test_the_request_carries_adaptive_thinking_and_effort(): void
    {
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame(['type' => 'adaptive'], $body['thinking']);
            $this->assertSame(['effort' => 'xhigh'], $body['output_config']);
            $this->assertSame('claude-opus-5', $body['model']);
            $this->assertSame(2048, $body['max_tokens']);

            return true;
        });
    }

    /**
     * The system prompt is a top-level field on this API, not a message — the
     * mirror image of the OpenAI-compatible adapter, and the reason AiMessage
     * has no system role.
     */
    public function test_the_system_prompt_is_a_top_level_field_not_a_message(): void
    {
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('You are the editor assistant.', $body['system']);
            $this->assertSame([['role' => 'user', 'content' => 'Write an intro']], $body['messages']);

            return true;
        });
    }

    public function test_the_request_never_carries_a_parameter_current_models_reject(): void
    {
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            foreach (['temperature', 'top_p', 'top_k', 'budget_tokens'] as $banned) {
                $this->assertArrayNotHasKey($banned, $request->data(), "{$banned} is rejected with a 400 by current models");
            }
            // `budget_tokens` could also hide inside the thinking block.
            $this->assertArrayNotHasKey('budget_tokens', $request->data()['thinking']);

            return true;
        });
    }

    public function test_the_api_key_travels_in_the_header_not_the_body(): void
    {
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            $this->assertSame('sk-ant-test', $request->header('x-api-key')[0]);
            $this->assertSame('2023-06-01', $request->header('anthropic-version')[0]);
            $this->assertStringNotContainsString('sk-ant-test', $request->body());

            return true;
        });
    }

    /**
     * A declined request is an HTTP 200 carrying stop_reason "refusal", so code
     * that reads content first sees an empty array rather than an error.
     */
    public function test_a_refusal_is_reported_even_though_the_http_status_is_200(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'claude-opus-5',
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.'],
            'content' => [],
        ], 200)]);

        $response = $this->provider()->complete($this->request());

        $this->assertTrue($response->isError());
        $this->assertSame('refusal', $response->stopReason);
        $this->assertSame('Declined.', $response->error);
    }

    public function test_an_upstream_error_body_is_never_echoed_to_the_caller(): void
    {
        // A 401 body can quote the credential it rejected.
        Http::fake(['*' => Http::response([
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key: sk-ant-test'],
        ], 401)]);

        $response = $this->provider()->complete($this->request());

        $this->assertTrue($response->isError());
        $this->assertStringNotContainsString('sk-ant-test', (string) $response->error);
        $this->assertStringContainsString('authentication_error', (string) $response->error);
    }

    public function test_a_missing_key_is_an_error_value_not_an_exception(): void
    {
        $provider = $this->provider(['key' => null]);

        $this->assertFalse($provider->isConfigured());
        $this->assertTrue($provider->complete($this->request())->isError());
    }

    public function test_tool_definitions_are_rendered_in_anthropics_shape(): void
    {
        $this->fakeOk();

        $this->provider()->complete($this->request()->withTools([
            ['name' => 'list_issues', 'description' => 'List issues', 'input_schema' => ['type' => 'object']],
        ]));

        Http::assertSent(function ($request) {
            $tool = $request->data()['tools'][0];

            $this->assertSame('list_issues', $tool['name']);
            $this->assertSame(['type' => 'object'], $tool['input_schema']);

            return true;
        });
    }

    public function test_streaming_yields_normalised_text_deltas_then_done(): void
    {
        // Chunked exactly the way a real stream splits: mid-frame.
        Http::fake(['*' => Http::response(
            "event: content_block_delta\n"
            . 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hel"}}' . "\n\n"
            . "event: content_block_delta\n"
            . 'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"lo"}}' . "\n\n"
            . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n"
            . 'data: {"type":"message_stop"}' . "\n\n"
        )]);

        $events = iterator_to_array($this->provider()->stream($this->request()));
        $types = array_map(static fn (AiStreamEvent $e): string => $e->type, $events);

        $this->assertSame(
            [AiStreamEvent::TEXT_DELTA, AiStreamEvent::TEXT_DELTA, AiStreamEvent::DONE],
            $types,
        );
        $this->assertSame('Hello', $events[0]->text . $events[1]->text);
        $this->assertSame('end_turn', $events[2]->data['stopReason']);
    }

    public function test_streaming_sets_the_stream_flag_on_the_request(): void
    {
        Http::fake(['*' => Http::response('data: {"type":"message_stop"}' . "\n\n")]);

        iterator_to_array($this->provider()->stream($this->request()));

        Http::assertSent(fn ($request) => $request->data()['stream'] === true);
    }
}

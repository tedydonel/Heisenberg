<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Adapters\OpenAiCompatibleProvider;
use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * OpenAiCompatibleProvider — one adapter for OpenAI, Groq, Ollama, OpenRouter
 * and anything else speaking /chat/completions.
 *
 * The behaviours worth pinning are the ones that differ from Anthropic and are
 * therefore easy to get backwards: the system prompt is a MESSAGE here, tool
 * arguments arrive as a JSON string rather than an object, the terminator is the
 * literal `[DONE]`, and a localhost endpoint legitimately needs no key at all.
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    private string $key = '';

    private function setKey(string $value): void
    {
        $this->key = $value;
    }

    /**
     * The registry resolves the key and hands the adapter the result — an
     * adapter never decides where a credential comes from.
     */
    private function provider(array $overrides = []): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(array_merge([
            'id' => 'openai',
            'label' => 'OpenAI-compatible',
            'key' => $this->key !== '' ? $this->key : null,
            'base_url' => 'https://api.openai.com/v1',
        ], $overrides));
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            messages: [AiMessage::user('Write an intro')],
            system: 'You are the editor assistant.',
            model: 'gpt-5',
            maxTokens: 1024,
        );
    }

    private function fakeOk(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'gpt-5',
            'choices' => [['message' => ['content' => 'Hello world'], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 20],
        ])]);
    }

    public function test_a_completion_returns_the_models_text(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();

        $response = $this->provider()->complete($this->request());

        $this->assertFalse($response->isError());
        $this->assertSame('Hello world', $response->text);
        $this->assertSame('stop', $response->stopReason);
    }

    public function test_the_system_prompt_is_the_first_message_not_a_top_level_field(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertArrayNotHasKey('system', $body);
            $this->assertSame(
                [
                    ['role' => 'system', 'content' => 'You are the editor assistant.'],
                    ['role' => 'user', 'content' => 'Write an intro'],
                ],
                $body['messages'],
            );

            return true;
        });
    }

    /**
     * `effort` has no equivalent in this API. Dropping it is correct; quietly
     * translating it into `temperature` would be a behaviour change wearing a
     * feature's clothes.
     */
    public function test_effort_is_dropped_rather_than_mistranslated(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            foreach (['effort', 'output_config', 'temperature', 'top_p'] as $absent) {
                $this->assertArrayNotHasKey($absent, $request->data());
            }

            return true;
        });
    }

    public function test_the_base_url_decides_the_endpoint(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();

        $this->provider(['base_url' => 'https://openrouter.ai/api/v1'])->complete($this->request());

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions');
    }

    public function test_a_trailing_slash_on_the_base_url_does_not_double_up(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();

        $this->provider(['base_url' => 'https://api.groq.com/openai/v1/'])->complete($this->request());

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions');
    }

    public function test_the_key_travels_as_a_bearer_header(): void
    {
        $this->setKey('sk-test');
        $this->fakeOk();
        $this->provider()->complete($this->request());

        Http::assertSent(function ($request) {
            $this->assertSame('Bearer sk-test', $request->header('Authorization')[0]);
            $this->assertStringNotContainsString('sk-test', $request->body());

            return true;
        });
    }

    /**
     * Ollama and friends run unauthenticated on localhost. Demanding a fake key
     * to talk to 127.0.0.1 would be theatre.
     */
    public function test_a_local_endpoint_is_configured_without_a_key(): void
    {
        $provider = $this->provider(['base_url' => 'http://localhost:11434/v1', 'key' => null, 'local' => true]);

        $this->assertTrue($provider->isConfigured());
    }

    public function test_a_remote_endpoint_without_a_key_is_not_configured(): void
    {
        $this->assertFalse($this->provider()->isConfigured());
    }

    public function test_a_local_request_sends_no_authorization_header(): void
    {
        $this->fakeOk();

        $this->provider(['base_url' => 'http://localhost:11434/v1', 'key' => null, 'local' => true])->complete($this->request());

        Http::assertSent(fn ($request) => $request->header('Authorization') === []);
    }

    public function test_tool_arguments_are_decoded_from_the_json_string_this_api_returns(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response([
            'model' => 'gpt-5',
            'choices' => [['message' => ['content' => null, 'tool_calls' => [[
                'id' => 'call_1',
                'function' => ['name' => 'list_issues', 'arguments' => '{"team":"core"}'],
            ]]], 'finish_reason' => 'tool_calls']],
        ])]);

        $response = $this->provider()->complete($this->request());

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame(['team' => 'core'], $response->toolCalls[0]['arguments']);
    }

    public function test_an_argument_less_tool_call_echoes_as_an_object_not_an_array(): void
    {
        // An argument-less call decodes to PHP's [], which json_encode turns back into a JSON
        // ARRAY — but `arguments` is specified as an object string, and strict endpoints
        // (MiniMax) reject the whole follow-up request over "[]". This was exactly the panel's
        // "works for chat, errors when building a post" failure: authoring flows begin with
        // argument-less discovery calls (list_blocks), chat never calls a tool at all.
        $this->setKey('sk-test');
        $this->fakeOk();

        $this->provider()->complete(new AiRequest(messages: [
            AiMessage::user('build me a post'),
            AiMessage::toolRequest('', [['id' => 'call_1', 'name' => 'heisenberg__list_blocks', 'arguments' => []]]),
            AiMessage::toolResult('call_1', 'heisenberg__list_blocks', '[]'),
        ]));

        Http::assertSent(function ($request) {
            $assistant = collect($request->data()['messages'])->firstWhere('role', 'assistant');

            return $assistant !== null
                && $assistant['tool_calls'][0]['function']['arguments'] === '{}';
        });
    }

    public function test_streaming_stops_on_the_literal_done_terminator(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response(
            'data: {"choices":[{"delta":{"content":"Hel"}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"content":"lo"},"finish_reason":"stop"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n"
        )]);

        $events = iterator_to_array($this->provider()->stream($this->request()));
        $types = array_map(static fn (AiStreamEvent $e): string => $e->type, $events);

        $this->assertSame([AiStreamEvent::TEXT_DELTA, AiStreamEvent::TEXT_DELTA, AiStreamEvent::DONE], $types);
        $this->assertSame('Hello', $events[0]->text . $events[1]->text);
    }

    /**
     * A streamed tool call is delivered in pieces: the opening fragment names it,
     * every fragment after appends a slice of the argument JSON, and `index` is
     * the only thing tying them together. This used to be ignored entirely, so a
     * tool-using turn streamed as an empty answer and looked like the model had
     * been cut off.
     */
    public function test_a_streamed_tool_call_is_reassembled_from_its_fragments(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response(
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"list_blocks","arguments":"{\"ti"}}]}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"er\":\"authors\"}"}}]}}]}' . "\n\n"
            . 'data: {"choices":[{"delta":{},"finish_reason":"tool_calls"}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n"
        )]);

        $events = iterator_to_array($this->provider()->stream($this->request()));
        $calls = array_values(array_filter($events, static fn (AiStreamEvent $e): bool => $e->type === AiStreamEvent::TOOL_USE));

        $this->assertCount(1, $calls);
        $this->assertSame('call_1', $calls[0]->data['id']);
        $this->assertSame('list_blocks', $calls[0]->data['name']);
        $this->assertSame(['tier' => 'authors'], $calls[0]->data['arguments']);
    }

    /** The call is still reported when its arguments never formed valid JSON. */
    public function test_a_tool_call_with_unparseable_arguments_is_still_reported(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response(
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"c1","function":{"name":"read_post","arguments":"{oops"}}]}}]}' . "\n\n"
            . 'data: [DONE]' . "\n\n"
        )]);

        $events = iterator_to_array($this->provider()->stream($this->request()));
        $calls = array_values(array_filter($events, static fn (AiStreamEvent $e): bool => $e->type === AiStreamEvent::TOOL_USE));

        $this->assertCount(1, $calls);
        $this->assertSame([], $calls[0]->data['arguments']);
    }

    /**
     * Not every gateway sends `[DONE]`; the body just ends. The stream must still
     * terminate with `done` so the panel can tell finished from dropped.
     */
    public function test_a_stream_that_ends_without_the_terminator_still_reports_done(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response(
            'data: {"choices":[{"delta":{"content":"Hi"},"finish_reason":"length"}]}' . "\n\n"
        )]);

        $events = iterator_to_array($this->provider()->stream($this->request()));
        $last = end($events);

        $this->assertSame(AiStreamEvent::DONE, $last->type);
        $this->assertSame('length', $last->data['stopReason']);
    }

    public function test_an_upstream_error_body_is_never_echoed_to_the_caller(): void
    {
        $this->setKey('sk-test');
        Http::fake(['*' => Http::response([
            'error' => ['type' => 'invalid_api_key', 'message' => 'Incorrect API key provided: sk-test'],
        ], 401)]);

        $response = $this->provider()->complete($this->request());

        $this->assertTrue($response->isError());
        $this->assertStringNotContainsString('sk-test', (string) $response->error);
    }
}

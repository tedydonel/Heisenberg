<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Ai\SseReader;
use Heisenberg\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * One adapter for every OpenAI-compatible `/chat/completions` endpoint — OpenAI
 * itself, Groq, Ollama, OpenRouter, vLLM, LM Studio. They differ in host and
 * model names, not in wire format, so pointing `base_url` somewhere else is the
 * whole configuration.
 *
 * Two consequences of that breadth:
 *
 * - **The model catalogue is host-supplied and may be empty**, because we cannot
 *   know what an arbitrary endpoint serves. {@see \Heisenberg\Services\AiSettingsRepository}
 *   accepts any well-formed model id for this provider and lets the endpoint
 *   reject it, rather than pretending to validate against a list we don't have.
 * - **`effort` has no equivalent** in this API, so it is dropped rather than
 *   mistranslated into `temperature` — which would be a silent behaviour change
 *   dressed up as a feature.
 *
 * The system prompt is a `system` MESSAGE here, unlike Anthropic's top-level
 * field. That difference is the reason {@see AiMessage} carries no system role:
 * each adapter renders it the way its own API expects.
 */
class OpenAiCompatibleProvider implements AiProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function id(): string
    {
        // The PROVIDER's id, not the format's — OpenAI, xAI, Gemini, Groq,
        // OpenRouter and a local Ollama all speak this shape and each gets its
        // own adapter instance.
        return (string) ($this->config['id'] ?? 'openai');
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? 'OpenAI-compatible');
    }

    /**
     * A local endpoint (Ollama, LM Studio, vLLM) needs no key at all, so a
     * configured base_url with no key counts as configured — requiring a fake
     * key to talk to localhost would be theatre.
     */
    public function isConfigured(): bool
    {
        if ($this->baseUrl() === '') {
            return false;
        }

        return $this->key() !== null || ! $this->requiresKey();
    }

    /**
     * Every endpoint speaking this shape exposes `GET /models`. Discovery is
     * what makes adding a provider a two-field job — a base URL and a key — and
     * it stays correct as a vendor's catalogue changes without telling us.
     *
     * @return list<string>
     */
    public function discoverModels(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->get($this->baseUrl() . '/models');
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = (array) $response->json();
        // OpenAI answers {data: [{id}]}; a few gateways answer a bare array.
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $ids = [];
        foreach ((array) $rows as $model) {
            $id = is_array($model) ? (string) ($model['id'] ?? '') : (is_string($model) ? $model : '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (! $this->isConfigured()) {
            return AiResponse::error(__('heisenberg::editor.ai.provider_not_configured', ['provider' => $this->label()]));
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->post($this->endpoint(), $this->body($request));
        } catch (\Throwable $e) {
            return AiResponse::error($this->networkMessage());
        }

        if ($response->failed()) {
            return AiResponse::error($this->apiMessage($response->json(), $response->status()));
        }

        $payload = (array) $response->json();
        $choice = (array) ($payload['choices'][0] ?? []);
        $message = (array) ($choice['message'] ?? []);

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $call = (array) $call;
            $function = (array) ($call['function'] ?? []);
            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($function['name'] ?? ''),
                // Arguments arrive as a JSON *string* here, unlike Anthropic's
                // already-decoded object.
                'arguments' => (array) (json_decode((string) ($function['arguments'] ?? '{}'), true) ?: []),
            ];
        }

        return new AiResponse(
            text: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            stopReason: isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : null,
            model: isset($payload['model']) ? (string) $payload['model'] : null,
            usage: array_map('intval', array_filter((array) ($payload['usage'] ?? []), 'is_numeric')),
        );
    }

    /** @return iterable<AiStreamEvent> */
    public function stream(AiRequest $request): iterable
    {
        if (! $this->isConfigured()) {
            yield AiStreamEvent::error(__('heisenberg::editor.ai.provider_not_configured', ['provider' => $this->label()]));

            return;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout())
                ->withOptions(['stream' => true])
                ->post($this->endpoint(), $this->body($request) + ['stream' => true]);
        } catch (\Throwable $e) {
            yield AiStreamEvent::error($this->networkMessage());

            return;
        }

        if ($response->failed()) {
            yield AiStreamEvent::error($this->apiMessage($response->json(), $response->status()));

            return;
        }

        $reader = new SseReader();
        $body = $response->toPsrResponse()->getBody();
        $finish = null;
        /** @var array<int, array{id: string, name: string, arguments: string}> $partial */
        $partial = [];

        while (true) {
            // Same reasoning as the Anthropic adapter: a throw from the read used
            // to end the streamed response silently, which the panel could only
            // show as a reply that stopped mid-sentence.
            try {
                if ($body->eof()) {
                    break;
                }
                $chunk = $body->read(8192);
            } catch (\Throwable $e) {
                yield AiStreamEvent::error($this->networkMessage());

                return;
            }

            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                continue;
            }

            foreach ($reader->push($chunk) as $frame) {
                // The terminator is the literal string `[DONE]`, not JSON.
                if (trim($frame['data']) === '[DONE]') {
                    foreach ($this->finishToolCalls($partial) as $call) {
                        yield AiStreamEvent::toolUse($call);
                    }
                    yield AiStreamEvent::done(['stopReason' => $finish]);

                    return;
                }

                $payload = SseReader::json($frame['data']);
                if ($payload === null) {
                    continue;
                }

                $choice = (array) ($payload['choices'][0] ?? []);
                $text = (string) ($choice['delta']['content'] ?? '');
                if ($text !== '') {
                    yield AiStreamEvent::textDelta($text);
                }
                // A streamed tool call arrives in fragments: the first frame
                // carries `id` and `function.name`, and every frame after it
                // appends a slice of the argument JSON. `index` — not `id` — is
                // what ties the fragments together, because the later frames
                // usually carry no id at all. Without this the whole call was
                // dropped and a tool-using turn streamed as an empty answer.
                foreach ((array) ($choice['delta']['tool_calls'] ?? []) as $fragment) {
                    $fragment = (array) $fragment;
                    $index = (int) ($fragment['index'] ?? 0);
                    $function = (array) ($fragment['function'] ?? []);

                    $partial[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];
                    if (($fragment['id'] ?? '') !== '') {
                        $partial[$index]['id'] = (string) $fragment['id'];
                    }
                    if (($function['name'] ?? '') !== '') {
                        $partial[$index]['name'] = (string) $function['name'];
                    }
                    $partial[$index]['arguments'] .= (string) ($function['arguments'] ?? '');
                }

                if (($choice['finish_reason'] ?? null) !== null) {
                    $finish = (string) $choice['finish_reason'];
                }
            }
        }

        foreach ($this->finishToolCalls($partial) as $call) {
            yield AiStreamEvent::toolUse($call);
        }

        yield AiStreamEvent::done(['stopReason' => $finish]);
    }

    /**
     * Turn accumulated fragments into whole calls, decoding the argument JSON
     * that arrived as a string. A call whose arguments never parsed is still
     * emitted with an empty argument set — the tool then answers with its own
     * validation error, which the model can read and correct, rather than the
     * call vanishing and the turn ending in silence.
     *
     * @param  array<int, array{id: string, name: string, arguments: string}>       $partial
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function finishToolCalls(array $partial): array
    {
        $calls = [];
        ksort($partial);

        foreach ($partial as $call) {
            if ($call['name'] === '') {
                continue;
            }

            $decoded = json_decode($call['arguments'] === '' ? '{}' : $call['arguments'], true);
            $calls[] = [
                'id' => $call['id'],
                'name' => $call['name'],
                'arguments' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $calls;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];

        $key = $this->key();
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        return $headers;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    private function endpoint(): string
    {
        return $this->baseUrl() . '/chat/completions';
    }

    /** @return array<string, mixed> */
    private function body(AiRequest $request): array
    {
        $messages = [];
        if ($request->system !== null && $request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }

        // The mirror image of Anthropic's block model: here a tool result is its
        // own `tool` ROLE keyed by `tool_call_id`, and the assistant's request is
        // a `tool_calls` array whose arguments are a JSON *string*.
        foreach ($request->messages as $message) {
            if ($message->isToolResult()) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->toolUseId,
                    'content' => $message->content,
                ];
                continue;
            }

            if ($message->hasToolCalls()) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $message->content !== '' ? $message->content : null,
                    'tool_calls' => array_map(static fn (array $call): array => [
                        'id' => $call['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => (string) json_encode($call['arguments']),
                        ],
                    ], $message->toolCalls),
                ];
                continue;
            }

            $messages[] = $message->toArray();
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages' => $messages,
        ];

        if ($request->hasTools()) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ], $request->tools);
        }

        return $body;
    }

    /**
     * Only a remote endpoint is assumed to need a key. Localhost/LAN hosts —
     * Ollama and friends — are the common no-auth case.
     */
    private function requiresKey(): bool
    {
        if (array_key_exists('local', $this->config)) {
            return ! (bool) $this->config['local'];
        }

        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);

        return ! in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            && ! str_ends_with($host, '.local');
    }

    /**
     * Already resolved by the registry (environment first, then the credential
     * store) — an adapter never decides where a key comes from.
     */
    private function key(): ?string
    {
        $key = $this->config['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function timeout(): int
    {
        return (int) config('heisenberg.ai.timeout', 120);
    }

    /** @param array<string, mixed>|null $payload */
    private function apiMessage(?array $payload, int $status): string
    {
        // Deliberately not the upstream message: a 401 body can quote the
        // rejected credential back at us.
        $type = (string) ($payload['error']['type'] ?? $payload['error']['code'] ?? '');

        return __('heisenberg::editor.ai.api_error', [
            'provider' => $this->label(),
            'detail' => $type !== '' ? $type : "HTTP {$status}",
        ]);
    }

    private function networkMessage(): string
    {
        return __('heisenberg::editor.ai.network_error', ['provider' => $this->label()]);
    }
}

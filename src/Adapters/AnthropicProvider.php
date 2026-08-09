<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Ai\SseReader;
use Heisenberg\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API, on Illuminate's HTTP client.
 *
 * Deliberately not the official SDK: this package requires five illuminate
 * packages and htmlpurifier, and adding a vendor SDK for one endpoint would make
 * every adopter carry it. The wire surface used here is small and stable.
 *
 * Three request-shape rules that are easy to get wrong and expensive to debug:
 *
 * 1. **No sampling parameters, ever.** `temperature`, `top_p` and `top_k` are
 *    rejected with a 400 by current models. Depth is controlled by
 *    `output_config.effort` instead — which is why {@see AiRequest} has no field
 *    for any of them.
 * 2. **No `budget_tokens`.** Manual extended thinking was removed; adaptive
 *    thinking (`{"type": "adaptive"}`) replaces it and is what we always send.
 * 3. **Check `stop_reason` before reading content.** A declined request is a
 *    successful HTTP 200 carrying `stop_reason: "refusal"`, so code that reads
 *    `content[0].text` first sees an empty array, not an error.
 */
class AnthropicProvider implements AiProvider
{
    private const API_VERSION = '2023-06-01';

    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function id(): string
    {
        // The PROVIDER's id, not the format's: several vendors can speak this
        // API shape, and each gets its own adapter instance.
        return (string) ($this->config['id'] ?? 'anthropic');
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? 'Anthropic');
    }

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * Anthropic exposes `GET /v1/models`. Discovering beats shipping a list that
     * is wrong the week a new model lands.
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
                ->get(rtrim($this->baseUrl(), '/') . '/v1/models', ['limit' => 100]);
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $ids = [];
        foreach ((array) ($response->json()['data'] ?? []) as $model) {
            $id = (string) ((array) $model)['id'];
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
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
            return AiResponse::error($this->networkMessage($e));
        }

        if ($response->failed()) {
            return AiResponse::error($this->apiMessage($response->json(), $response->status()));
        }

        return $this->toResponse((array) $response->json());
    }

    /** @return iterable<AiStreamEvent> */
    public function stream(AiRequest $request): iterable
    {
        if (! $this->isConfigured()) {
            yield AiStreamEvent::error(__('heisenberg::editor.ai.provider_not_configured', ['provider' => $this->label()]));

            return;
        }

        try {
            // No TOTAL timeout on a stream — same reasoning as OpenAiCompatibleProvider:
            // Guzzle's `timeout` bounds the entire body read and kills long generations
            // mid-stream; the configured budget bounds INACTIVITY between chunks instead.
            $response = Http::withHeaders($this->headers())
                ->timeout(0)
                ->withOptions(['stream' => true, 'read_timeout' => $this->timeout()])
                ->post($this->endpoint(), $this->body($request) + ['stream' => true]);
        } catch (\Throwable $e) {
            yield AiStreamEvent::error($this->networkMessage($e));

            return;
        }

        if ($response->failed()) {
            yield AiStreamEvent::error($this->apiMessage($response->json(), $response->status()));

            return;
        }

        $reader = new SseReader();
        $body = $response->toPsrResponse()->getBody();
        $stopReason = null;
        $toolCalls = [];

        while (true) {
            // A read that throws mid-stream (idle timeout, reset connection) used
            // to escape this generator and kill the whole streamed response, so
            // the panel simply stopped receiving text with no explanation — which
            // is what "the answer is cut off and never finishes" looked like.
            try {
                if ($body->eof()) {
                    break;
                }
                $chunk = $body->read(8192);
            } catch (\Throwable $e) {
                yield AiStreamEvent::error($this->networkMessage($e));

                return;
            }

            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                continue;
            }

            foreach ($reader->push($chunk) as $frame) {
                $payload = SseReader::json($frame['data']);
                if ($payload === null) {
                    continue;
                }

                $type = (string) ($payload['type'] ?? $frame['event'] ?? '');

                if ($type === 'content_block_delta') {
                    $delta = (array) ($payload['delta'] ?? []);
                    // `thinking_delta` frames also arrive when a summary is
                    // requested; only text belongs in the response card.
                    if (($delta['type'] ?? '') === 'text_delta' && ($delta['text'] ?? '') !== '') {
                        yield AiStreamEvent::textDelta((string) $delta['text']);
                    }
                } elseif ($type === 'content_block_start') {
                    $block = (array) ($payload['content_block'] ?? []);
                    if (($block['type'] ?? '') === 'tool_use') {
                        $toolCalls[] = [
                            'id' => (string) ($block['id'] ?? ''),
                            'name' => (string) ($block['name'] ?? ''),
                            'arguments' => (array) ($block['input'] ?? []),
                        ];
                    }
                } elseif ($type === 'message_delta') {
                    $stopReason = (string) (($payload['delta']['stop_reason'] ?? null) ?? $stopReason);
                } elseif ($type === 'error') {
                    yield AiStreamEvent::error($this->apiMessage($payload, 0));

                    return;
                }
            }
        }

        if ($stopReason === 'refusal') {
            yield AiStreamEvent::error(__('heisenberg::editor.ai.refused'));

            return;
        }

        foreach ($toolCalls as $call) {
            yield AiStreamEvent::toolUse($call);
        }

        yield AiStreamEvent::done(['stopReason' => $stopReason]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'x-api-key' => (string) $this->key(),
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
            'accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.anthropic.com'), '/');
    }

    private function endpoint(): string
    {
        return $this->baseUrl() . '/v1/messages';
    }

    /** @return array<string, mixed> */
    private function body(AiRequest $request): array
    {
        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages' => $this->messages($request->messages),
            // Adaptive thinking replaces the removed budget_tokens knob. Depth
            // rides on output_config.effort below.
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => $request->effort],
        ];

        // The system prompt is a top-level field here, NOT a message — which is
        // exactly why AiMessage has no `system` role.
        if ($request->system !== null && $request->system !== '') {
            $body['system'] = $request->system;
        }

        if ($request->hasTools()) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $tool['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ], $request->tools);
        }

        return $body;
    }

    /**
     * Anthropic models tool traffic as CONTENT BLOCKS: the assistant turn holds
     * `tool_use` blocks and the answer comes back as `tool_result` blocks inside
     * a **user** turn (there is no `tool` role here — that is the
     * OpenAI-compatible shape). Consecutive results are merged into one user
     * turn, which the API requires.
     *
     * @param  list<\Heisenberg\Ai\AiMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function messages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if ($message->isToolResult()) {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolUseId,
                    'content' => $message->content,
                ];
                if ($message->isError) {
                    $block['is_error'] = true;
                }

                $last = count($out) - 1;
                if ($last >= 0 && $out[$last]['role'] === 'user' && is_array($out[$last]['content'])) {
                    $out[$last]['content'][] = $block;
                    continue;
                }

                $out[] = ['role' => 'user', 'content' => [$block]];
                continue;
            }

            if ($message->hasToolCalls()) {
                $content = [];
                if ($message->content !== '') {
                    $content[] = ['type' => 'text', 'text' => $message->content];
                }
                foreach ($message->toolCalls as $call) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'],
                        'name' => $call['name'],
                        'input' => $call['arguments'] === [] ? new \stdClass() : $call['arguments'],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            $out[] = $message->toArray();
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function toResponse(array $payload): AiResponse
    {
        $stopReason = isset($payload['stop_reason']) ? (string) $payload['stop_reason'] : null;
        $model = isset($payload['model']) ? (string) $payload['model'] : null;

        // Rule 3: before content, not after.
        if ($stopReason === 'refusal') {
            $explanation = (string) ($payload['stop_details']['explanation'] ?? '');

            return AiResponse::refusal(
                $explanation !== '' ? $explanation : __('heisenberg::editor.ai.refused'),
                $model,
            );
        }

        $text = '';
        $toolCalls = [];
        foreach ((array) ($payload['content'] ?? []) as $block) {
            $block = (array) $block;
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return new AiResponse(
            text: $text,
            toolCalls: $toolCalls,
            stopReason: $stopReason,
            model: $model,
            usage: array_map('intval', array_filter((array) ($payload['usage'] ?? []), 'is_numeric')),
        );
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

    /**
     * Never echo the provider's raw error body to the panel: an upstream 401
     * message can quote the key it rejected. Report the shape, not the payload.
     *
     * @param array<string, mixed>|null $payload
     */
    private function apiMessage(?array $payload, int $status): string
    {
        $type = (string) ($payload['error']['type'] ?? '');

        return __('heisenberg::editor.ai.api_error', [
            'provider' => $this->label(),
            'detail' => $type !== '' ? $type : ($status !== 0 ? "HTTP {$status}" : 'error'),
        ]);
    }

    private function networkMessage(\Throwable $e): string
    {
        return __('heisenberg::editor.ai.network_error', ['provider' => $this->label()]);
    }
}

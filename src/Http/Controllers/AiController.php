<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Adapters\GuestActor;
use Heisenberg\Adapters\LocalDevRoleGate;
use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Ai\EditorPrompt;
use Heisenberg\Contracts\AiCredentialStore;
use Heisenberg\Contracts\RoleGate;
use Heisenberg\Services\AiProviderRegistry;
use Heisenberg\Services\AiSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The AI panel's backend — settings, credentials, model discovery, completion
 * and streaming. Routed under `/editor/ai` (routes/ai.php).
 *
 * Authorization has two tiers. Configuration (which vendor sees the document,
 * which key is used) is `admins`, the same bar as editing the site theme. Using
 * the assistant is `authors` — an authoring act, but still not open, because
 * every call spends the operator's API budget.
 *
 * **No response from this controller ever contains key material.** Keys are
 * write-only: `putKey()` accepts one and `describe()` reports `has_key` plus the
 * name of the env var, so an operator can be told what is set without the value
 * being readable back.
 */
class AiController
{
    public function __construct(
        private AiSettingsRepository $settings,
        private AiProviderRegistry $providers,
        private AiCredentialStore $credentials,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        $settings = $request->input('settings');
        if (! is_array($settings)) {
            return response()->json(['saved' => false, 'errors' => ['settings must be an object']], 422);
        }

        $result = $this->settings->save($settings);
        if (! $result['saved']) {
            return response()->json(['saved' => false, 'errors' => $result['errors']], 422);
        }

        return response()->json(['saved' => true] + $this->payload());
    }

    /**
     * Store or clear a provider's API key. Write-only by design: there is no
     * companion read endpoint and there must never be one.
     */
    public function putKey(Request $request, string $provider): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        if ($this->settings->provider($provider) === null) {
            return response()->json(['saved' => false, 'errors' => ["Unknown provider '{$provider}'."]], 404);
        }

        $key = (string) $request->input('key', '');
        if (trim($key) === '') {
            $this->credentials->forget($provider);
        } else {
            $this->credentials->put($provider, $key);
        }

        // Echo the whole payload back so the modal's connection badges update
        // without a second round trip — booleans only.
        return response()->json(['saved' => true] + $this->payload());
    }

    /**
     * Ask a provider's endpoint what models it serves.
     *
     * This is why adding a provider is a two-field job: give it a base URL and a
     * key, and the catalogue comes from the vendor rather than from a list
     * shipped in this package, which would be stale the week a model launches.
     */
    public function discoverModels(Request $request, string $provider): JsonResponse
    {
        if (($denied = $this->denyUnlessAdmin($request)) !== null) {
            return $denied;
        }

        $profile = $this->settings->provider($provider);
        $adapter = $this->providers->make($provider);
        if ($profile === null || $adapter === null) {
            return response()->json(['ok' => false, 'error' => "Unknown provider '{$provider}'."], 404);
        }

        if (! $adapter->isConfigured()) {
            return response()->json([
                'ok' => false,
                'error' => __('heisenberg::editor.ai.provider_not_configured', ['provider' => $profile->label]),
            ], 422);
        }

        $models = $adapter->discoverModels();

        return response()->json([
            'ok' => true,
            'models' => $models,
            // An endpoint that answers nothing is not an error — some gateways
            // don't implement /models. The operator adds ids by hand instead.
            'empty' => $models === [],
        ]);
    }

    public function complete(Request $request, EditorPrompt $prompt): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $text = trim((string) $request->input('prompt', ''));
        if ($text === '') {
            return response()->json(['error' => __('heisenberg::editor.ai.empty_prompt')], 422);
        }

        $provider = $this->providers->active();
        $aiRequest = $this->buildRequest(
            $prompt,
            $text,
            (array) $request->input('context', []),
            (array) $request->input('history', []),
            $request->input('model'),
        );

        // The tool loop always runs: Heisenberg's own tools need no configuration,
        // and they are what let the assistant look up a block contract or read a
        // post rather than asking the user for it. Connected MCP servers join the
        // same loop when the client is enabled.
        //
        // Unlike stream(), nothing here is already committed to an open
        // connection — but an uncaught \Throwable would still fall through to a
        // raw 500 instead of the structured error shape every other failure on
        // this endpoint uses, so it gets the same try/catch treatment.
        try {
            $response = app(\Heisenberg\Services\AiToolRunner::class)->run($provider, $aiRequest);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(
                \Heisenberg\Ai\AiResponse::error(__('heisenberg::editor.ai.completion_failed'))->toArray(),
                502,
            );
        }

        // Several open-weight models emit their chain of thought inline as
        // <think>…</think>; that is serving-template behaviour, not something
        // prompting removes, so it is filtered at the boundary.
        $response = new \Heisenberg\Ai\AiResponse(
            text: \Heisenberg\Ai\ReasoningFilter::strip($response->text),
            toolCalls: $response->toolCalls,
            stopReason: $response->stopReason,
            model: $response->model,
            usage: $response->usage,
            error: $response->error,
        );

        return response()->json($response->toArray(), $response->isError() ? 502 : 200);
    }

    /**
     * Three short, conversation-aware follow-up suggestions for the panel's
     * quick-insert chips. Best-effort by design: this spends a little of the
     * operator's budget on a convenience, so any failure returns an empty list
     * with 200 rather than an error the panel would have to handle.
     *
     * Language follows the editor's current locale (the footer switcher, via
     * EditorLocaleMiddleware) so the chips read in the language the author is
     * working in — French chips for a French document, English for English.
     */
    public function suggest(Request $request): JsonResponse
    {
        if (($denied = $this->denyUnlessAuthor($request)) !== null) {
            return $denied;
        }

        $history = $this->historyMessages((array) $request->input('history', []));
        if ($history === []) {
            return response()->json(['suggestions' => []]);
        }

        $provider = $this->providers->active();
        if (! $provider->isConfigured()) {
            return response()->json(['suggestions' => []]);
        }

        $locale = in_array($request->input('locale'), ['en', 'fr'], true)
            ? (string) $request->input('locale')
            : app()->getLocale();
        $language = $locale === 'fr' ? 'French' : 'English';

        // A compact replay — just the words, capped — is enough to ground the
        // suggestions without re-sending the whole document.
        $transcript = collect($history)
            ->map(fn (AiMessage $m): string => ($m->role === AiMessage::ROLE_USER ? 'User: ' : 'Assistant: ') . \Illuminate\Support\Str::limit($m->content, 500))
            ->implode("\n");

        $model = $this->resolveModel($request->input('model'));
        $suggestRequest = new AiRequest(
            messages: [AiMessage::user("The conversation so far:\n\n{$transcript}")],
            system: "You propose what the user might ask the assistant next in a block-based page "
                . "builder. Reply with ONLY a JSON array of exactly 3 suggestions, each a short "
                . "imperative of at most 6 words, written in {$language}. Base them on where the "
                . "conversation is going. No prose, no code fence, no keys — just the array.",
            model: $model?->id,
            effort: AiRequest::normalizeEffort('low'),
            maxTokens: 200,
        );

        try {
            $response = $provider->complete($suggestRequest);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['suggestions' => []]);
        }

        return response()->json(['suggestions' => $this->parseSuggestions($response->text)]);
    }

    /**
     * Pull a JSON string array out of the model's reply, tolerating a stray
     * code fence or preamble. Anything that isn't three-ish short strings is
     * dropped rather than shown.
     *
     * @return list<string>
     */
    private function parseSuggestions(string $text): array
    {
        $text = \Heisenberg\Ai\ReasoningFilter::strip($text);
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = \Illuminate\Support\Str::limit(trim($item), 60, '');
            }
            if (count($out) === 3) {
                break;
            }
        }

        return $out;
    }

    public function stream(Request $request, EditorPrompt $prompt): StreamedResponse
    {
        // A reasoning model can stream for longer than a host's max_execution_time —
        // PHP killing the worker mid-stream reads as "the connection ended before the
        // reply did". Harmless where the limit is already 0 (CLI server).
        @set_time_limit(0);

        $denied = $this->denyUnlessAuthor($request);
        $text = trim((string) $request->input('prompt', ''));
        $context = (array) $request->input('context', []);

        $provider = $this->providers->active();
        $aiRequest = $this->buildRequest(
            $prompt,
            $text,
            $context,
            (array) $request->input('history', []),
            $request->input('model'),
        );

        return response()->stream(function () use ($denied, $text, $provider, $aiRequest): void {
            // Tear every output buffer down before the first frame. PHP-FPM, the
            // built-in server and most Laravel stacks start with at least one
            // buffer open, and zlib compression opens another that ob_flush()
            // cannot reach — with either in place the whole reply is held until
            // the handler returns and then arrives in one lump, which is what
            // made this look like it was not streaming at all.
            //
            // Skipped under the plain CLI SAPI: there is no browser waiting on the
            // other end there, and the only buffers open belong to the test
            // harness capturing this output. Every real SAPI that serves HTTP
            // (fpm-fcgi, apache2handler, cli-server) still goes through it.
            if (PHP_SAPI !== 'cli') {
                @ini_set('zlib.output_compression', '0');
                @ini_set('output_buffering', '0');
                @ini_set('implicit_flush', '1');
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                ob_implicit_flush(true);
            }

            $emit = function (AiStreamEvent $event): void {
                echo 'data: ' . json_encode($event->toArray() + ['type' => $event->type]) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            if ($denied !== null) {
                $emit(AiStreamEvent::error(__('heisenberg::editor.ai.not_allowed')));

                return;
            }
            if ($text === '') {
                $emit(AiStreamEvent::error(__('heisenberg::editor.ai.empty_prompt')));

                return;
            }

            // Streamed turns run the same tool loop as complete(). They used to go
            // straight to the provider with no tools attached, which meant the
            // assistant silently lost every platform tool as soon as streaming was
            // on — and a turn that needed one ended with nothing to show.
            $stream = app(\Heisenberg\Services\AiToolRunner::class)->stream($provider, $aiRequest);

            $terminated = false;
            try {
                foreach ($stream as $event) {
                    if ($event->type === AiStreamEvent::DONE || $event->type === AiStreamEvent::ERROR) {
                        $terminated = true;
                    }
                    $emit($event);
                }
            } catch (\Throwable $e) {
                // The connection is already open, so an exception cannot become a
                // 500 — without this frame the browser just sees the stream close
                // and the reply looks like it stopped mid-sentence.
                report($e);
                $emit(AiStreamEvent::error(__('heisenberg::editor.ai.stream_failed')));

                return;
            }

            // Every stream ends with an explicit terminator, so the panel can tell
            // "the model finished" from "the connection dropped".
            if (! $terminated) {
                $emit(AiStreamEvent::done());
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            // nginx buffers proxied responses by default; without this every
            // frame is held until the response ends.
            'X-Accel-Buffering' => 'no',
            // Stops a compressing proxy from buffering to build a gzip window.
            'Content-Encoding' => 'none',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{role?: mixed, content?: mixed}> $history prior turns, oldest first
     */
    private function buildRequest(EditorPrompt $prompt, string $text, array $context, array $history = [], ?string $modelKey = null): AiRequest
    {
        $model = $this->resolveModel($modelKey);

        // Prior turns come first as plain user/assistant text, then the current
        // turn — which is the only one carrying the live document context, so a
        // reopened conversation is remembered without re-attaching stale
        // document snapshots from every past turn.
        $messages = array_merge(
            $this->historyMessages($history),
            [AiMessage::user($prompt->user($text, $context))],
        );

        return new AiRequest(
            messages: $messages,
            system: $prompt->system(),
            model: $model?->id,
            // Effort rides on the MODEL, not on one global setting: a small local
            // model has no use for `xhigh` and a reasoning model is wasted at `low`.
            effort: $model?->effort ?? AiRequest::normalizeEffort(config('heisenberg.ai.effort')),
            maxTokens: (int) config('heisenberg.ai.max_tokens', 16000),
        );
    }

    /**
     * The model the client asked for (its `provider:id` key), falling back to
     * the configured active model. Only models the operator has configured can
     * be named, so this widens nothing — it just lets the chat's own picker win.
     */
    private function resolveModel(?string $modelKey): ?\Heisenberg\Ai\AiModel
    {
        $active = $this->settings->activeModel();
        if ($modelKey === null || $modelKey === '') {
            return $active;
        }

        foreach ($this->settings->load()['models'] as $raw) {
            $model = \Heisenberg\Ai\AiModel::fromArray($raw);
            if ($model->key() === $modelKey && $model->enabled) {
                return $model;
            }
        }

        return $active;
    }

    /**
     * Turn the client's transcript into replayable message turns. Only `user`
     * and `assistant` text turns are accepted — tool rounds are transport, not
     * transcript — and the tail is capped so a long thread can't blow the
     * context window.
     *
     * @param list<array{role?: mixed, content?: mixed}> $history
     * @return list<AiMessage>
     */
    private function historyMessages(array $history): array
    {
        $cap = (int) config('heisenberg.ai.history_turns', 20);
        if ($cap > 0 && count($history) > $cap) {
            $history = array_slice($history, -$cap);
        }

        $messages = [];
        foreach ($history as $turn) {
            $role = is_array($turn) ? (string) ($turn['role'] ?? '') : '';
            $content = is_array($turn) ? trim((string) ($turn['content'] ?? '')) : '';
            if ($content === '') {
                continue;
            }
            if ($role === AiMessage::ROLE_ASSISTANT) {
                $messages[] = AiMessage::assistant($content);
            } elseif ($role === AiMessage::ROLE_USER) {
                $messages[] = AiMessage::user($content);
            }
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $settings = $this->settings->load();
        $active = $this->settings->activeModel();

        return [
            'settings'  => $settings,
            'providers' => $this->providers->describe(),
            'presets'   => $this->providers->availablePresets(),
            'formats'   => $this->providers->formats(),
            'active'    => $active?->key(),
            'tools'     => AiSettingsRepository::TOOLS,
            'efforts'   => AiRequest::EFFORTS,
            'mcp' => [
                'client_enabled' => (bool) config('heisenberg.ai.mcp.client.enabled', false),
                'server_enabled' => (bool) config('heisenberg.ai.mcp.server.enabled', false),
                'server_path'    => (string) config('heisenberg.ai.mcp.server.path', 'heisenberg/mcp'),
                'tokens_env'     => (string) config('heisenberg.ai.mcp.server.tokens_env', ''),
            ],
        ];
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        return $this->deny($request, 'admins', [
            'saved'  => false,
            'errors' => ['You are not authorized to change the AI configuration.'],
        ]);
    }

    private function denyUnlessAuthor(Request $request): ?JsonResponse
    {
        return $this->deny($request, 'authors', ['error' => __('heisenberg::editor.ai.not_allowed')]);
    }

    /** @param array<string, mixed> $body */
    private function deny(Request $request, string $tier, array $body): ?JsonResponse
    {
        $actor = $request->user() ?? new GuestActor();
        $roleGate = new LocalDevRoleGate(app(RoleGate::class));

        return $roleGate->is($actor, $tier) ? null : response()->json($body, 403);
    }
}

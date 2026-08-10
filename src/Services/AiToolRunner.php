<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Ai\McpServer;
use Heisenberg\Contracts\AiProvider;
use Heisenberg\Contracts\McpClient;
use Illuminate\Support\Facades\Log;

/**
 * The server-side tool loop: request → `tool_use` → execute → `tool_result` →
 * repeat, until the model stops asking or the iteration cap is hit.
 *
 * Heisenberg owns this loop rather than delegating to a provider's built-in MCP
 * connector, because that connector is Anthropic-only and this package ships two
 * provider families — and because owning it is what makes MCP servers on
 * localhost or inside a VPC reachable at all.
 *
 * Two safety properties, both enforced here and again in the client:
 *
 * - **The allow-list is checked before execution**, never after. A model that
 *   asks for a tool outside it gets a refusal as the tool result and can adapt;
 *   nothing runs.
 * - **The loop is bounded.** Without a cap, a model that keeps calling tools is
 *   an unbounded bill and an unbounded wait.
 */
class AiToolRunner
{
    /**
     * Told to the model, not the user, on the graceful exhaustion pass — plain
     * English regardless of locale, same as every other tool-facing string in
     * this class ("No such tool", the dedup note): the audience is the model,
     * not the panel.
     */
    private const BUDGET_SPENT_NOTICE = 'You have used your entire tool-call budget for this turn and no '
        . 'more tools are available now. Answer the user now, using only what you already gathered. '
        . 'Do not attempt to call a tool.';

    public function __construct(
        private McpClient $mcp,
        private AiSettingsRepository $settings,
        private ?HeisenbergToolSource $local = null,
    ) {
    }

    /**
     * Tool definitions from every enabled server, in the neutral shape
     * {@see AiRequest::$tools} expects.
     *
     * A server that is unreachable is skipped rather than fatal: one broken
     * integration must not take the assistant down with it.
     *
     * @return array{tools: list<array<string, mixed>>, byName: array<string, McpServer>, errors: list<string>}
     */
    public function discover(?array $servers = null): array
    {
        $servers ??= $this->settings->mcpServers();
        // Heisenberg's own tools come first and are always available: they need
        // no configuration, no network and no credential, and without them the
        // assistant cannot read the document it is being asked to edit.
        $tools = $this->local?->tools() ?? [];
        $byName = [];
        $errors = [];

        foreach ($servers as $server) {
            if (! $server->enabled || ! $server->isConfigured()) {
                continue;
            }

            try {
                $discovered = $this->mcp->listTools($server);
            } catch (\Throwable $e) {
                $errors[] = "{$server->id}: {$e->getMessage()}";
                continue;
            }

            foreach ($discovered as $tool) {
                // Discovery advertises everything the server has; the allow-list
                // decides what the model is even told about.
                if (! $server->allows($tool['name'])) {
                    continue;
                }
                // Namespaced so two servers offering `search` stay distinct.
                $name = $server->id . '__' . $tool['name'];
                $tools[] = [
                    'name' => $name,
                    'description' => $tool['description'],
                    'input_schema' => $tool['input_schema'],
                ];
                $byName[$name] = $server;
            }
        }

        return ['tools' => $tools, 'byName' => $byName, 'errors' => $errors];
    }

    /**
     * Run `$request` to completion, executing any tools the model asks for.
     *
     * @param array<string, McpServer>|null $byName
     */
    public function run(AiProvider $provider, AiRequest $request, ?array $byName = null, ?array $tools = null): AiResponse
    {
        if ($tools === null || $byName === null) {
            $discovered = $this->discover();
            $tools = $discovered['tools'];
            $byName = $discovered['byName'];
        }

        if ($tools === [] || ! $provider->supportsTools()) {
            return $provider->complete($request);
        }

        $messages = $request->messages;
        $max = max(1, (int) config('heisenberg.ai.mcp.client.max_iterations', 8));
        $startedAt = microtime(true);
        $usage = [];
        $totalCalls = 0;
        // (name, normalized-arguments) -> result, scoped to this call only: a
        // model that asks for the same thing twice gets the same answer back
        // instead of paying for (and waiting on) the call again.
        $cache = [];

        for ($iteration = 0; $iteration < $max; $iteration++) {
            $response = $provider->complete($request->withTools($tools)->withMessages($messages));
            $usage = self::mergeUsage($usage, $response->usage);

            if ($response->isError() || ! $response->hasToolCalls()) {
                $this->logSummary($iteration + 1, $totalCalls, $usage, $startedAt);

                // Only intermediate rounds' usage was ever discarded — the final
                // round's own numbers are already in $response->usage, so folding
                // it into $usage and carrying that forward is enough.
                return $response->isError() ? $response : new AiResponse(
                    text: $response->text,
                    toolCalls: $response->toolCalls,
                    stopReason: $response->stopReason,
                    model: $response->model,
                    usage: $usage,
                    error: $response->error,
                );
            }

            // Replay the model's own turn so the results it gets back are
            // matched to the calls it made.
            $messages[] = AiMessage::toolRequest($response->text, $response->toolCalls);

            $results = [];
            foreach ($response->toolCalls as $call) {
                $result = $this->execute($call, $byName, $cache);
                $messages[] = $result;
                $results[] = $result;
            }
            $totalCalls += count($response->toolCalls);
            $this->logRound($iteration + 1, $response->toolCalls, $results);
        }

        // The cap is reached and the model is still asking for tools. A from-
        // scratch creative prompt reliably burns rounds on one-at-a-time
        // discovery calls before it ever produces content — erroring here threw
        // all of that away. Spend ONE more pass with no tools attached instead:
        // told its budget is spent, the model has to land the turn on what it
        // already gathered rather than losing it.
        $final = $this->finalPass($provider, $request, $messages);
        $usage = self::mergeUsage($usage, $final?->usage ?? []);
        $this->logSummary($max + 1, $totalCalls, $usage, $startedAt);

        if ($final !== null && ! $final->isError()) {
            return new AiResponse(
                text: $final->text,
                toolCalls: $final->toolCalls,
                stopReason: $final->stopReason,
                model: $final->model,
                usage: $usage,
                error: null,
            );
        }

        return AiResponse::error(__('heisenberg::editor.ai.tool_loop_exhausted', ['max' => $max]));
    }

    /**
     * The same loop, streamed.
     *
     * Text is forwarded delta by delta as it arrives; `tool_use` is swallowed and
     * acted on instead, because a tool call is machinery, not an answer. Only the
     * pass in which the model stops asking for tools is allowed to emit `done`,
     * so the panel never sees a turn end while work is still outstanding.
     *
     * Without this, `stream()` went straight to the provider with no tools
     * attached: the assistant lost every platform tool the moment streaming was
     * on, and any turn that needed one ended with nothing to show for it.
     *
     * @param  array<string, McpServer>|null $byName
     * @return iterable<AiStreamEvent>
     */
    public function stream(AiProvider $provider, AiRequest $request, ?array $byName = null, ?array $tools = null): iterable
    {
        if ($tools === null || $byName === null) {
            $discovered = $this->discover();
            $tools = $discovered['tools'];
            $byName = $discovered['byName'];
        }

        if ($tools === [] || ! $provider->supportsTools()) {
            yield from $provider->stream($request);

            return;
        }

        $messages = $request->messages;
        $max = max(1, (int) config('heisenberg.ai.mcp.client.max_iterations', 8));
        $startedAt = microtime(true);
        $usage = [];
        $totalCalls = 0;
        $cache = [];

        for ($iteration = 0; $iteration < $max; $iteration++) {
            $calls = [];
            $text = '';
            $failed = false;

            foreach ($provider->stream($request->withTools($tools)->withMessages($messages)) as $event) {
                if ($event->type === AiStreamEvent::TOOL_USE) {
                    $calls[] = $event->data;
                } elseif ($event->type === AiStreamEvent::TEXT_DELTA) {
                    $text .= $event->text;
                    yield $event;
                } elseif ($event->type === AiStreamEvent::ERROR) {
                    $failed = true;
                    yield $event;
                } elseif ($event->type === AiStreamEvent::DONE) {
                    // Not populated by either adapter today (usage rides on the
                    // non-streaming response only) — merged defensively in case
                    // that ever changes, so the summary log below stays accurate.
                    $usage = self::mergeUsage($usage, (array) ($event->data['usage'] ?? []));
                    if ($calls === [] && ! $failed) {
                        yield $event;
                    }
                }
            }

            if ($failed || $calls === []) {
                $this->logSummary($iteration + 1, $totalCalls, $usage, $startedAt);

                return;
            }

            // Replay the model's own turn so the results it gets back are
            // matched to the calls it made.
            $messages[] = AiMessage::toolRequest($text, $calls);

            $results = [];
            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');

                // Client-applied tools (write_canvas, set_page_title) invert the
                // usual order: validate FIRST, then ship the frame — their
                // arguments are the payload the panel applies to the editor, and
                // the panel must never apply what validation rejected. `ok`
                // tells it whether to.
                if (HeisenbergToolSource::appliesClientSide($name)) {
                    $result = $this->execute($call, $byName, $cache);
                    yield AiStreamEvent::toolUse([
                        'name' => $name,
                        'arguments' => (array) ($call['arguments'] ?? []),
                        'ok' => ! $result->isError,
                    ]);
                    $messages[] = $result;
                    $results[] = $result;
                    continue;
                }

                // Surface the activity to the client BEFORE the (possibly slow) call runs —
                // without this the panel sits silent through every tool round and the whole
                // build looks like nothing is streaming. Name only: other tools' arguments
                // are transport, not payload.
                yield AiStreamEvent::toolUse(['name' => $name]);
                $result = $this->execute($call, $byName, $cache);
                $messages[] = $result;
                $results[] = $result;
            }
            $totalCalls += count($calls);
            $this->logRound($iteration + 1, $calls, $results);
        }

        // Same graceful-exhaustion contract as run(): one last pass with no
        // tools attached, so a turn that streamed real work does not end in an
        // error frame that erases it client-side.
        $messages[] = AiMessage::user(self::BUDGET_SPENT_NOTICE);
        $finalFailed = false;
        $sawDone = false;

        try {
            foreach ($provider->stream($request->withTools([])->withMessages($messages)) as $event) {
                if ($event->type === AiStreamEvent::ERROR) {
                    // Swallowed rather than forwarded — the tool_loop_exhausted
                    // frame below explains this better than a raw provider error would.
                    $finalFailed = true;
                    continue;
                }
                if ($event->type === AiStreamEvent::TOOL_USE) {
                    // The budget is spent: a stray call here is ignored, not run.
                    continue;
                }
                if ($event->type === AiStreamEvent::DONE) {
                    $sawDone = true;
                    $usage = self::mergeUsage($usage, (array) ($event->data['usage'] ?? []));
                }
                yield $event;
            }
        } catch (\Throwable $e) {
            report($e);
            $finalFailed = true;
        }

        $this->logSummary($max + 1, $totalCalls, $usage, $startedAt);

        if ($finalFailed || ! $sawDone) {
            yield AiStreamEvent::error(__('heisenberg::editor.ai.tool_loop_exhausted', ['max' => $max]));
        }
    }

    /**
     * The one extra pass made when the loop is about to exhaust with the model
     * still asking for tools. Never throws: a provider adapter is contractually
     * not supposed to (see {@see AiProvider}), but this is the last chance to
     * turn a genuine fault into the tool_loop_exhausted message instead of a 500.
     *
     * @param list<AiMessage> $messages
     */
    private function finalPass(AiProvider $provider, AiRequest $request, array $messages): ?AiResponse
    {
        $messages[] = AiMessage::user(self::BUDGET_SPENT_NOTICE);

        try {
            return $provider->complete($request->withTools([])->withMessages($messages));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param array{id: string, name: string, arguments: array} $call
     * @param array<string, McpServer>                          $byName
     * @param array<string, array{content: string, isError: bool}> $cache keyed
     *        by name + normalized arguments, scoped to a single run()/stream() call
     */
    private function execute(array $call, array $byName, array &$cache): AiMessage
    {
        $name = (string) ($call['name'] ?? '');
        $id = (string) ($call['id'] ?? '');
        $arguments = (array) ($call['arguments'] ?? []);
        $key = $name . '#' . self::argumentsKey($arguments);

        if (isset($cache[$key])) {
            $cached = $cache[$key];

            return AiMessage::toolResult(
                $id,
                $name,
                '(You already called this with identical arguments — same result as before.) ' . $cached['content'],
                isError: $cached['isError'],
            );
        }

        $result = $this->runTool($name, $arguments, $byName);
        $cache[$key] = $result;

        return AiMessage::toolResult($id, $name, $result['content'], isError: $result['isError']);
    }

    /**
     * @param  array<string, mixed>     $arguments
     * @param  array<string, McpServer> $byName
     * @return array{content: string, isError: bool}
     */
    private function runTool(string $name, array $arguments, array $byName): array
    {
        // In-process first — no server, no token, no socket.
        if ($this->local !== null && $this->local->owns($name)) {
            try {
                return $this->local->call($name, $arguments);
            } catch (\Throwable $e) {
                // A tool handler that throws must not skip the tool-result
                // channel entirely — the model still needs an answer for every
                // call it made, even a failing one.
                report($e);

                return self::toolFailure($name, $e);
            }
        }

        $server = $byName[$name] ?? null;

        if ($server === null) {
            // The model invented a tool, or named one it was never offered.
            return ['content' => "No such tool: '{$name}'.", 'isError' => true];
        }

        $bare = substr($name, strlen($server->id) + 2);

        try {
            return $this->mcp->callTool($server, $bare, $arguments);
        } catch (\Throwable $e) {
            report($e);

            return self::toolFailure($name, $e);
        }
    }

    /** @return array{content: string, isError: bool} */
    private static function toolFailure(string $name, \Throwable $e): array
    {
        return [
            'content' => "Tool '{$name}' failed with " . $e::class . ': ' . $e->getMessage()
                . '. Proceed without it, or try a different approach.',
            'isError' => true,
        ];
    }

    /** Same call, same key, regardless of argument key order. */
    private static function argumentsKey(array $arguments): string
    {
        $normalize = static function (array $value) use (&$normalize): array {
            ksort($value);

            return array_map(static fn ($v) => is_array($v) ? $normalize($v) : $v, $value);
        };

        return json_encode($normalize($arguments), JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @param  array<string, int|string> $a
     * @param  array<string, int|string> $b
     * @return array<string, int>
     */
    private static function mergeUsage(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $a[$key] = ((int) ($a[$key] ?? 0)) + (int) $value;
        }

        return $a;
    }

    /**
     * @param list<array{id: string, name: string, arguments: array}> $calls
     * @param list<AiMessage>                                         $results
     */
    private function logRound(int $iteration, array $calls, array $results): void
    {
        Log::debug('heisenberg: ai tool round', [
            'iteration' => $iteration,
            'calls' => array_map(static fn (array $call): array => [
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => substr(json_encode($call['arguments'] ?? [], JSON_UNESCAPED_SLASHES) ?: '', 0, 200),
            ], $calls),
            'results' => array_map(static fn (AiMessage $m): array => [
                'bytes' => strlen($m->content),
                'isError' => $m->isError,
            ], $results),
        ]);
    }

    /** @param array<string, int> $usage */
    private function logSummary(int $rounds, int $totalCalls, array $usage, float $startedAt): void
    {
        Log::info('heisenberg: ai tool loop finished', [
            'rounds' => $rounds,
            'toolCalls' => $totalCalls,
            'usage' => $usage,
            'wallTimeMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}

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
use Heisenberg\Services\HeisenbergToolSource;

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

        for ($iteration = 0; $iteration < $max; $iteration++) {
            $response = $provider->complete($request->withTools($tools)->withMessages($messages));

            if ($response->isError() || ! $response->hasToolCalls()) {
                return $response;
            }

            // Replay the model's own turn so the results it gets back are
            // matched to the calls it made.
            $messages[] = AiMessage::toolRequest($response->text, $response->toolCalls);

            foreach ($response->toolCalls as $call) {
                $messages[] = $this->execute($call, $byName);
            }
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
                } elseif ($event->type === AiStreamEvent::DONE && $calls === [] && ! $failed) {
                    yield $event;
                }
            }

            if ($failed || $calls === []) {
                return;
            }

            // Replay the model's own turn so the results it gets back are
            // matched to the calls it made.
            $messages[] = AiMessage::toolRequest($text, $calls);

            foreach ($calls as $call) {
                // Surface the activity to the client BEFORE the (possibly slow) call runs —
                // without this the panel sits silent through every tool round and the whole
                // build looks like nothing is streaming.
                yield AiStreamEvent::toolUse(['name' => (string) ($call['name'] ?? '')]);
                $messages[] = $this->execute($call, $byName);
            }
        }

        yield AiStreamEvent::error(__('heisenberg::editor.ai.tool_loop_exhausted', ['max' => $max]));
    }

    /**
     * @param array{id: string, name: string, arguments: array} $call
     * @param array<string, McpServer>                          $byName
     */
    private function execute(array $call, array $byName): AiMessage
    {
        $name = (string) ($call['name'] ?? '');

        // In-process first — no server, no token, no socket.
        if ($this->local !== null && $this->local->owns($name)) {
            $result = $this->local->call($name, (array) ($call['arguments'] ?? []));

            return AiMessage::toolResult(
                (string) $call['id'],
                $name,
                (string) $result['content'],
                isError: (bool) $result['isError'],
            );
        }

        $server = $byName[$name] ?? null;

        if ($server === null) {
            // The model invented a tool, or named one it was never offered.
            return AiMessage::toolResult(
                (string) $call['id'],
                $name,
                "No such tool: '{$name}'.",
                isError: true,
            );
        }

        $bare = substr($name, strlen($server->id) + 2);
        $result = $this->mcp->callTool($server, $bare, (array) ($call['arguments'] ?? []));

        return AiMessage::toolResult(
            (string) $call['id'],
            $name,
            (string) $result['content'],
            isError: (bool) $result['isError'],
        );
    }
}

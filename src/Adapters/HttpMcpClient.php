<?php

declare(strict_types=1);

namespace Heisenberg\Adapters;

use Heisenberg\Ai\McpServer;
use Heisenberg\Contracts\McpClient;
use Illuminate\Support\Facades\Http;

/**
 * Talks to somebody else's MCP server over Streamable HTTP (JSON-RPC 2.0 POST).
 *
 * Heisenberg runs this loop itself rather than handing the server list to a
 * provider's built-in MCP connector, for two reasons: that connector is
 * Anthropic-only and this package ships two provider families, and a
 * provider-side connector cannot reach a server on localhost or inside a VPC.
 *
 * The token comes from the environment variable the server entry NAMES — see
 * {@see McpServer::token()}. A server whose variable is unset sends no
 * Authorization header at all rather than an empty one, which some servers
 * accept as anonymous and others reject confusingly.
 */
class HttpMcpClient implements McpClient
{
    private const PROTOCOL_VERSION = '2025-06-18';

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function listTools(McpServer $server): array
    {
        $result = $this->rpc($server, 'tools/list');

        $tools = [];
        foreach ((array) ($result['tools'] ?? []) as $tool) {
            $tool = (array) $tool;
            $name = (string) ($tool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $tools[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'input_schema' => (array) ($tool['inputSchema'] ?? ['type' => 'object']),
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array{content: string, isError: bool}
     */
    public function callTool(McpServer $server, string $tool, array $arguments): array
    {
        // Re-checked here rather than trusted from the caller: this is the last
        // gate before a third party's code runs on our behalf, and a model that
        // asks for an un-allowed tool must not be able to reach it through any
        // path that forgot to check.
        if (! $server->allows($tool)) {
            return ['content' => "Tool '{$tool}' is not allowed on server '{$server->id}'.", 'isError' => true];
        }

        try {
            $result = $this->rpc($server, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        } catch (\RuntimeException $e) {
            return ['content' => $e->getMessage(), 'isError' => true];
        }

        $text = [];
        foreach ((array) ($result['content'] ?? []) as $block) {
            $block = (array) $block;
            if (($block['type'] ?? '') === 'text') {
                $text[] = (string) ($block['text'] ?? '');
            }
        }

        return [
            'content' => implode("\n", $text),
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(McpServer $server, string $method, array $params = []): array
    {
        $headers = [
            'content-type' => 'application/json',
            // Some servers negotiate a stream; we only ever want the JSON body.
            'accept' => 'application/json, text/event-stream',
            'mcp-protocol-version' => self::PROTOCOL_VERSION,
        ];

        $token = $server->token();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('heisenberg.ai.mcp.client.timeout', 30))
                ->post($server->url, array_filter([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => $params === [] ? null : $params,
                ], static fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Couldn't reach MCP server '{$server->id}'.");
        }

        if ($response->failed()) {
            throw new \RuntimeException("MCP server '{$server->id}' returned HTTP {$response->status()}.");
        }

        $body = (array) $response->json();
        if (isset($body['error'])) {
            $message = (string) ($body['error']['message'] ?? 'unknown error');

            throw new \RuntimeException("MCP server '{$server->id}': {$message}");
        }

        return (array) ($body['result'] ?? []);
    }
}

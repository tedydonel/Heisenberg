<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Services\McpToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Heisenberg as an MCP server: JSON-RPC 2.0 over a single POST endpoint, so
 * Claude Code, Claude Desktop or any MCP-speaking agent can author pages here.
 *
 * A tools-only server needs neither the bidirectional SSE channel nor sampling,
 * which is why this is one route rather than a session protocol. Three methods
 * carry the whole surface: `initialize`, `tools/list`, `tools/call`.
 *
 * Authorization happened in {@see \Heisenberg\Http\Middleware\McpTokenMiddleware};
 * the tier it resolved is the only authority this controller consults. Content
 * safety is {@see McpToolRegistry}'s: every write runs the editor's own
 * validation pipeline.
 */
class McpServerController
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(private McpToolRegistry $tools)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);
        $tier = (string) $request->attributes->get('hb_mcp_tier', McpToolRegistry::TIER_READ);

        // A JSON-RPC notification (no id) expects no response body.
        if (! array_key_exists('id', $body) && str_starts_with($method, 'notifications/')) {
            return response()->json(null, 202);
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                // Tools only — no resources, no prompts, no sampling.
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'heisenberg', 'version' => $this->version()],
            ]),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->tools->listFor($tier)]),
            'tools/call' => $this->result($id, $this->tools->call(
                (string) ($params['name'] ?? ''),
                (array) ($params['arguments'] ?? []),
                $tier,
            )),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    private function version(): string
    {
        return (string) (config('heisenberg.version') ?? '0.1.0');
    }
}

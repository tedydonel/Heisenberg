<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Http\Middleware\McpTokenMiddleware;
use Heisenberg\Services\McpToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heisenberg as an MCP server: JSON-RPC 2.0 over the MCP **Streamable HTTP**
 * transport (spec 2025-06-18 §Base Protocol/Transports:
 * https://modelcontextprotocol.io/specification/2025-06-18/basic/transports),
 * so Claude Code, Claude Desktop or any MCP-speaking agent can author pages
 * here. The tool surface is unchanged from the original single-POST design —
 * three methods carry the whole surface (`initialize`, `tools/list`,
 * `tools/call`) — this class only had to become conformant with what the spec
 * requires of the *transport* around that surface:
 *
 * - A single MCP endpoint that answers both POST (here) and GET/DELETE (see
 *   {@see notAllowed()} and routes/mcp.php) — the spec requires the endpoint
 *   accept all three methods even from a server that only implements POST.
 * - `initialize` echoes back the client's requested `protocolVersion` when
 *   Heisenberg supports it, and otherwise its own latest, per the version
 *   negotiation rule in the Lifecycle spec
 *   (https://modelcontextprotocol.io/specification/2025-06-18/basic/lifecycle#version-negotiation).
 * - The `MCP-Protocol-Version` request header (required on every
 *   post-`initialize` request per the Transports spec) is validated: an
 *   unrecognised value is a 400, per spec. A missing header is tolerated (the
 *   spec's own backwards-compatibility fallback), because tool behaviour does
 *   not actually vary by negotiated version here — there is nothing to
 *   dispatch on.
 * - JSON-RPC *batching* (an array of messages in one POST) is accepted
 *   defensively for older (2025-03-26-and-earlier) clients, even though
 *   2025-06-18 removed the *requirement* to support it — accepting a batch
 *   never conflicts with a modern client, which will simply never send one.
 * - Malformed JSON is a transport-level failure (-32700, HTTP 400), not a
 *   tool-catalogue concern.
 *
 * What this class deliberately does NOT do, because the spec permits it:
 *
 * - **No `Mcp-Session-Id`.** Session IDs are a server-side MAY
 *   (https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#session-management):
 *   "A server ... MAY assign a session ID at initialization time." A tools-only
 *   server with no per-connection state to track (every tool call carries
 *   everything it needs — a post id, a tier resolved fresh from the bearer
 *   token — in its own arguments) has nothing a session would buy it, so it
 *   stays stateless: every POST is a self-contained JSON-RPC exchange, exactly
 *   as before.
 * - **No SSE stream.** The spec allows a server to answer every request with
 *   a single `application/json` object instead of opening
 *   `text/event-stream` ("the server MUST either return ... to initiate an
 *   SSE stream, or `Content-Type: application/json` ... The client MUST
 *   support both these cases.") Heisenberg's tools never emit progress
 *   notifications or server-initiated requests mid-call, so there is nothing
 *   an SSE stream would carry that the single JSON result does not already
 *   contain.
 *
 * `Origin` validation (a transport-security MUST) happens one layer up, in
 * {@see McpTokenMiddleware}, because it is a property of the *connection*,
 * not of any one JSON-RPC method — see that class for why.
 *
 * Authorization happened in {@see McpTokenMiddleware}; the tier it resolved is
 * the only authority this controller consults. Content safety is
 * {@see McpToolRegistry}'s: every write runs the editor's own validation
 * pipeline. Neither of those is touched by anything in this file.
 */
class McpServerController
{
    /**
     * Protocol versions this server understands, newest first. The first
     * entry is what `initialize` answers with when the client's requested
     * version isn't one Heisenberg recognises — the Lifecycle spec requires
     * the server's fallback to be "the latest version supported by the
     * server" (see the class docblock link).
     *
     * 2024-11-05 is listed for version-negotiation purposes only (a client
     * naming it gets it echoed back, per spec); this server was never a
     * dual-endpoint HTTP+SSE transport, which is the only wire-format
     * difference that protocol version actually implies, so no such client
     * exists in practice for a server this new.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const LATEST_PROTOCOL_VERSION = self::SUPPORTED_PROTOCOL_VERSIONS[0];

    public function __construct(private McpToolRegistry $tools)
    {
    }

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Not a JSON-RPC concern at all — the body never became a message we
            // could address by id, so `id` is null, exactly as JSON-RPC 2.0
            // prescribes for a Parse error.
            return $this->transportError(-32700, 'Parse error: ' . json_last_error_msg(), 400);
        }

        if (is_array($decoded) && array_is_list($decoded)) {
            if ($decoded === []) {
                return $this->transportError(-32600, 'Invalid Request: batch must not be empty', 400);
            }

            $messages = $decoded;
            $isBatch = true;
        } elseif (is_array($decoded)) {
            $messages = [$decoded];
            $isBatch = false;
        } else {
            return $this->transportError(-32600, 'Invalid Request: expected a JSON object or an array of them', 400);
        }

        // The client cannot know a negotiated version before `initialize` has
        // answered, so a lone `initialize` call is exempt from this check —
        // every other message (including a batch that happens to contain one)
        // is expected to carry it once a session is under way.
        $soleMethod = ! $isBatch ? (string) ($messages[0]['method'] ?? '') : null;
        if ($soleMethod !== 'initialize') {
            $headerVersion = $request->header('MCP-Protocol-Version');
            if ($headerVersion !== null && ! in_array($headerVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
                return $this->transportError(-32600, "Unsupported MCP-Protocol-Version: {$headerVersion}", 400);
            }
        }

        $responses = [];
        foreach ($messages as $message) {
            $response = $this->dispatch($request, $message);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // A pure notification (or an all-notification batch) gets no body at
        // all: 202 Accepted, per the Transports spec's rule for a JSON-RPC
        // *notification* or *response* POSTed to the endpoint. `response()->json(null, ...)`
        // would NOT do this — Symfony's JsonResponse turns a null payload into
        // `{}` (see its constructor's `$data ??= new \ArrayObject()`) — so this
        // builds a genuinely empty-bodied response instead.
        if ($responses === []) {
            return response('', 202);
        }

        return response()->json($isBatch ? $responses : $responses[0]);
    }

    /**
     * GET and DELETE are the other two methods the Streamable HTTP transport
     * requires the MCP endpoint to accept (see routes/mcp.php) — GET to open a
     * server-initiated SSE stream, DELETE to end a session. Heisenberg offers
     * neither (see the class docblock), and the spec names 405 as exactly the
     * right answer for a server that doesn't:
     *
     * - GET: "The server MUST either return `Content-Type: text/event-stream`
     *   ... or else return HTTP 405 Method Not Allowed, indicating that the
     *   server does not offer an SSE stream at this endpoint."
     * - DELETE: "The server MAY respond to this request with HTTP 405 Method
     *   Not Allowed, indicating that the server does not allow clients to
     *   terminate sessions."
     */
    public function notAllowed(Request $request): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Method Not Allowed: this MCP endpoint is stateless — it never opens a '
                    . 'server-initiated SSE stream and has no session to terminate. Use POST.',
            ],
        ], 405)->header('Allow', 'POST');
    }

    /**
     * Routes one decoded JSON-RPC message to its handler. Returns null for a
     * notification (no response is ever sent for one — JSON-RPC 2.0 forbids
     * it), or the JSON-RPC response/error object otherwise.
     *
     * @param array<string, mixed>|mixed $message a single already-JSON-decoded message
     */
    private function dispatch(Request $request, mixed $message): ?array
    {
        if (! is_array($message)) {
            // A batch entry that isn't itself an object — id is unknowable.
            return $this->errorArray(null, -32600, 'Invalid Request: expected a JSON object');
        }

        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);
        $tier = (string) $request->attributes->get('hb_mcp_tier', McpToolRegistry::TIER_READ);

        // A JSON-RPC notification (no id) expects no response body.
        if (! array_key_exists('id', $message) && str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->resultArray($id, [
                'protocolVersion' => $this->negotiatedProtocolVersion((string) ($params['protocolVersion'] ?? '')),
                // Tools only — no resources, no prompts, no sampling.
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'heisenberg', 'version' => $this->version()],
            ]),
            'ping' => $this->resultArray($id, new \stdClass()),
            'tools/list' => $this->resultArray($id, ['tools' => $this->tools->listFor($tier)]),
            'tools/call' => $this->resultArray($id, $this->tools->call(
                (string) ($params['name'] ?? ''),
                (array) ($params['arguments'] ?? []),
                $tier,
            )),
            default => $this->errorArray($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * The Lifecycle spec's version-negotiation rule: echo the client's
     * requested version back when Heisenberg supports it; otherwise answer
     * with the latest version Heisenberg does support. A client that doesn't
     * support what comes back "SHOULD disconnect" — that's a client-side
     * decision the spec leaves alone, not something this server can or should
     * pre-empt.
     */
    private function negotiatedProtocolVersion(string $requested): string
    {
        return in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $requested : self::LATEST_PROTOCOL_VERSION;
    }

    /** @param array<string, mixed>|object $result */
    private function resultArray(mixed $id, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function errorArray(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * A transport-level failure — the body never resolved to an addressable
     * JSON-RPC message at all, so there is no per-message id to reply to and
     * no tool-call semantics involved. Unlike an in-band JSON-RPC error (a
     * 200 carrying `error`, e.g. "method not found"), this is reported with a
     * real HTTP error status, per the Streamable HTTP transport's own
     * language for input the server "cannot accept."
     */
    private function transportError(int $code, string $message, int $status): JsonResponse
    {
        return response()->json($this->errorArray(null, $code, $message), $status);
    }

    private function version(): string
    {
        return (string) (config('heisenberg.version') ?? '0.1.0');
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Http\Middleware;

use Closure;
use Heisenberg\Services\McpToolRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the inbound MCP server.
 *
 * Tokens live in ONE environment variable (config:
 * heisenberg.ai.mcp.server.tokens_env) as `token:tier,token:tier`. Tiers are
 * {@see McpToolRegistry}'s — `read`, `authors`, `admins` — so an integration can
 * be handed a read-only token without also being able to write posts.
 *
 * Three properties this deliberately has:
 *
 * - **Constant-time comparison** over every configured token, with no early
 *   return on the first mismatch, so response timing does not leak how much of a
 *   guessed token was right.
 * - **Nothing is logged or echoed.** The 401 body names no token and no env var.
 * - **Session-free.** This endpoint is mounted outside the `web` group on
 *   purpose (see routes/mcp.php), so there is no cookie, no CSRF token and no
 *   ambient user — the token is the entire identity.
 *
 * Two additions for MCP Streamable HTTP transport conformance
 * (https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#security-warning),
 * both scoped to this class because they are properties of the *connection*,
 * not of any one JSON-RPC method:
 *
 * - **`Origin` validation.** The spec: "Servers MUST validate the `Origin`
 *   header on all incoming connections to prevent DNS rebinding attacks."
 *   {@see originIsForeign()} for what "foreign" means here and why a bearer
 *   token alone doesn't already cover this case.
 * - **`WWW-Authenticate` on 401.** Bog-standard RFC 6750 bearer-auth
 *   practice, not an MCP-specific requirement (Heisenberg's static
 *   `token:tier` scheme is the spec's own "custom authentication" escape
 *   hatch, not its OAuth-based Authorization framework — there is no
 *   protected-resource metadata to advertise here). Costs nothing and lets a
 *   conformant HTTP client distinguish "no credential was sent" from "the one
 *   sent was rejected."
 */
class McpTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('heisenberg.ai.mcp.server.enabled', false)) {
            abort(404);
        }

        if ($this->originIsForeign($request)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32000, 'message' => 'Forbidden: cross-origin request'],
                'id' => null,
            ], 403);
        }

        $presented = $this->presentedToken($request);
        $tier = $this->tierFor($presented);
        if ($tier === null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                'id' => null,
            ], 401)->header('WWW-Authenticate', $this->challenge($presented));
        }

        // The tools layer reads this; there is no other source of authority.
        $request->attributes->set('hb_mcp_tier', $tier);

        return $next($request);
    }

    /**
     * Every real MCP HTTP client (curl, the TypeScript/Python SDKs, Claude
     * Code) is a server-to-server caller and never sends an `Origin` header —
     * only browsers do. So the check that matters is narrow: when an `Origin`
     * IS present, it must name this same host. That closes exactly the gap a
     * bearer token doesn't: a token that has leaked into a browser context
     * (e.g. pasted into devtools, or held by a compromised extension) could
     * otherwise be replayed from a malicious page via a same-site-cookie-free
     * `fetch()`, which is precisely the DNS-rebinding-style shape the spec's
     * security warning is about. A request with no `Origin` header at all
     * passes through unconditionally.
     */
    private function originIsForeign(Request $request): bool
    {
        $origin = (string) $request->header('Origin', '');
        if ($origin === '') {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);

        return ! is_string($originHost) || $originHost === ''
            || ! hash_equals(strtolower($request->getHost()), strtolower($originHost));
    }

    /** RFC 6750 §3: `error="invalid_token"` only once a credential was actually presented. */
    private function challenge(string $presented): string
    {
        return $presented === ''
            ? 'Bearer realm="heisenberg-mcp"'
            : 'Bearer realm="heisenberg-mcp", error="invalid_token"';
    }

    private function presentedToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');

        return preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1 ? trim($m[1]) : '';
    }

    /** The tier this token grants, or null when it matches nothing. */
    private function tierFor(string $presented): ?string
    {
        if ($presented === '') {
            return null;
        }

        $env = (string) config('heisenberg.ai.mcp.server.tokens_env', '');
        $raw = $env !== '' ? env($env) : null;
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $granted = null;
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $split = strrpos($entry, ':');
            $token = $split === false ? $entry : substr($entry, 0, $split);
            $tier = $split === false ? McpToolRegistry::TIER_READ : substr($entry, $split + 1);

            // No `break` on a hit: every configured token is compared so the
            // work done is independent of which one (if any) matched.
            if (hash_equals($token, $presented) && in_array($tier, [
                McpToolRegistry::TIER_READ,
                McpToolRegistry::TIER_AUTHORS,
                McpToolRegistry::TIER_ADMINS,
            ], true)) {
                $granted = $tier;
            }
        }

        return $granted;
    }
}

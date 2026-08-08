<?php

declare(strict_types=1);

namespace Heisenberg\Http\Middleware;

use Heisenberg\Services\McpToolRegistry;
use Closure;
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
 */
class McpTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('heisenberg.ai.mcp.server.enabled', false)) {
            abort(404);
        }

        $tier = $this->tierFor($this->presentedToken($request));
        if ($tier === null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                'id' => null,
            ], 401);
        }

        // The tools layer reads this; there is no other source of authority.
        $request->attributes->set('hb_mcp_tier', $tier);

        return $next($request);
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

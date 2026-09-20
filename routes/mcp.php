<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\McpServerController;
use Heisenberg\Http\Middleware\McpTokenMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg inbound MCP server (opt-in, OFF by default)
|--------------------------------------------------------------------------
| Loaded by the service provider when config('heisenberg.ai.mcp.server.enabled')
| is true. It ships DISABLED on purpose: this is a write API that lets an
| external agent create and edit posts, so turning it on is a deliberate act,
| not a default.
|
| `middleware.mcp` is deliberately EMPTY rather than `['web']`. The endpoint
| authenticates with a bearer token, not a session — putting it in the web group
| would subject every call to session-cookie handling and CSRF verification,
| which an MCP client neither has nor can obtain. McpTokenMiddleware is the
| entire gate, and it 404s when the feature is off so a disabled server does not
| even advertise its existence.
|
| One POST route carries the whole JSON-RPC surface: initialize, tools/list
| and tools/call need no session and no per-client state (see
| McpServerController's docblock for why that is spec-legal). GET and DELETE
| exist only because the MCP Streamable HTTP transport requires the single
| "MCP endpoint" to answer all three HTTP methods — GET to open a
| server-initiated SSE stream, DELETE to end a session — even from a server
| that offers neither; McpServerController::notAllowed() answers both with the
| 405 the spec names for exactly that case. All three share the same
| middleware stack so a disabled server 404s and an Origin/token failure is
| rejected identically regardless of HTTP method.
*/
Route::middleware(array_merge(
    (array) config('heisenberg.middleware.mcp', []),
    [McpTokenMiddleware::class],
))->group(function (): void {
    $path = '/' . ltrim((string) config('heisenberg.ai.mcp.server.path', 'heisenberg/mcp'), '/');

    Route::post($path, [McpServerController::class, 'handle'])->name('heisenberg.mcp');
    Route::get($path, [McpServerController::class, 'notAllowed'])->name('heisenberg.mcp.get');
    Route::delete($path, [McpServerController::class, 'notAllowed'])->name('heisenberg.mcp.delete');
});

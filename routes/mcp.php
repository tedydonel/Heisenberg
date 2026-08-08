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
| One route, because a tools-only MCP server is JSON-RPC over a single POST:
| initialize, tools/list and tools/call need no session, no SSE channel and no
| per-client state.
*/
Route::middleware(array_merge(
    (array) config('heisenberg.middleware.mcp', []),
    [McpTokenMiddleware::class],
))
    ->post('/' . ltrim((string) config('heisenberg.ai.mcp.server.path', 'heisenberg/mcp'), '/'), [McpServerController::class, 'handle'])
    ->name('heisenberg.mcp');

<?php

declare(strict_types=1);

use Heisenberg\Http\Controllers\AiController;
use Heisenberg\Http\Controllers\AiMcpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Heisenberg AI assistant routes (opt-in)
|--------------------------------------------------------------------------
| Loaded by the service provider when config('heisenberg.ai.routes') is true
| (default). Its own config keys (heisenberg.ai.routes /
| heisenberg.middleware.ai) so a host can mount the editor without the AI
| surface, or protect the two differently — the same independence the media
| library has from the editor.
|
| This group is the panel's OWN backend (settings today; completion and
| streaming in the next phase). It is NOT the inbound MCP server: that is a
| bearer-token API for other AIs and lives in routes/mcp.php under an empty
| middleware stack, deliberately outside `web`'s session/CSRF handling.
|
| Reads here are open; the write carries an admins-tier RoleGate check inside
| AiController (see its docblock) regardless of how middleware.ai is set.
*/
Route::middleware(config('heisenberg.middleware.ai', ['web']))
    ->prefix('editor/ai')
    ->name('heisenberg.editor.ai.')
    ->group(function (): void {
        Route::get('/settings', [AiController::class, 'show'])->name('settings.show');
        Route::put('/settings', [AiController::class, 'update'])->name('settings.update');

        // Credentials are WRITE-ONLY: this stores or clears a provider's key and
        // there is deliberately no companion GET. The settings payload reports
        // `has_key` booleans instead, so an operator can see what is set without
        // the value ever being readable back through the API.
        Route::put('/providers/{provider}/key', [AiController::class, 'putKey'])
            ->where('provider', '[a-z0-9-]+')->name('providers.key');

        // Ask the provider's own endpoint what models it serves, rather than
        // shipping a catalogue that goes stale the week a model launches.
        Route::post('/providers/{provider}/discover', [AiController::class, 'discoverModels'])
            ->where('provider', '[a-z0-9-]+')->name('providers.discover');

        // Probe an MCP server for its tool list — admins-tier, read-only.
        Route::post('/mcp/test', [AiMcpController::class, 'test'])->name('mcp.test');

        // Model-calling endpoints. Throttled because each request spends the
        // operator's API budget: without a limit, one stuck client retrying in a
        // loop is a bill. Both also carry an authors-tier check inside the
        // controller, on top of whatever middleware.ai is set to.
        //
        // The throttle is skippable (heisenberg.ai.rate_limit = null) because it
        // is the one thing in this package that requires a working cache store —
        // a host on the `database` cache driver without the `cache` table would
        // otherwise get a 500 from these two routes and nowhere else.
        $rateLimit = config('heisenberg.ai.rate_limit', '30,1');
        $model = function (): void {
            Route::post('/complete', [AiController::class, 'complete'])->name('complete');
            Route::post('/stream', [AiController::class, 'stream'])->name('stream');
        };

        if (is_string($rateLimit) && $rateLimit !== '') {
            Route::middleware("throttle:{$rateLimit}")->group($model);
        } else {
            $model();
        }
    });

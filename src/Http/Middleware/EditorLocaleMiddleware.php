<?php

declare(strict_types=1);

namespace Heisenberg\Http\Middleware;

use Closure;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Apply the editor's UI locale to the request.
 *
 * Reads `heisenberg.locale` from the session (set by
 * `Heisenberg\Http\Controllers\LocaleController::switch`) and calls
 * `App::setLocale()` so every `__()` / `trans()` call rendered on this
 * request resolves against that locale's lang file.
 *
 * Registered globally in `HeisenbergServiceProvider::boot()` AFTER the
 * `web` middleware group (which guarantees the session exists), so it
 * has access to session state on every editor request.
 *
 * Locale whitelist: `Heisenberg\Support\LocaleConfig::locales()` (`heisenberg.locales`, falling
 * back to `heisenberg.editor.locales`, docs/content-translation.md §3) — same source
 * `LocaleController::switch()` validates against, so a session value that passed that switch
 * always passes here too.
 */
final class EditorLocaleMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('heisenberg.locale');

        if (is_string($locale) && LocaleConfig::isValid($locale)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
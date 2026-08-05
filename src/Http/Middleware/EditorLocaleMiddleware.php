<?php

declare(strict_types=1);

namespace Heisenberg\Http\Middleware;

use Closure;
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
 */
final class EditorLocaleMiddleware
{
    /** Locales shipped with the package. Must match LocaleController::LOCALES. */
    private const LOCALES = ['en', 'fr'];

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('heisenberg.locale');

        if (is_string($locale) && in_array($locale, self::LOCALES, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
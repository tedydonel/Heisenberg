<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Locale switcher for the editor surface.
 *
 * Heisen- berg loads `resources/lang/{en|fr}/editor.php` under the
 * `heisenberg` namespace; this controller flips the active locale
 * (persisted in the session) and bounces the caller back to the editor
 * page so the whole shell re-renders through `__()` with the new locale.
 *
 * Hosts that want to extend this (e.g. additional locales) widen the
 * whitelist here; the brief ships `en` and `fr` only.
 */
final class LocaleController
{
    /** Locales shipped with the package. */
    private const LOCALES = ['en', 'fr'];

    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, self::LOCALES, true)) {
            abort(404);
        }

        $request->session()->put('heisenberg.locale', $locale);

        return redirect()->to(
            $request->session()->pull('heisenberg.locale_return', $request->headers->get('referer') ?? '/editor')
        );
    }
}
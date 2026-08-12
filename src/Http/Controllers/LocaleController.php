<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Support\LocaleConfig;
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
 * Hosts that want to extend this (e.g. additional locales) widen the whitelist via
 * `heisenberg.locales` (docs/content-translation.md §3) — see `Heisenberg\Support\LocaleConfig`,
 * the single source every locale-aware surface (this controller, EditorLocaleMiddleware,
 * TranslationStatusService, McpToolRegistry) reads through. The `LOCALES` constant this class
 * used to hardcode its own whitelist in is gone; `LocaleConfig` owns the last-resort default now.
 */
final class LocaleController
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, LocaleConfig::locales(), true)) {
            abort(404);
        }

        $request->session()->put('heisenberg.locale', $locale);

        return redirect()->to($this->safeReturnUrl($request));
    }

    /**
     * The footer's switcher form submits the current page as `return`. Accept it only
     * when it is a site-relative path or an absolute URL on this host — anything else
     * would be an open redirect. Falls back to the Referer, then /editor.
     */
    private function safeReturnUrl(Request $request): string
    {
        $candidate = trim((string) $request->input('return', ''));

        if ($candidate !== '') {
            if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//') && ! str_starts_with($candidate, '/\\')) {
                return $candidate;
            }
            $host = parse_url($candidate, PHP_URL_HOST);
            if (is_string($host) && strcasecmp($host, $request->getHost()) === 0) {
                return $candidate;
            }
        }

        return (string) ($request->headers->get('referer') ?? '/editor');
    }
}
<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * Single source of truth for "which locales does this install support"
 * (docs/content-translation.md §3). Every locale-aware surface —
 * `LocaleController`, `EditorLocaleMiddleware`, `TranslationStatusService`,
 * `McpToolRegistry`'s `locale` argument validation — reads through here
 * instead of hardcoding `['en', 'fr']` or reading config directly, so the
 * precedence rule lives in exactly one place.
 *
 * Precedence: `config('heisenberg.locales')` wins whenever it is a non-empty
 * array — that is the current key (config/heisenberg.php, added alongside
 * this class). `config('heisenberg.editor.locales')` is read only as a
 * fallback, for a host that overrode the old key and hasn't migrated to the
 * new one yet. `['en', 'fr']` is the last-resort default if neither is set.
 */
final class LocaleConfig
{
    /** @return list<string> */
    public static function locales(): array
    {
        $locales = config('heisenberg.locales');

        if (! is_array($locales) || $locales === []) {
            $locales = config('heisenberg.editor.locales');
        }

        if (! is_array($locales) || $locales === []) {
            $locales = ['en', 'fr'];
        }

        return array_values(array_map('strval', $locales));
    }

    public static function default(): string
    {
        $default = config('heisenberg.default_locale');

        return is_string($default) && $default !== '' ? $default : 'en';
    }

    public static function isValid(string $locale): bool
    {
        return in_array($locale, self::locales(), true);
    }
}

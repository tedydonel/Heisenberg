<?php

declare(strict_types=1);

namespace Heisenberg\Support;

/**
 * Pure, dependency-free helpers over a block's `attributes` map for the single-row translation
 * model (docs/content-translation.md §0): human-language content lives in locale-suffixed
 * attribute variants (`content_en`, `content_fr`, …) on the SAME block instance, resolved
 * `key_<locale>` first, then bare `key`.
 *
 * LOCKSTEP with {@see \Heisenberg\Services\BlockRenderer::localizedAttribute()} — that method
 * owns the render-time resolution (and stays untouched, per this wave's brief); this class gives
 * every OTHER caller (the merge command, `TranslationStatusService`, the future editor wave) the
 * same read/write/discover primitives without reaching into a block array by hand. No Laravel
 * facades, no container — plain arrays in, plain arrays/scalars out, so it is trivial to unit test
 * and safe to call from a console command.
 */
final class LocalizedAttributes
{
    private function __construct()
    {
        // Static-only.
    }

    /**
     * Read `$key`'s value for `$locale`: `key_<locale>` first, then bare `key`, then null.
     * Mirrors `BlockRenderer::localizedAttribute()` exactly, except it returns `null` (not `''`)
     * when neither variant is set — callers here need to distinguish "no value" from "empty
     * string", which the renderer (always about to concatenate into HTML) does not.
     */
    public static function read(array $attributes, string $key, string $locale): mixed
    {
        $suffixed = $key . '_' . $locale;
        if (array_key_exists($suffixed, $attributes)) {
            return $attributes[$suffixed];
        }

        return $attributes[$key] ?? null;
    }

    /**
     * Write `$value` for `$key`/`$locale`, returning a NEW attributes array (the input is never
     * mutated in place). Always writes the locale-suffixed variant (`key_<locale>`) — never the
     * bare key — so a write never silently changes what a DIFFERENT locale resolves to.
     */
    public static function write(array $attributes, string $key, string $locale, mixed $value): array
    {
        $attributes[$key . '_' . $locale] = $value;

        return $attributes;
    }

    /**
     * True when a read() result counts as "has content" — a non-empty string/array, or any
     * non-null scalar. Empty string, empty array, and null all count as "no content".
     */
    public static function hasContent(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * Which of `$candidateLocales` this BLOCK has content for, across every attribute in
     * `$translatableKeys` — a locale counts only when EVERY listed attribute has content for it
     * (a block with `content` translated but `titleAttr` still empty is not "done" for that
     * locale). Empty `$translatableKeys` (a block with nothing translatable, e.g. a separator)
     * returns `$candidateLocales` unchanged — vacuously true, nothing to withhold completeness on.
     *
     * Only ATTRIBUTES THE AUTHOR ACTUALLY USED gate completeness — a translatable key whose bare
     * (unsuffixed) value is empty is skipped entirely, never demanded in any locale. This matters
     * because most translatable keys are optional (`titleAttr`, a tooltip, is translatable on
     * every block but empty on nearly all of them): without this filter, an unused optional field
     * would make a block read as "untranslated" forever — including in its OWN home locale, which
     * is nonsensical.
     *
     * `$homeLocale`, when given, is the ONE locale allowed to satisfy an active key via its bare
     * (unsuffixed) value — normally the post's own `locale` column: a block authored before any
     * translation exists has no `key_<locale>` suffix at all yet, and that bare text IS the home
     * locale's own content, not a stand-in for every locale. Every OTHER candidate locale must
     * have its own explicit suffixed variant. This is DELIBERATELY NOT {@see self::read()}'s
     * fallback-to-bare behavior (which BlockRenderer relies on for rendering, so something always
     * shows): reusing that fallback here would make a never-translated block read as "100%
     * translated" into every locale, since the fallback always resolves to the bare value. Pass
     * `null` (the default) for a strict check where no locale gets the bare-value exemption.
     *
     * `$block` is a block-instance array (`{name, attributes, innerBlocks?}` or just its
     * `attributes` map — either is accepted, see {@see self::attributesOf()}).
     *
     * @param string[] $translatableKeys
     * @param string[] $candidateLocales
     * @return string[]
     */
    public static function locales(array $block, array $translatableKeys, array $candidateLocales, ?string $homeLocale = null): array
    {
        $attributes = self::attributesOf($block);

        $activeKeys = array_values(array_filter(
            $translatableKeys,
            static fn (string $key): bool => self::hasContent($attributes[$key] ?? null)
        ));

        if ($activeKeys === []) {
            return $candidateLocales;
        }

        return array_values(array_filter(
            $candidateLocales,
            static function (string $locale) use ($attributes, $activeKeys, $homeLocale): bool {
                foreach ($activeKeys as $key) {
                    $suffixed = $key . '_' . $locale;
                    if (array_key_exists($suffixed, $attributes)) {
                        if (! self::hasContent($attributes[$suffixed])) {
                            return false;
                        }
                        continue;
                    }

                    // No explicit variant for this locale: only the designated home locale may
                    // fall back to the (already-known-non-empty) bare value.
                    if ($locale !== $homeLocale) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /** Accepts either a full block instance (`{attributes: {...}}`) or a bare attributes map. */
    private static function attributesOf(array $block): array
    {
        if (isset($block['attributes']) && is_array($block['attributes'])) {
            return $block['attributes'];
        }

        return $block;
    }
}

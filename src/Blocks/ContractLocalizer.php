<?php

declare(strict_types=1);

namespace Heisenberg\Blocks;

use Heisenberg\Services\BlockRegistryService;
use Illuminate\Support\Str;

/**
 * Localization primitives shared across the registry's contract-derivation
 * pipeline (§3.4, docs/content-translation.md §0). Extracted verbatim from
 * {@see BlockRegistryService}: translating a namespaced
 * lang string, resolving a lang key with a humanized fallback, the block-slug
 * and enum-option-key conventions those lookups are keyed by, and the list of
 * a contract's translatable attribute names.
 */
final class ContractLocalizer
{
    /** Translatable string namespace gate for {@see localize()}. */
    private const TRANSLATION_NAMESPACE = 'heisenberg::';

    /**
     * Attribute names this contract marks `"translatable": true` (docs/content-translation.md
     * §0) — the human-language attributes whose value lives in locale-suffixed variants
     * (`content_en`, `content_fr`, …). Empty for a contract with none declared (most
     * container/design blocks).
     *
     * @return string[]
     */
    public function translatableAttributesOf(array $contract): array
    {
        $attributes = $contract['attributes'] ?? null;
        if (! is_array($attributes)) {
            return [];
        }

        $out = [];
        foreach ($attributes as $attribute => $def) {
            if (is_array($def) && ($def['translatable'] ?? false) === true) {
                $out[] = (string) $attribute;
            }
        }

        return $out;
    }

    /** Localize a lang key; fall back to a humanized label when it doesn't resolve. */
    public function localizeLabel(string $key, string $fallbackSource, ?string $locale): string
    {
        $translated = __($key, [], $locale);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return Str::headline($fallbackSource);
    }

    public function localize(mixed $value, ?string $locale): mixed
    {
        if (is_string($value) && str_starts_with($value, self::TRANSLATION_NAMESPACE)) {
            return __($value, [], $locale);
        }

        return $value;
    }

    /** Block slug as used in lang keys (`heisenberg/section-head` → `section_head`). */
    public function langSlug(string $name): string
    {
        $slug = str_contains($name, '/') ? explode('/', $name, 2)[1] : $name;

        return str_replace('-', '_', $slug);
    }

    /** Normalize an enum value to its lang key: `_self`→`self`, `wide-line`→`wide_line`. */
    public function optionKey(mixed $value): string
    {
        return Str::snake(str_replace('-', '_', ltrim((string) $value, '_')));
    }
}

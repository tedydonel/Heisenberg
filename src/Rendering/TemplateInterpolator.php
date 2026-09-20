<?php

declare(strict_types=1);

namespace Heisenberg\Rendering;

use Heisenberg\Services\BlockRenderer;

/**
 * `{{ ... }}` template-token resolution and locale-aware attribute lookup —
 * extracted verbatim from {@see BlockRenderer}. Pure and
 * stateless: every method reads only its arguments, so this collaborator has
 * no constructor dependencies and is safe to share across every other
 * renderer collaborator that needs to resolve a token.
 */
final class TemplateInterpolator
{
    public function __construct(
        private DataPath $dataPath = new DataPath(),
    ) {
    }

    /** Locale-aware attribute lookup: `key_<locale>` then bare `key`. */
    public function localizedAttribute(array $block, string $key, string $locale): mixed
    {
        $attributes = $block['attributes'] ?? [];
        if (! is_array($attributes)) {
            return '';
        }

        if (array_key_exists($key . '_' . $locale, $attributes)) {
            return $attributes[$key . '_' . $locale];
        }

        return $attributes[$key] ?? '';
    }

    /** Resolve {{ id }}, {{ name }}, {{ attributes.X }} (locale-aware), {{ supports.X }}, {{ lang.X }}. */
    public function substitute(string $value, array $block, string $locale): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $m) use ($block, $locale): string {
                $token = $m[1];

                if ($token === 'id') {
                    return (string) ($block['id'] ?? '');
                }
                if ($token === 'name') {
                    return (string) ($block['name'] ?? '');
                }
                if (str_starts_with($token, 'attributes.')) {
                    return $this->scalarToString($this->localizedAttribute($block, substr($token, 11), $locale));
                }
                if (str_starts_with($token, 'supports.')) {
                    return $this->scalarToString($this->dataPath->get($block['supports'] ?? [], substr($token, 9)));
                }
                if (str_starts_with($token, 'lang.')) {
                    return $this->localizedString(substr($token, 5), $locale);
                }

                return '';
            },
            $value
        ) ?? $value;
    }

    /**
     * `{{ lang.blocks.embed.unsupported }}` -> the package translation
     * `heisenberg::blocks.embed.unsupported` for the render locale. Lets a contract ship
     * a user-facing STRING (an empty/unsupported state, a fallback caption) without
     * hard-coding English into the template the way an author-controlled `title=` does.
     *
     * Fail-quiet by design: an unknown key resolves to '' rather than leaking the raw
     * key into the page, and the key charset is bounded so a contract can only ever
     * reach a lang file, never an arbitrary container binding. The result is escaped by
     * the caller (text nodes) or by buildAttributes() (attribute values) like any other
     * substituted value. NOTE the canvas mirror's subst() has no translator and yields
     * '' here — a contract using this token must stay legible with an empty string
     * (embed.css does that with a `:empty::before` fallback label).
     */
    public function localizedString(string $key, string $locale): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/i', $key) !== 1) {
            return '';
        }

        $full = 'heisenberg::' . $key;
        $translated = __($full, [], $locale);

        return is_string($translated) && $translated !== $full ? $translated : '';
    }

    public function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}

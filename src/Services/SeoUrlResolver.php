<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Support\LocaleConfig;

/**
 * The bundled default {@see PostUrlResolver} — ONE definition of "a post's public URL per locale"
 * (docs/seo-system.md §5), resolved from the container by both {@see SitemapController} and
 * {@see \Heisenberg\Http\Controllers\PreviewController}'s hreflang alternates (never referenced by
 * concrete class from either caller) — both need the exact same answer or the sitemap and the
 * page's own `<link rel="alternate">` tags would disagree about a post's canonical public address.
 * A host that needs a URL shape this class cannot express (per-locale domains, id-based URLs,
 * anything reaching into its own route helpers) binds `heisenberg.seo.url_resolver` to its own
 * {@see PostUrlResolver} implementation instead — this class stays the fallback for everyone else.
 *
 * `config('heisenberg.seo.url_template')` wins when set, in either of two shapes:
 *
 *  - a STRING, `{locale}`/`{slug}` placeholders, e.g. `https://example.com/{locale}/blog/{slug}` —
 *    substituted for every locale alike (the original, back-compat shape).
 *  - a MAP keyed by locale, e.g.
 *    `['en' => 'https://example.com/blog/{slug}', 'fr' => 'https://example.com/fr/blog/{slug}']` —
 *    lets a host give its default locale an UNPREFIXED URL while other locales carry a prefix (or
 *    express any other per-locale irregularity), something one global template cannot say. The
 *    post's own locale picks the entry; `{locale}`/`{slug}` still substitute in whichever template
 *    string is chosen, so a map entry may itself still use `{locale}` if the host wants it.
 *    Lookup order for the entry: the post's locale, then a `'*'` catch-all key if present, then
 *    the `heisenberg.default_locale` key if present; if none of those exist, this falls through
 *    to the same dev-default preview route as an unset `url_template`.
 *
 * `null` (the default) — or a map with no matching entry — falls back to this package's own
 * post-scoped preview route (`GET /editor/{post}/preview`) — a DEV DEFAULT ONLY: it is gated
 * behind `config('heisenberg.middleware.editor')` (open by default, `['web']`) rather than a real
 * public route, and reveals the `/editor` prefix. A host publishing a real sitemap MUST set
 * `heisenberg.seo.url_template` (docs/seo-system.md §5's own wording — "so hosts map it to their
 * real blog routes").
 */
class SeoUrlResolver implements PostUrlResolver
{
    public function url(Post $post): string
    {
        $template = config('heisenberg.seo.url_template');
        $locale = (string) ($post->locale ?: LocaleConfig::default());

        if (is_string($template) && trim($template) !== '') {
            return $this->substitute($template, $locale, $post);
        }

        if (is_array($template) && $template !== []) {
            $entry = $this->resolveMapEntry($template, $locale);
            if ($entry !== null) {
                return $this->substitute($entry, $locale, $post);
            }
        }

        return route('heisenberg.editor.preview.post', ['post' => $post->getKey()]);
    }

    /** @param array<string, mixed> $map */
    private function resolveMapEntry(array $map, string $locale): ?string
    {
        foreach ([$locale, '*', LocaleConfig::default()] as $key) {
            $value = $map[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function substitute(string $template, string $locale, Post $post): string
    {
        return strtr($template, [
            '{locale}' => $locale,
            '{slug}' => (string) $post->slug,
        ]);
    }
}

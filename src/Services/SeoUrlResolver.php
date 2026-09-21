<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Http\Controllers\PreviewController;
use Heisenberg\Models\Post;
use Heisenberg\Support\LocaleConfig;
use Heisenberg\Support\SiteUrl;
use Illuminate\Support\Facades\Route;

/**
 * The bundled default {@see PostUrlResolver} — ONE definition of "a post's public URL per locale"
 * (docs/seo-system.md §5), resolved from the container by both {@see SitemapController} and
 * {@see PreviewController}'s hreflang alternates (never referenced by
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
 * `null` (the default) — or a map with no matching entry — resolves to the bundled public route
 * (`GET /posts/{locale}/{slug}`) when the host has enabled it via `heisenberg.public.routes`.
 * With neither a template nor the public route, it falls back to this package's own
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

        // A host that turned on the bundled public route (`heisenberg.public.routes`) has
        // already told us where posts live — without this, enabling it still left the
        // sitemap, canonical and hreflang links pointing at the editor preview below until
        // `url_template` was ALSO set by hand.
        if (Route::has('heisenberg.public.posts.show') && (string) $post->slug !== '') {
            // route() builds the host from the CURRENT request, which is the editor's host —
            // an admin/staff subdomain in most installs. The path is right, the host is not,
            // so rebase onto the configured public site when the host has named one.
            return SiteUrl::rebase(route('heisenberg.public.posts.show', ['locale' => $locale, 'slug' => $post->slug]));
        }

        // Deliberately NOT rebased: this is the dev-only editor preview route. Moving it onto
        // the public site would produce a confident-looking URL that 404s there, and the SEO
        // panel's "/editor/ means not a real URL" check would stop catching it.

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

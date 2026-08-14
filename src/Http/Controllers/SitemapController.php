<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Services\TranslationStatusService;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * `GET /sitemap.xml` (docs/seo-system.md §5) — one `<url>` per published, sitemap-eligible post
 * PER LOCALE it is readable in, each carrying `<xhtml:link rel="alternate" hreflang>` entries for
 * the other locales plus `x-default`. Under the single-row model (docs/content-translation.md §0)
 * a post is one row in several languages, so the per-locale URLs come from resolving that same row
 * once per locale rather than from separate sibling rows — which is what this emitted before the
 * model changed, and why a fully translated post briefly appeared here exactly once with no
 * alternates at all.
 *
 * Eligibility (a row is IN the sitemap when):
 *  - `type === 'post'` (docs/email-system.md §3 — an email document has no public URL)
 *  - `status === 'published'`
 *  - no `SeoMeta` row at all (nothing overriding the defaults) OR `in_sitemap === true`
 *  - `robots` does not contain `noindex` (falls back to the migration's own default
 *    `'index, follow'` when there is no row / the column is empty, same posture as
 *    `NativeSeoMetaProvider::meta()`)
 *
 * URLs resolve through the {@see PostUrlResolver} CONTRACT (not the bundled `SeoUrlResolver`
 * class directly) — a host binding `heisenberg.seo.url_resolver` to its own implementation
 * controls every URL this controller emits, and it is the SAME resolver
 * `PreviewController::showPost()` uses for its own hreflang, so the two can never disagree.
 */
class SitemapController
{
    public function __construct(
        private PostUrlResolver $urls,
        private TranslationStatusService $status,
    ) {
    }

    public function index(): Response
    {
        $class = (string) config('heisenberg.models.post', Post::class);
        /** @var Post $probe */
        $probe = new $class();

        // posts() (docs/email-system.md §3): an email document is never sitemap-eligible — it has
        // no public URL at all. Scoping the base query means the hreflang $groups below (built
        // from the SAME collection) inherit the exclusion for free.
        $published = $class::query()->posts()->where('status', 'published')->get();

        $seoRows = SeoMeta::query()
            ->where('able_type', $probe->getMorphClass())
            ->whereIn('able_id', $published->pluck('id'))
            ->get()
            ->keyBy('able_id');

        $eligible = $published->filter(fn (Post $post) => $this->isEligible($post, $seoRows));

        $xml = $this->buildXml($eligible);

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** @param Collection<int, SeoMeta> $seoRows */
    private function isEligible(Post $post, Collection $seoRows): bool
    {
        $row = $seoRows->get($post->getKey());
        if ($row === null) {
            return true; // no row -> defaults apply: in_sitemap true, robots 'index, follow'
        }
        if (! $row->in_sitemap) {
            return false;
        }

        $robots = strtolower((string) ($row->robots ?: 'index, follow'));

        return ! str_contains($robots, 'noindex');
    }

    /** @param \Illuminate\Support\Collection<int, Post> $eligible */
    private function buildXml($eligible): string
    {
        $defaultLocale = LocaleConfig::default();
        $body = '';

        foreach ($eligible as $post) {
            $locales = $this->localesWithContent($post);
            $lastmod = $post->updated_at?->toAtomString();

            foreach ($locales as $locale) {
                $body .= '<url>';
                $body .= '<loc>' . $this->xmlEscape($this->urlFor($post, $locale)) . '</loc>';
                if ($lastmod !== null) {
                    $body .= '<lastmod>' . $this->xmlEscape($lastmod) . '</lastmod>';
                }

                if (count($locales) >= 2) {
                    foreach ($locales as $alternate) {
                        $body .= '<xhtml:link rel="alternate" hreflang="' . $this->xmlEscape($alternate)
                            . '" href="' . $this->xmlEscape($this->urlFor($post, $alternate)) . '" />';
                    }
                    if (in_array($defaultLocale, $locales, true)) {
                        $body .= '<xhtml:link rel="alternate" hreflang="x-default" href="'
                            . $this->xmlEscape($this->urlFor($post, $defaultLocale)) . '" />';
                    }
                }

                $body .= '</url>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            . $body . '</urlset>';
    }

    /**
     * The locales this ONE row is actually readable in — the same "has a title in that locale"
     * rule PreviewController's hreflang uses, so the sitemap and the page can never disagree.
     * Falls back to the row's own locale so a post always appears at least once.
     *
     * @return list<string>
     */
    private function localesWithContent(Post $post): array
    {
        $locales = [];
        foreach ($this->status->statuses($post) as $status) {
            if (($status['title'] ?? false) === true) {
                $locales[] = (string) $status['locale'];
            }
        }

        return $locales !== [] ? $locales : [(string) ($post->locale ?: LocaleConfig::default())];
    }

    private function urlFor(Post $post, string $locale): string
    {
        $clone = clone $post;
        $clone->locale = $locale;

        return $this->urls->url($clone);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

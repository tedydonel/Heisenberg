<?php

declare(strict_types=1);

namespace Heisenberg\Http\Controllers;

use Heisenberg\Contracts\PostUrlResolver;
use Heisenberg\Models\Post;
use Heisenberg\Models\SeoMeta;
use Heisenberg\Support\LocaleConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * `GET /sitemap.xml` (docs/seo-system.md §5, Wave S2b) — one `<url>` per published, sitemap-
 * eligible post row, with `<xhtml:link rel="alternate" hreflang>` entries across its translation
 * group + `x-default` (docs/content-translation.md's split-row model: a "post" in the sitemap
 * sense is one locale row, not the group). Loaded only when `config('heisenberg.seo.sitemap')` is
 * true (see `HeisenbergServiceProvider::registerSeoRoutes()`); URLs come from
 * the {@see PostUrlResolver} contract, the SAME resolver `PreviewController::showPost()` uses for its own
 * hreflang alternates, so the two surfaces never disagree.
 *
 * Eligibility (a row is IN the sitemap when):
 *  - `type === 'post'` (docs/email-system.md §3 — an email document has no public URL)
 *  - `status === 'published'`
 *  - no `SeoMeta` row at all (nothing overriding the defaults) OR `in_sitemap === true`
 *  - `robots` does not contain `noindex` (falls back to the migration's own default
 *    `'index, follow'` when there is no row / the column is empty, same posture as
 *    `NativeSeoMetaProvider::meta()`)
 *
 * Efficient by construction: exactly two queries regardless of post count — all eligible-
 * candidate posts, then their `SeoMeta` rows in one `whereIn`, both eager-loaded rather than
 * queried per row. The hreflang groups reuse the SAME published-posts collection (grouped by
 * `translation_group_id` in memory) rather than a query per group.
 *
 * URLs resolve through the {@see PostUrlResolver} CONTRACT (not the bundled `SeoUrlResolver`
 * class directly) — a host binding `heisenberg.seo.url_resolver` to its own implementation
 * controls every URL this controller emits.
 */
class SitemapController
{
    public function __construct(private PostUrlResolver $urls)
    {
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

        // Group ALL published rows (not just eligible ones) by translation_group_id -- hreflang
        // alternates point at any published sibling, whether or not IT is itself sitemap-eligible.
        $groups = $published->filter(fn (Post $post) => ! empty($post->translation_group_id))
            ->groupBy('translation_group_id');

        $xml = $this->buildXml($eligible, $groups);

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

    /**
     * @param \Illuminate\Support\Collection<int, Post> $eligible
     * @param \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Post>> $groups
     */
    private function buildXml($eligible, $groups): string
    {
        $defaultLocale = LocaleConfig::default();
        $body = '';

        foreach ($eligible as $post) {
            $loc = $this->urls->url($post);
            $lastmod = $post->updated_at?->toAtomString();

            $body .= '<url>';
            $body .= '<loc>' . $this->xmlEscape($loc) . '</loc>';
            if ($lastmod !== null) {
                $body .= '<lastmod>' . $this->xmlEscape($lastmod) . '</lastmod>';
            }

            $siblings = ! empty($post->translation_group_id) ? ($groups->get($post->translation_group_id) ?? collect()) : collect();
            if ($siblings->count() >= 2) {
                foreach ($siblings as $sibling) {
                    $body .= '<xhtml:link rel="alternate" hreflang="' . $this->xmlEscape((string) $sibling->locale)
                        . '" href="' . $this->xmlEscape($this->urls->url($sibling)) . '" />';
                }

                $default = $siblings->firstWhere('locale', $defaultLocale);
                if ($default !== null) {
                    $body .= '<xhtml:link rel="alternate" hreflang="x-default" href="'
                        . $this->xmlEscape($this->urls->url($default)) . '" />';
                }
            }

            $body .= '</url>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            . $body . '</urlset>';
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

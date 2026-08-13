# SEO system — design & build plan

Status: **as-built** (2026-08-11 — all waves landed and verified; the full package suite passes with this system in place). Companion doc: `docs/content-translation.md`
(the split-row locale model this rides on). Research basis: the SEO audit of 2026-08-11 — the 11-field
SEO/Social panel is finished VISUALLY but is static markup; `preview.blade.php`'s head logic works but
always receives `$seo = []`; the `PostSeoMetaProvider` seam is bound and never called; the BLUEPRINT
documents the legacy system's complete polymorphic `SeoMeta` (§2.3.11, §2.4, §11.1) which was never
ported. This plan ports it and finishes the wiring end to end.

## 1. Data model (Wave S1) — port BLUEPRINT §2.4, extended

Model `Heisenberg\Models\SeoMeta`, table `config('heisenberg.tables.seo_meta')` (default `seo_meta`,
deliberately unprefixed per the blueprint), polymorphic `able_type`/`able_id` (`morphs('able')`):

- Blueprint columns, verbatim: `meta_title_en/_fr`, `meta_description_en/_fr` (255), `og_image` (255),
  `canonical_url` (255), `robots` (255, default `'index, follow'`), `schema_type` (255),
  `schema_data` (json), timestamps.
- Extensions the current UI needs (the panel already draws these fields): `og_title_en/_fr`,
  `og_description_en/_fr` (social tab ≠ meta tab), `focus_keyphrase_en/_fr` (scoring input),
  `in_sitemap` (bool default true — the panel's "Include in sitemap" toggle; `robots` covers
  index/follow).
- Accessors: `metaTitle($locale)`, `metaDescription($locale)`, `ogTitle($locale)`,
  `ogDescription($locale)`, `focusKeyphrase($locale)` — own-locale first, cross-locale fallback
  (`PublicFile::getAlt()` posture). `getJsonLd($locale, $url)` per blueprint: Article JSON-LD from
  `schema_type`+`schema_data` merged with computed defaults (headline, dates, image, author).
- Bilingual columns + polymorphism are deliberate even though a post row is per-locale: the same table
  serves single-row bilingual entities (categories, tags — future) unchanged, exactly as the legacy
  system did. `models.seo_meta` config entry is uncommented; `'seo_meta'` added to swappable models.
- Wave S1 does NOT touch `src/Models/Post.php` (parallel-wave discipline); the `seoMeta(): MorphOne`
  relation lands in Wave S2 with the save path. Until then reads go through
  `SeoMeta::query()->where(...)`.

## 2. Provider seam goes live (Wave S1)

`NativeSeoMetaProvider` implements `PostSeoMetaProvider::meta(Post $post, string $locale): array`,
becomes the DEFAULT binding (Null remains the opt-out; same posture as comments). Contract return grows
(additive): `{title, description, canonical, ogImage, robots, ogTitle?, ogDescription?, jsonLd?}`.
`PreviewController::showPost()` finally calls the seam and feeds `preview.blade.php`'s existing head
logic real data; the `noindex`/`nofollow` booleans the view expects are derived from `robots`.
The `seoMeta` template capability flips to a real default: `resources/templates/article/article.json`
enables it; `docs/post-template-schema.md` §10 updated (it is no longer "the one capability with no
data source").

## 3. Save path + editor wiring (Wave S2a)

The SEO/Social panel's 11 fields become real controls: seeded from the post's `SeoMeta` (+`slug` from
the post — the panel's URL Slug field and the Summary slug input are the SAME value; they share the
pending-slug mechanism, not a second path), tracked as a pending `seo` object that rides the next
explicit save (`hbPendingSeo`, autosave excluded — same pattern as status/slug/date). Server:
`SavePostRequest` validates a `seo` map; `PostController::applySeo()` `updateOrCreate`s the MorphOne
(tier: authors may edit SEO of posts they may edit). The search-result preview snippet and character
counters go live from the actual field values. Social image uses the media picker (stores the file URL
in `og_image`). The panel edits the fields for the POST ROW'S OWN locale (the split-row model means the
FR sibling's panel edits `_fr` columns); the session-preview envelope `meta.seo` is populated so
unsaved previews carry SEO too.

## 4. Score & analysis (Wave S2b)

`SeoAnalyzer` service — deterministic, server-side, no external calls. Input: a post row + locale
(+ optional draft overrides so the panel can score unsaved edits). Checks (each `pass|warn|fail` +
message + weight):

| Group | Checks |
|---|---|
| Meta | title present / 30–60 chars; description present / 50–160 chars |
| Keyphrase | present; in title; in slug; in description; in first ~150 words; density 0.5–2.5% |
| Content | ≥300 words; exactly one H1-equivalent (post title) and heading hierarchy sane; paragraphs not over-long |
| Media & links | all images have alt text (locale-aware); ≥1 internal link; ≥1 outbound link |
| Technical | slug short/clean; canonical set or self; not noindexed while published; OG image present |
| Readability | Flesch reading-ease band (locale-aware syllable heuristic; FR uses its own thresholds) |

Score = weighted 0–100 → rating band `poor / needs-work / good / excellent`. Endpoint
`GET /editor/posts/{post}/seo/analyze?locale=` returns `{score, rating, checks[]}` (editor middleware,
view-tier). The panel's checklist stops being hardcoded: it renders the real checks, debounced
re-analysis on field edits (sending draft overrides), and a score ring/badge in the panel header —
the "SEO score rating" UI the design lacked.

## 5. Site-wide artifacts (Wave S2b)

- **Sitemap**: `GET /sitemap.xml` (config `heisenberg.seo.sitemap`, default on; `heisenberg.middleware.seo`)
  — published posts where `in_sitemap` and not noindexed, one `<url>` per locale row with
  `xhtml:link rel="alternate" hreflang` entries across its translation group + `x-default`; lastmod from
  `updated_at`. Host URL shape comes from a configurable URL template
  (`heisenberg.seo.url_template`, default the preview route) so hosts map it to their real blog routes.
  `url_template` accepts either shape:
  - a **string**, `{locale}`/`{slug}` placeholders, e.g. `https://example.com/{locale}/blog/{slug}` —
    the same substitution applied for every locale (unchanged behavior).
  - a **map keyed by locale**, e.g.
    ```php
    'url_template' => [
        'en' => 'https://example.com/blog/{slug}',   // default locale: unprefixed
        'fr' => 'https://example.com/fr/blog/{slug}', // other locales: prefixed
    ],
    ```
    which is how a host whose site already has its own `/en/`/`/fr/` structure — but wants the
    DEFAULT locale unprefixed — expresses it: one global template can only ever say "prefix every
    locale the same way," which is exactly the shape that forced the reference install to invent an
    `/en/` mirror route it didn't actually want. With the map above, an English post resolves to
    `https://example.com/blog/hello-world` and its French sibling to
    `https://example.com/fr/blog/hello-world` — no mirror route needed. Lookup order per post: its
    own locale, then a `'*'` catch-all key if present, then the `heisenberg.default_locale` key if
    present; `{locale}`/`{slug}` still substitute in whichever entry is chosen (a map entry may
    itself still use `{locale}`). No matching entry (or `url_template` left `null`) falls back to
    the dev-default preview route, same as an unset string template.

  For URL shapes even a per-locale map can't express — per-locale domains/subdomains, id-based
  URLs, anything reaching into a host's own route helpers — `heisenberg.seo.url_resolver` is the
  full override seam: a class implementing `Heisenberg\Contracts\PostUrlResolver` (`url(Post
  $post): string`), bound in the container exactly like `media_resolver`/`role_gate`/the
  `post_template.*` providers. `Heisenberg\Services\SeoUrlResolver` (the `url_template` logic
  above) is the bundled default. `SitemapController` and `PreviewController`'s hreflang alternates
  both resolve the `PostUrlResolver` CONTRACT from the container, never `SeoUrlResolver` by
  concrete class — so a host's binding controls every public URL Heisenberg emits, and the sitemap
  and a page's own `<link rel="alternate">` tags never disagree.
- **hreflang on pages**: `preview.blade.php` emits alternate links built by
  `PreviewController::alternatesPayload()` — under the single-row translation model
  (docs/content-translation.md §0) a "translation" is `_<locale>` attribute variants on the SAME
  row, not a sibling row, so every alternate points at the SAME post through the
  `PostUrlResolver` seam above with only its `locale` swapped on an in-memory clone. One alternate
  per locale the post actually has content for (that locale's title is non-empty), plus
  `x-default` at `heisenberg.default_locale`; a post translated into only its own home locale
  emits nothing.
- **JSON-LD**: `<script type="application/ld+json">` from `SeoMeta::getJsonLd()` on the preview page
  and offered to templates via the provider payload.
- **robots meta** already handled per-page; a robots.txt is the HOST'S file (documented, not shipped).

## 6. AI / MCP (Wave A1)

New tools (both surfaces unless noted): `get_seo {post_id}` (READ — full SeoMeta + analyze summary),
`update_seo {post_id, locale?, title?, description?, og_title?, og_description?, og_image?, canonical?,
robots?, focus_keyphrase?, in_sitemap?, schema_type?, schema_data?}` (AUTHORS — updateOrCreate, field
whitelist, length caps), `analyze_seo {post_id, locale?}` (READ — runs `SeoAnalyzer`). Media handling:
`update_media {file_id, alt_text_en?, alt_text_fr?, caption_en?, caption_fr?, credit?}` and
`set_featured_image {post_id, file_id|null}` (AUTHORS). Uploading bytes stays deliberately excluded
from the MCP surface (existing documented decision). `EditorPrompt` gains an SEO section (what the
tools do, what good meta looks like, use analyze → fix → re-analyze). `docs/ai-capability-matrix.md`
rows updated.

## 7. Out of scope (recorded)

Redirect management, external rank/keyword APIs, per-block schema markup, image sitemaps, and
robots.txt shipping. All host- or later-phase concerns.

## 8. Test surface

Model: accessors/fallbacks/JSON-LD. Provider: native meta shape + preview head emission pins
(description/canonical/robots/OG/JSON-LD/hreflang). Save: applySeo validation + tier gate + autosave
exclusion. Analyzer: per-check unit fixtures + score bands + FR thresholds. Sitemap: inclusion rules +
hreflang alternates. URL resolution: string `url_template` back-compat, per-locale map (including the
unprefixed-default-locale case), map fallback chain (`*` → `default_locale` → preview route), and a
custom-bound `PostUrlResolver` winning on BOTH the sitemap and the preview page's hreflang alternates
(`tests/Seo/SeoUrlResolverTest.php`). MCP: each tool's validation + surfaces. UI: panel wiring pins
(seeding, pending-seo payload, live checklist markup).

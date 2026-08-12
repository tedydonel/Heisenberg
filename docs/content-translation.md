# Content translation — design & build plan

Status: **as-built** (2026-08-11 — all waves landed and verified; the full package suite passes with this system in place; the same-day shared-slug + public-API revision below is included).
Companion doc: `docs/seo-system.md` (per-locale SEO
rides on the model defined here). Research basis: the i18n audit of 2026-08-11 — schema intent vs. what
was actually wired.

## 1. The model: split-row translations (finish what the schema intends)

A post's translation is **its own `heisenberg_posts` row**: same `translation_group_id` (UUID), its own
`locale`, slug (unique per `(locale, slug)` — already enforced), blocks, TOC entries, revisions, comments,
featured image, SEO meta, and its own lifecycle status. This is what the existing columns were built for;
we are wiring it, not changing it.

Explicitly REJECTED alternative: single-row content with `_en`/`_fr` attribute pairs on blocks.
`BlockRenderer::localizedAttribute()` keeps understanding the suffix shape (back-compat, and hosts may
use it for small inline variance), but it is NOT the translation mechanism — split-row scales to new
locales without migrations and gives per-locale slugs/status/SEO for free.

Within a row, the bilingual `title_en`/`title_fr`, `excerpt_en`/`excerpt_fr` columns remain, resolved by
accessor: a row primarily reads the column matching **its own locale**, falling back to the other.
New code never reads `title_en` directly.

**Shared-slug invariant (owner decision, 2026-08-11).** A translation group presents as **ONE logical
post**, not several — a visitor's language switcher just swaps locales on the same conceptual page. The
host resolves locale from its own URL prefix (`/fr/blog/...`), never from the slug text, so every row in
a group carries the **IDENTICAL slug**. The `(locale, slug)` composite unique index already permits this
(it was never a `slug`-alone unique index), so nothing about the schema changes — only the write paths
do:

- A rename (`PostController::applySlug()`) validates the new slug against every sibling's own locale
  (excluding that sibling) IN ADDITION TO the post's own locale, and — on success — writes it to the post
  AND every sibling in the same transaction. Any single collision fails the whole rename (naming which
  language blocked it); nothing partial is ever written.
- A brand-new translation (`PostTranslationController`'s create flow, and MCP `create_translation`'s
  create branch) copies the source's **exact** slug — no numeric-suffixing. See §4 for the collision
  behavior this implies.
- An empty-slug regeneration (`Post::booted()`'s `updating` hook, fired when a caller explicitly clears
  the slug) derives a base string from the row's own title as before, but unique-checks the candidate
  against the UNION of this row's locale and every sibling's locale, then writes the SAME result onto
  every sibling directly — see the hook's own docblock for why this is the simplest rule that stays
  exact rather than approximate.

**Featured image is group-wide too (owner decision, 2026-08-12).** Same "one logical post"
reasoning: the photo illustrates the article, not a specific language's row of it, so a group
carries exactly one featured image. `PostSettingsController::updateFeaturedImage()` and the MCP
`set_featured_image` tool write the post's own `featured_image_id`, then propagate that same value
(including clearing it to null) onto every sibling in a transaction — closing the gap where
translating a post with no featured image yet, then setting one afterwards, required setting it a
second time. `createSibling()`'s creation-time copy (below) still runs for a brand-new sibling that
has nothing to propagate to yet; `update: true` re-translation never touches `featured_image_id`,
so it can't clobber a value kept in sync elsewhere. (`allow_comments`/`page_padding_x`/`_y` are
still copied once at creation only and can drift the same way — left unchanged pending an owner
call: discussion settings arguably should differ per language, layout padding arguably shouldn't.)

## 2. Post model additions (Wave T1)

- `sibling(?string $locale = null)` — the group row for `$locale` (default: "the other" of en/fr), null
  when untranslated. `siblings()` — all other rows in the group. `scopeForLocale($q, $l)`,
  `scopeInGroup($q, $uuid)` (all per BLUEPRINT §2.3.1, previously unported).
- `title(?string $locale = null): string`, `excerpt(?string $locale = null): ?string` — own-locale
  column first, cross-locale fallback (same posture as `PublicFile::getAlt()`).
- New nullable column `translated_from_version` (unsignedBigInteger) + migration: set to the SOURCE
  row's `content_version` when a translation is created/updated from it. A translation is **outdated**
  when its source sibling's `content_version` has moved past this value. Null = never machine/workflow
  translated (hand-authored or pre-feature).
- `TranslationStatusService`: for a post, returns per configured locale one of
  `source | missing | draft | published | outdated` (+ sibling id when present).

## 3. Locale configuration (Wave T1)

Single source of truth: `config('heisenberg.locales')` = `['en', 'fr']` and
`config('heisenberg.default_locale')` = `'en'`. `LocaleController::LOCALES`,
`EditorLocaleMiddleware::LOCALES`, `editor.locales`, and the MCP `locale` validation all read it
(the old keys stay as deprecated aliases reading the new one). Adding a locale remains a host-level
config + (for now) schema decision — `title_<locale>` columns cap real support at en/fr until a
follow-up generalizes columns; the config comment says so honestly.

## 4. The translation workflow

**Create translation** — `POST /editor/posts/{post}/translations` body `{locale}` (editor middleware,
author-tier): creates the sibling row as `draft` (409 if it exists): copies blocks (same content tree —
translation happens IN the copy), TOC entries, featured image, layout/discussion settings; slug = the
source's **exact** slug (§1's shared-slug invariant — never a numeric-suffixed copy);
`translated_from_version` = source's `content_version`. Returns the new post id; the editor opens it.
If an UNRELATED post already holds that exact slug in the target locale, the whole translation is
refused with a 422 (`"Translation not created: the slug ... is already used by another post in ..."`)
BEFORE anything is written — same posture for the MCP `create_translation` tool's create branch. The
`update: true` re-translation flow never touches slug at all (it isn't part of what "re-translating"
means), so no collision check applies there.

**Translate with AI** — the assistant (editor surface) and external MCP agents get a
`create_translation` tool (see §6): the AI reads the source, translates the shortcode itself, and the
tool writes the sibling in one validated call. "Whole blog" translation = an external agent looping
`list_posts` → `create_translation`.

**Keeping up to date**: the editor shows `outdated` when the source moved; re-running translation
updates the sibling's blocks and bumps `translated_from_version`. Publishing the translation is the
normal lifecycle (Summary status control).

## 5. Editor UI (Wave T2a)

Post tab gains a **Translations** section (below Summary): one row per configured locale — locale name,
status chip (`source/missing/draft/published/outdated`), and per state: **Open** (loads the sibling in
the editor), **Create translation** (calls the endpoint above), or **Update from source** (re-copy —
confirm first, it overwrites the sibling's blocks). The section reads a seeded
`postTranslations` payload from `EditorController` and refreshes after actions. The footer language
pill remains what it truly is — the CHROME/render locale — and its labels stop claiming otherwise.

## 6. AI / MCP (Wave T2b)

New tool `create_translation` (AUTHORS tier, both surfaces):
`{post_id, target_locale, title, code, excerpt?, slug?}` — validates locale against config, shortcode
against block contracts (same validator `write_canvas` uses), creates-or-updates the sibling row
(always `draft` on the external surface), sets `translated_from_version`, returns
`{post_id, locale, status, slug, outdated: false}`. The `slug` ARGUMENT is accepted for wire-compat
only and otherwise **ignored** (§1's shared-slug invariant): a new sibling always gets the source
post's exact slug, and the response's own `slug` field always reports that real, shared value — a
caller that passed a different one learns the truth instead of assuming its input won. `get_post` responses gain
`translations: {<locale>: {post_id, status}}` so agents can discover the group. Taxonomy translation
uses the existing single-row bilingual columns: `create_category`/`create_tag` (and new
`update_category`/`update_tag`) accept `name_fr`/`description_fr`. `EditorPrompt` gains a LOCALES
section: the split-row model, the tool, and "translate = translate the shortcode text content only;
never structure, ids, attribute names, or media URLs."

## 7. Public side & hosts

`PreviewController::showPost()` stops hardcoding `title_en` (uses `title()`), and emits
`<link rel="alternate" hreflang>` pairs for published siblings (SEO doc §6 owns the tag shape).
Host guidance (a locale-prefixed blog is the reference install shape): route a locale prefix
(`/fr/blog/...`), scope queries `forLocale($locale)`, link siblings via `sibling()`, and let
`getAlt($locale)` etc. follow the page locale. The blog page shows a language switcher when a
published sibling exists.

**Public translations API (owner decision, 2026-08-11).** `GET /heisenberg/posts/{post}/translations`
(`routes/translations.php`, name `heisenberg.translations.index`, controller
`PostTranslationsApiController` — separate from the editor's mutating `PostTranslationController`) is
a read-only endpoint so **hosts build their own language-switcher buttons** off it, without reaching
into the database directly. Gated on `config('heisenberg.translations.routes')` (default true) and
`config('heisenberg.middleware.translations')` (default `['web']`) — same opt-out/middleware shape as
`routes/comments.php`.

Authorization: actor = the authenticated user, or a `GuestActor` stand-in. The REQUESTED post is
authorized via `Gate::authorize('view', ...)` first (an unknown post 404s via `findOrFail()`; a post
the actor may not view — e.g. a guest requesting a draft — 403s, mirroring `CommentController`'s own
public-endpoint precedent). Every OTHER group member is then filtered independently through the SAME
`PostPolicy::view()` gate: a guest sees only `published` siblings; the post's own author or an
`authors`/`admins`-tier actor also sees drafts.

Response shape:

```json
{
  "default_locale": "en",
  "slug": "hello-world",
  "translations": [
    { "locale": "en", "post_id": 12, "status": "published", "current": true },
    { "locale": "fr", "post_id": 34, "status": "draft", "current": false }
  ]
}
```

`slug` is top-level (not per-row) because §1's shared-slug invariant means the whole group has exactly
one. There are deliberately **no URLs** in this response — this package doesn't own the host's URL
shape (locale-prefixed, locale-only-for-non-default, or something else entirely); a host combines a
row's `locale` with the shared `slug` and its own routing to build a link.

**A host's URL shape lives in one place.** The sitemap and every page's own `<link rel="alternate"
hreflang>` tags are built from `heisenberg.seo.url_template`/`heisenberg.seo.url_resolver`
(docs/seo-system.md §5) — the SAME URL shape a host's own language switcher and this translations API
imply, so it is worth pointing here rather than duplicating: a per-locale `url_template` MAP is how a
host expresses "default locale unprefixed, other locales prefixed" (or any other irregular per-locale
shape), and `url_resolver` is the full override seam for anything a template can't express at all
(per-locale domains, id-based URLs, a host's own route helpers). See docs/seo-system.md §5 for both.

## 8. Out of scope (recorded so nobody wonders)

- Block-level attribute translation UI (`_en`/`_fr` suffixes stay renderer-only).
- More locales than en/fr (config generalizes; columns don't yet).
- TocEntry bilingual labels (each sibling row owns its own TOC — split-row makes this moot).
- Auto-publish of translations; comment threads are per-row by design (discussions differ per language).

## 9. Test surface

Model: sibling/scopes/accessors/fallbacks; outdated detection; group-wide empty-slug regeneration.
HTTP: create-translation endpoint (copies, 409, tier gate, exact-slug + 422 collision); rename
propagation across siblings + transaction atomicity on collision; the public translations API (guest
published-only + current flag + shared slug, staff sees drafts, 404 unknown post, draft invisible to a
guest, routes-toggle 404). MCP: create_translation validation + draft-only external posture + shared-
slug behavior (divergent `slug` arg ignored, response carries the real slug) + get_post translations
block. UI: wiring pins for the Translations section. Config: locale single-source pins.

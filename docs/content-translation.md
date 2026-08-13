# Content translation — design & build plan

Status: **superseded in part** — the split-row model described below shipped 2026-08-11 and is being
replaced by the single-row model in §0 (owner decision, 2026-08-13). Everything from §1 onward
documents the model as it was built; read §0 first, and treat the rest as the history that explains
why the columns and services look the way they do.
Companion doc: `docs/seo-system.md` (per-locale SEO
rides on the model defined here). Research basis: the i18n audit of 2026-08-11 — schema intent vs. what
was actually wired.

## 0. Model change: one post, localized fields (2026-08-13)

**Why.** Split rows made every structural edit a two-post chore: adding a block to the French version
meant opening the English post at a different URL and adding it again, forever, with drift guaranteed
the moment someone forgot. The flexibility that bought — a different layout per language, publishing
one language while the other stays draft — is not what a blog actually needs, and it was being paid
for on every single edit.

**The model.** A logical post is ONE `heisenberg_posts` row. Structure exists once; only the words
differ per locale.

- Post fields already work this way: `title_en`/`title_fr`, `excerpt_en`/`excerpt_fr` on one row, and
  `SeoMeta` is bilingual on one row too. Nothing changes there.
- Block text lives in locale-suffixed attribute variants — `content_en`, `content_fr` — which
  `BlockRenderer::localizedAttribute()` has always read (`key_<locale>`, then bare `key`). That
  mechanism stops being a curiosity and becomes the translation mechanism.
- A contract marks which attributes hold human language with `"translatable": true` (heading/paragraph
  `content`, button `text`, list items, quote text, image `alt`/`caption`). Everything else — urls,
  variants, colours, sizes — is locale-neutral and stored once. `BlockContractValidator` validates the
  flag; the editor uses it to decide which controls are locale-scoped.
- `translation_group_id`, `Post::sibling()`, `scopeForLocale()` and the create-a-sibling endpoint
  become vestigial. They are removed, not left as traps.

**What this costs, stated plainly.** One row means one lifecycle: you publish the post, not the
language, and a half-translated post is published in whatever state its French fields are in. There is
no per-language layout. If per-language publishing is ever genuinely wanted, the honest way to add it
is a `published_locales` set on the row — not a second row.

**Comments** attach to the post, so a thread is shared across languages: it is a discussion about the
article, not about one translation of it. Documented rather than filtered, because filtering by locale
would fragment a conversation for no clear benefit.

**Migration.** Existing sibling rows must be merged back: the non-default row's title/excerpt fold into
the surviving row's `_<locale>` columns, and its block text folds into the surviving blocks'
`_<locale>` attribute variants, matched by position within the group. Structures that have already
diverged cannot be merged safely — the command reports those groups and leaves them alone rather than
guessing which blocks correspond. The merged-away row is then removed.

**Public side.** `/fr/blog/{slug}` resolves the SAME row and renders with `locale=fr`; the shared-slug
invariant from §1 stops being a rule to enforce and becomes a fact. The public translations API
reports which locales the post has content for, and the host builds its own URLs as before.

### 0.1 Wave 1 as built (engine + data layer only, 2026-08-13)

Wave 1 built the CONTRACT/DATA/MIGRATION layer only. The editor UI (Translations section, topbar
language dropdown), MCP tools (`create_translation`, `get_post`'s `translations` map,
`set_featured_image`'s propagation), and `PostTranslationsApiController` (the public API) are
explicitly OUT OF SCOPE and are now, to varying degrees, BROKEN against the single-row model — see
"What's now broken" below. A later wave rebuilds each against what this wave shipped.

**Translatable-attribute audit.** Every shipped contract's attributes were reviewed; the following
are marked `"translatable": true` (and nothing else — no urls, variants, colours, sizes, anchors,
or ids):

- heading: `content`. paragraph: `content`. button: `text`. quote: `content`, `citation`.
  list: `content` (the newline-separated items). image: `alt`, `caption`.
- `titleAttr` (the shared `title=""` tooltip attribute every block contract carries) — marked
  translatable on ALL 12 contracts (heading, paragraph, button, quote, list, image, column,
  columns, group, separator, embed, icon). It is genuinely human-facing accessibility text, the
  same category as `alt`, even though it wasn't named in the original list above — "and anything
  equivalent" from the original brief. It is usually empty; see the completeness rule below for why
  an unused optional field doesn't corrupt translation-status tracking.
- column/columns/group/separator/embed/icon have no OTHER human-language attributes (`icon`'s own
  `icon` attribute is a library slug reference, not text).

**`BlockRegistryService::translatableAttributes(string $name): string[]`** exposes the list
per-contract for server-side callers (the merge command, `TranslationStatusService`); the localized
registry envelope also carries a `translatableAttributes` key per block, alongside `controls`/
`panels`, for the editor wave to consume without re-deriving it client-side.

**`Heisenberg\Support\LocalizedAttributes`** — the dependency-free helper class: `read()` mirrors
`BlockRenderer::localizedAttribute()`'s `key_<locale>`-then-bare-`key` resolution exactly; `write()`
always writes the suffixed variant, never the bare key, and never mutates its input; `hasContent()`
is the "is this actually filled in" predicate every other piece uses; `locales()` answers "which of
these candidate locales does this block have content for" — critically, `locales()` does NOT reuse
`read()`'s bare-key fallback for this purpose (that would make an untranslated block read as "100%
translated everywhere," since the fallback always resolves to something). Only a caller-supplied
`$homeLocale` (normally the post's own `locale` column) may satisfy an attribute via its bare value;
every other candidate locale needs its own explicit `key_<locale>` variant. `locales()` also only
demands attributes the author actually used (non-empty in bare form) — an unused optional field like
`titleAttr` never blocks completeness, including for the block's own home locale.

**`TranslationStatusService::statuses(Post $post)`** — rewritten from sibling-row status
(`source|missing|draft|published|outdated`) to per-locale COMPLETENESS on the one row:

```
{locale, is_default, title: bool, excerpt: bool, blocks_translated: int, blocks_total: int, complete: bool}
```

one row per `LocaleConfig::locales()`. A block counts toward `blocks_total`/`blocks_translated` only
when it has authored content worth translating (at least one translatable attribute non-empty in
bare form) — nested `innerBlocks` are walked too. `complete` requires `title` AND every counted
block translated; `excerpt` only gates completeness once the post uses an excerpt in ANY configured
locale (a post that never uses excerpts isn't held "incomplete" forever for lacking one).

**`php artisan heisenberg:merge-translations [--dry-run]`** — merges every `translation_group_id`
with more than one row back into one:

- **Survivor**: the row whose `locale` equals `heisenberg.default_locale`; else the oldest
  (`created_at`, then `id`).
- **Folded into the survivor**: `title_<locale>`/`excerpt_<locale>` columns; every translatable
  block attribute, matched BY POSITION (top-level block index, then recursively by `innerBlocks`
  index) into `<attribute>_<locale>`; `SeoMeta`'s five locale-suffixed text fields
  (`meta_title`/`meta_description`/`og_title`/`og_description`/`focus_keyphrase`), created via
  `updateOrCreate` if the survivor had no `SeoMeta` row yet.
- **Refuse to guess, whole-group**: EVERY fold for EVERY sibling is validated before ANYTHING is
  written. A block-tree shape mismatch (different block count, or a different block `name` at any
  position at any depth) OR a target field already holding DIFFERENT non-empty content on the
  survivor (title/excerpt/block attribute/SEO field, all held to the same rule) skips the ENTIRE
  GROUP — nothing partial is ever written, every reason found is reported.
- **Comments**: REASSIGNED (`post_id` UPDATE) onto the survivor before the sibling row is removed —
  never dropped. A thread is about the article, not one translation row of it.
- **SEO row-level fields** (`og_image`, `canonical_url`, `robots`, `schema_type`, `schema_data`,
  `in_sitemap`) are NOT folded — technical/single-value, the survivor's own value (or lack of one)
  is left untouched. The sibling's own now-redundant `SeoMeta` row is deleted (it has no cascading
  FK, so it would otherwise leak as an orphan).
- **TOC entries are a known, documented gap**: `TocEntry::label` has no `_<locale>` column (unlike
  title/excerpt/block attributes/SEO), so there is nowhere honest to fold a sibling's translated
  labels TO. The survivor keeps its own TOC untouched; the sibling's TOC rows are removed with it
  (cascading FK). Bilingual TOC labels need their own migration in a later wave.
- **Revisions and category/tag pivot rows** on the sibling are cascade-deleted with it (same FKs
  `Post::delete()`'s own docblock describes) — not folded, not mentioned as in-scope by the original
  brief. `page_padding_x`/`page_padding_y`/`allow_comments`/`featured_image_id` are left as the
  survivor's own values (already meant to be kept in sync as group-wide settings, not per-locale
  text).
- The default run wraps each group in its own transaction and `forceDelete()`s the merged-away rows
  (a real SQL DELETE, so the cascading FKs above actually fire). `--dry-run` computes and reports
  the identical plan, writing nothing.

**Removed** (stopped reading `translation_group_id`/`locale` for these; the COLUMNS themselves are
left in place, pending a follow-up migration once the merge command has run everywhere):
`Post::sibling()`, `Post::siblings()`, `Post::scopeForLocale()`, `Post::scopeInGroup()`,
`Post::isTranslationOutdated()`, `PostTranslationController` and its
`POST /editor/posts/{post}/translations` route. `PostController::applySlug()` and
`Post::booted()`'s `updating` hook no longer validate/propagate a rename across sibling rows (there
are none to propagate to); `PostSettingsController::updateFeaturedImage()` no longer propagates to
siblings for the same reason.

**What's now broken, for the next wave to pick up** (all deliberately left as-is per this wave's
brief — "Do NOT touch: `PostTranslationsApiController.php`, the editor blades, `McpToolRegistry`"):

- `McpToolRegistry`'s `create_translation` tool FATALS as soon as it reaches
  `$source->sibling($targetLocale)` (pre-flight validation — locale/title/shortcode checks — still
  runs and still works). `set_featured_image` FATALS on EVERY call, translated or not, because it
  unconditionally calls `propagateFeaturedImageToSiblings()` after every write. `get_post`'s
  `translations` map degrades to `{post_id: null, status: null}` per locale (a PHP warning, not a
  fatal, since the missing array keys are just read as `null`) rather than the real per-locale
  status.
- `PostTranslationsApiController::index()` (`GET /heisenberg/posts/{post}/translations`) FATALS on
  every request — it calls `$model->siblings()`.
- `PreviewController::alternatesPayload()` (hreflang `<link rel="alternate">` tags) now
  unconditionally returns `[]` — it used to build the list from published sibling rows, and there
  are none anymore. Real multi-locale hreflang for one row needs the per-locale URL shape
  (`heisenberg.seo.url_template`/`url_resolver`) applied differently; that rebuild is public-side
  work for a later wave.
- The editor's Translations section (Post tab) and the topbar language dropdown
  (`EditorController::show()`'s `postTranslations` seed, `TranslationStatusService::statuses()`)
  now receive the NEW completeness shape while the Blade markup (`inspector.blade.php`,
  `topbar.blade.php`) still expects the old `{locale, status, post_id}` per-sibling-row shape — the
  section renders on stale/absent fields rather than fataling, but shows nothing meaningful. Given
  the model change, this section's whole affordance set (Open/Create translation/Update from
  source, all sibling-row actions) needs a genuine redesign for one-row-many-locales, not just a
  data adapter — left for the editor UI wave.

### 0.2 Wave 2 as built (editor UI, 2026-08-13)

Wave 2 rebuilds the editor UI against Wave 1's data layer: the SAME document is edited in every
configured locale, in place.

**Editing locale.** Tracked client-side in `live/block-runtime.blade.php` (`editingLocale`,
alongside the fixed `homeLocale` — the post's own `locale` column, seeded via
`EditorController`'s `postLocale`). Defaults to `homeLocale`, corrected from
`localStorage['hb-editor:editing-locale:<postId>']` (`'new'` for an unsaved document) before the
first render — no locale-flash to fix up. Switching (topbar dropdown, or a Translations-section
row) calls `window.hbEditor.setEditingLocale()`, which re-renders every block, re-seeds the
inspector's open panel, and fires `hb:editing-locale-change` — the one signal the topbar label,
the canvas's locale badge, and the Translations rows all react to. The title has no bare column
(only `title_en`/`title_fr`), so it is NOT part of this mechanism — `topbar.blade.php` keeps its
own `hbTitleByLocale` cache (seeded from both columns) and swaps the visible text on the same
event, re-using `live/canvas.blade.php`'s own title-sync script via a synthetic `input` event
rather than duplicating it.

**Writes.** One resolver pair in `block-runtime.blade.php`, exposed on `window.hbEditor`:
`resolveAttrKey(name, key)` (write) and `readAttr(model, key)` (read), both driven off each
contract's `translatableAttributes` (now shipped in `BlockViewData::clientBlocks()`, which
previously derived-but-dropped it). `resolveAttrKey` writes `key_<editingLocale>` for a
translatable attribute EXCEPT when `editingLocale === homeLocale`, which keeps writing the bare
key — the exemption the brief called out explicitly, so a post's own language never forks into a
`_<homeLocale>` variant nothing else reads. Every write path routes through it: `setAttribute`
(inspector controls), the canvas's contenteditable/rich-text commit, and Code view's two write
sites (`code-editor.blade.php`, a plain `attr="value"` and the rich-text body). Every read path —
`subst()`'s `{{attributes.x}}` substitution, the `rich-text` and `text-lines` template nodes, the
inspector's `syncControls`, and Code view's serializer — goes through `readAttr`, which mirrors
`LocalizedAttributes::read()` exactly (suffixed-if-present, else bare).

**Translations section.** Rebuilt against `TranslationStatusService::statuses()`'s completeness
shape: one row per configured locale showing a computed summary (Complete / title missing /
`:done/:total blocks`), the current editing locale marked (`.is-current`, client-side — `is_default`
is a different concept, config's default locale). Clicking a row calls `setEditingLocale()`; there
is no Create/Open/Update action left, so `PostTranslationsUrlTemplate`/`postEditorUrlTemplate` and
the whole fetch-and-navigate flow are gone from both `topbar.blade.php` and
`inspector/taxonomy-behavior.blade.php`.

**Still open**: `McpToolRegistry`, `PostTranslationsApiController`, and `PreviewController`'s
hreflang payload — unowned by this wave (a parallel wave), see §0.1's own "what's now broken" for
the exact fatals.

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

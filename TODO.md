# Heisenberg — Working TODO

Iterative checklist. Tick items as they land — this file is the *plan*.

**Legend:** `[ ]` todo · `[~]` in progress · `[x]` done · `[!]` blocked (reason inline)
**D:** dependency · **AC:** acceptance criteria · **Own:** which agent owns the files

## Status — 2026-08-05

| Phase | State |
|---|---|
| 0 — Unblockers | ✅ done |
| 1 — Persistence | ✅ done except 1.1 (locale-specific save unverified) and 1.7 (`Revision` exists, **nothing calls `snapshotOf()`**) |
| 2 — Inspector + toolbar | ✅ done except 2.6, which Phase 7 supersedes (see below) |
| 3 — Taxonomy + template schema | ✅ done — 3.1 (Categories/Tags) 2026-08-03, 3.2 (Post Template schema) 2026-08-03 |
| 4 — Footer + editor UI language | ✅ done (en/fr at 195/195 key parity) |
| 5 — Client test platform | ⬜ not started |
| 6 — Audit debt | ⬜ mostly open, but 6.1/6.2/6.6 landed 2026-08-02, 6.7 landed 2026-08-03 (see below) |
| 7 — Inspector functional; contracts catch up to the UI | ✅ 7.1–7.8 done; 7.9 deferred by instruction until more block contracts exist |

Suite: **533 tests, 2260 assertions, green.**

**2026-08-05 — repository reset.** The GitHub repo was deleted and recreated to remove AI
co-author attribution that could not be stripped any other way (a closed PR's `refs/pull/1/head`
is server-managed and immutable). History restarted at a single `Initial commit`; the previous
176 commits survive only on the local `archive/pre-reset` branch. Dropped in the same pass:
`docs/gutenberg-study/`, `docs/superpowers/`, `CODEBASE-REVIEW.md`,
`docs/editor-remediation-audit.md`. **Commits must never carry `Co-Authored-By` or any
AI-attribution trailer.**

**2026-08-05 — Style panel now gates on the contract.** `style-panel.blade.php` accepted a
`$supports` prop and never read it, so every block rendered all ten Style sections. It now gates
per section (and Typography per field), using the same truthiness rule as the toolbar. This
reversed `EditorRendersTest::test_style_panel_keeps_the_complete_pencil_section_stack_mounted`.
**Phase 7 is the other half of that work**: gating removed UI the contracts did not back;
Phase 7 makes the contracts back the UI that should exist. See
`docs/inspector-composition.md` and `docs/toolbar-composition.md` for the full control catalogue.

**2026-08-04 — the gap the README exposed.** Writing host-app install instructions
(`README.md`) surfaced the one thing nothing in this repo can catch: Heisenberg can *author*
content and cannot *publish* it. `/editor` saves a post, its blocks, its taxonomy, its layout
and its theme; `BlockRenderer` compiles `rendered_html_en`/`_fr`; `PostTemplateRegistryService`
validates a template contract describing the page chrome. **Nothing renders one.** There is no
public route, no template renderer, no column linking a `Post` to a template — the registry's
only consumers are `templates:verify` and its own tests. An adopter following the README today
reaches step 5, opens the editor, saves a post, and then has to write the entire public surface
themselves. That makes **5.1 (client test platform) the next step, not 6.x** — the friction is
only visible from outside the package, and 5.5 is where the missing renderer becomes a
build-it-now item rather than a note in a file.

**2026-08-02 — the builder is gone, block contracts pruned to heading + paragraph.** Decided against
chasing editor/builder parity (the earlier plan in this file) in favor of a clean break: only one
editing surface left to reason about, at the cost of losing image/button/embed/columns/etc. as
insertable content types until/unless they're deliberately rebuilt for the editor. Concretely:
`/builder` and everything under it (`resources/js/builder`, `resources/views/builder`,
`resources/css/builder`, `BuilderController`, `routes/web.php`, `tests/Builder`) — deleted.
`resources/blocks/{button,code,column,columns,cta,embed,image,list,pullquote,quote,section-head,
separator,spacer}` — deleted; only `heading`/`paragraph` remain. `BuilderViewData` → renamed
`BlockViewData` (`src/Support/BlockViewData.php`), trimmed to only what the editor uses
(`clientBlocks`/`blocksCss`), no more config-driven allow-list — the editor now ships whatever
`BlockRegistryService` auto-discovers. `ThemeController`/`FontController` were kept (real,
reusable backend, not builder-specific) but are currently **unrouted** — re-mount under `/editor`
when the Style/Themes panel actually needs them (was 6.7). This closes 6.1 (allow-lists: there's
only one now), 6.2 (orphaned contracts: deleted, not wired), and 6.6 (decommission the builder).
Full history is in git — every deleted file is one `git log` away if any of it is needed again.

**Known live defects** (verified over HTTP, not yet fixed):
- Cold Blade view cache → first `/editor` request compiles templates, exceeds 30s, **500s**. Warm ≈2–3s.
- No revision is recorded on save.

---

## Ground rules

1. **File ownership is exclusive per wave.** An agent may *read* anything, but may only *write*
   files explicitly listed as its own. The one time this was loose, an agent rewrote the twelve
   1:1-from-Pencil `live/block/**` components instead of mounting them.
2. **Shared hubs are frozen to the orchestrator**: `block-runtime.blade.php`, `inspector.blade.php`,
   `HeisenbergServiceProvider.php`, `config/heisenberg.php`, `tokens.css`, `01-reset.css`.
   Agents request changes; they don't make them.
3. **There is no builder anymore (2026-08-02).** It used to be a reference for visual details and
   proven algorithms; anything worth porting from it now has to come from git history instead.
4. **Vanilla JS on `/editor`.** No Alpine, no Livewire, no bundler. The page loads none of them.
5. **Every wave ends green.** `vendor/bin/phpunit` must pass; new behaviour needs new tests.
6. **Report honestly.** "Didn't do X" is always better than an overstated completion.

---

## Phase 0 — Unblockers (small, independent, do first)

- [x] **0.1 Components tab: show only real blocks**
  8 of 12 cards (Form, Input, Text Area, Select, Checkbox, Radio, Link, Video) map to no contract
  and never will. Drop them; keep the grid driven by what's actually registered.
  *Files:* `live/panel-components-blocks.blade.php`
  *AC:* every card in the tab resolves to a contract in the registry; clicking any card inserts.

- [x] **0.2 `ui/category-head` actually collapses**
  Today it flips the chevron and dispatches `toggle`; nothing listens, so content never hides.
  Fix at the component level so every importer benefits — mirror `ui/disclosure-row`'s existing
  contract (toggle a sibling `[data-hb-category-body]`).
  *Files:* `ui/category-head.blade.php` (+ callers to add the body hook)
  *AC:* "Base" in the Components tab collapses/expands its grid; behaviour is in the component,
  not the caller.

- [x] **0.3 Editor gets its own middleware key**
  `routes/editor.php:8` uses `config('heisenberg.middleware.builder')`; no `middleware.editor`
  exists, so the two surfaces can't be protected independently.
  *AC:* `heisenberg.middleware.editor` exists, defaults `['web']`, and `routes/editor.php` reads it.

- [x] **0.4 Close the media upload hole + make upload work in dev**
  `src/Livewire/MediaLibrary.php` has no `mimes:`, no `authorize()`. `MediaLibraryService::storeOne()`
  never re-checks the extension, so the allow-list (FormRequest-only) is bypassed. Default scanner
  is `NullVirusScanner`. Served back as `image/svg+xml` from our own origin → stored XSS.
  - [x] `mimes:` + `max:` on the Livewire validation
  - [x] push the extension allow-list **into** `MediaLibraryService::storeOne()` (the real fix)
  - [x] `authorize()` on `updatedUploads()` and `remove()`
  - [x] `local`-only permissive `RoleGate` so dev sessions can upload
  - [x] `tests/Media/` coverage for the Livewire path
  *AC:* uploading works on a local dev session; a guest on a non-local env cannot upload or delete;
  a `.svg` with a script tag is rejected at the service layer.

- [x] **0.5 `PUT /builder/theme` no longer unauthenticated by default**
  Deliberate today (documented at `routes/web.php:21-26`) but unsafe on install.
  *AC:* default posture is safe; the testbench harness still runs.

---

## Phase 1 — Persistence (the defining gap)

Nothing can be saved today. `Post`/`Block`/`BlocksPayloadService`/`content_version` are all built
and wired to zero controllers. **D:** Phase 0.3.

- [~] **1.1 Content localisation columns (en/fr)**
  Post content is stored per-locale (`excerpt_en/_fr`, `rendered_html_en/_fr`, `alt_text_en/_fr`) —
  known and expected; the save/load payload carries the active locale's fields.
  *Note, not a blocker:* these are per-language **columns**, so a future third locale is a migration
  rather than a row. Fine for en/fr; worth a look before the package has outside adopters. Flagging
  once here, not re-litigating it.
  *AC:* saving in `fr` writes the `_fr` fields and leaves `_en` untouched, and vice versa.

- [x] **1.2 `PostController` + `PostPolicy`**
  `store`/`update` running the payload through the already-built `BlocksPayloadService::validatePayload()`,
  then replacing block rows transactionally. Model on `MediaLibraryController` (the one properly
  built controller here). Policy built *with* the endpoint, not after.
  *AC:* a block tree round-trips to the DB and back; unauthorised users get 403.

- [x] **1.3 Load path** — `GET /editor/{post}` hydrating from real rows; seed `doc.blocks` instead
  of booting empty. *D:* 1.2

- [x] **1.4 Optimistic concurrency** — compare client `content_version` in-transaction, 409 on
  mismatch, then `bumpContentVersion()`. The machinery exists and is never checked. *D:* 1.2

- [x] **1.5 Autosave** — reuse the `autosave` flag `BlocksPayloadService:58-60` already accepts. *D:* 1.4

- [x] **1.6 `unique(['locale','slug'])` + collision handling**
  `slug` is a plain non-unique index; `Post::booted()` derives it from the title with no collision
  detection. Live the moment posts are created over HTTP.

- [~] **1.7 Revisions** — `heisenberg_post_revisions` migration + `Revision` model + snapshot on
  save. Config reserves the table name; nothing else exists. `content_version` alone can't restore.

- [x] **1.8 Lifecycle guard** — `status`/`published_at`/`scheduled_at` are mass-assignable with
  nothing enforcing `config('heisenberg.lifecycle')`. A low-privilege author could self-publish.

- [x] **1.9 Recurse `BlocksPayloadService` into `innerBlocks`** with the depth cap `BlockRenderer`
  already enforces (`MAX_NESTING_DEPTH = 20`).

- [x] **1.10 Soft-delete cascade mismatch** — blocks FK is a hard `cascadeOnDelete()` but `Post`
  uses `SoftDeletes`, so a trashed post's blocks stay live.

---

## Phase 2 — Make the inspector and toolbar actually work

Style/Advanced render and gate correctly but don't read or write values. Only Content is bound.

- [x] **2.1 Bind Style panel controls** to `hbEditor.setSupport(id, path, value)` — two-way.
  *Files:* `live/block/style/*.blade.php` (**add hooks only — do not restructure the markup**)

- [x] **2.2 Bind Advanced panel controls** (visibility toggles, animation) via `setAttribute`.

- [x] **2.3 Toolbar format buttons apply formatting**
  `hb:format`, `hb:toolbar-action`, `hb:toolbar-popover` are dispatched with zero listeners.

- [x] **2.4 Add the missing popover containers** — triggers query `[data-tb-pop="color"]` and
  `="align"`, which don't exist, so the query returns `null` and clicking does nothing.

- [x] **2.5 Type dropdown retypes a block** — `blocktype` event has no listener; its
  "Heading 1/2/3" entries don't match the contract, which stores `level` as one attribute.

- [ ] **2.6 Style panels whose writes don't render**
  Position, Flex Layout, Appearance-opacity, Effects, per-side Border write to `supports.*` paths
  with no matching `contract.style.variable`, so the canvas doesn't update. Either add the
  variables to the contracts or hide those controls.

---

## Phase 3 — Taxonomy + Post Template schema (new public surface)

- [x] **3.1 Categories + Tags** — done 2026-08-04.
  - [x] migrations (`categories`, `tags`, pivot), models, config-driven table names
  - [x] REST endpoints (index/store/update/destroy/attach), FormRequests, policies
  - [x] wire the inspector's inert Categories/Tags rows — both flipped from chevron="right"
        (inert navigation stub) to chevron="down" (inline expansion, matching Featured
        Image/Summary above them). Categories is `ui/combobox` seeded from a depth-indented
        flatten of the category tree; picking an existing one PUTs `/editor/posts/{id}/category`,
        typing a name with no match creates it via `POST /editor/categories` then attaches it.
        Tags is a new chip-input (search-as-you-type against `GET /editor/tags`, Enter/click
        attaches an existing tag or creates+attaches one, each chip removable). Both render
        disabled until a real post id exists — a new `hb:post-id` event (topbar.blade.php,
        dispatched on a new document's first save) enables them without a reload.
  *AC:* a post's categories/tags round-trip; endpoints authorised; MySQL + SQLite both migrate.
  Verified live: created a category + tag, attached both to a real post, reloaded
  `/editor/{id}` and confirmed both came back pre-selected/pre-chipped from the server render.
  **2026-08-03, follow-up — real Gutenberg-style multi-category, checkbox lists for both:**
  user feedback: the combobox/checklist-single-select/chip-list mix didn't match Gutenberg's own
  Categories UI (a tickable checklist allowing more than one) and read as "hardcoded instead of
  imported." Two changes:
  (1) **Schema**: `Post::category()` (BelongsTo, single) → `Post::categories()`/`Category::posts()`
  (BelongsToMany via new `heisenberg_category_post` pivot, mirroring `tags()` exactly) — migrations
  `2026_08_03_000001`/`_000002` (the latter backfills existing `category_id` data into the pivot
  before dropping the column; SQLite's `dropColumn()` needed the column's plain index dropped
  first, not just its FK, or the table-rebuild it does internally fails). `PostCategoryController`
  rewritten to attach/detach one category at a time (`POST`/`DELETE /editor/posts/{id}/categories/
  {category}`), identical shape to `PostTagController` now. `docs/BLUEPRINT.md` gets a `[TARGET]`
  deviation note at §2.3.3 rather than an edit to the ported host app's historical record.
  (2) **UI**: Categories AND Tags now render through one shared widget (`wirePostTaxonomy()` in
  inspector.blade.php) — real `ui/checkbox` rows for every option, an add/search input above
  (filters the already-loaded list, Enter creates+attaches a new one when nothing matches),
  checking a box POSTs an attach and unchecking DELETEs. Replaces the earlier single-select
  button-checklist (Categories) and chip-list-plus-remote-search-dropdown (Tags) — those were two
  different widgets for what's now the same relationship shape on both sides. `EditorController`
  gained `tagOptions()` (mirrors `categoryOptions()`, flat since tags have no tree).
  Regression coverage: `tests/Taxonomy/PostTaxonomyRelationsTest.php`/`PostTaxonomyAttachDetachTest.php`
  rewritten for the pivot; `tests/Editor/EditorRendersTest.php` gained checklist-render assertions.
  Verified live: attached TWO categories to one post (impossible before), confirmed both render
  pre-checked on reload.
  **Caught mid-build**: `@if (...) disabled @endif` embedded directly inside a self-closing
  `<x-ui.toggle .../>`/`<x-ui.slider .../>` component tag doesn't compile — Blade's component-tag
  compiler tokenizes the tag's attributes via regex and doesn't expect a raw directive sitting
  among them, so the whole tag fell through as literal unrendered text. Fixed by using the
  `:disabled="$expr"` prop binding instead (which both components already supported) — the
  idiomatic form anyway. A plain HTML tag (e.g. `<input ... @if(...) disabled @endif>`) does NOT
  have this problem; only Blade's own `<x-...>` component tags do.

- [x] **3.2 Post Template schema** — done 2026-08-03 (commit `c95b1ec`).
  A contract adopters follow to define the template that renders posts/pages publicly.
  **Model it on the existing block contract** (`resources/blocks/*/*.json` + `BlockContractValidator`
  + `BlockRegistryService` + `docs/block-schema.md`) so adopters learn one mental model.
  - [x] `docs/post-template-schema.md` — the spec
  - [x] `PostTemplateContractValidator` + `PostTemplateRegistryService` (mirror the block pair)
  - [x] a shipped reference template (`resources/templates/article`)
  - [x] `templates:verify` drift command (and the long-missing `blocks:verify` alongside it)
  - [x] the four capabilities needing storage this package doesn't own became adapter contracts
        with null defaults (`PostViewsProvider`, `PostCommentProvider`, `RelatedPostsProvider`,
        `PostSeoMetaProvider`) — settles open question #1; the other seven render directly.
  **What this did NOT include, and is now the largest gap in the package:** nothing *renders* a
  template. There is no `PostTemplateRenderer`, no public route, and no per-post template
  assignment (`Post` has no `template` column — the schema doc says so explicitly and leaves
  selection to the host). The registry's only consumers are `templates:verify` and its tests.
  A template contract is currently a validated, verifiable, entirely inert JSON file.
  *Supported options the schema exposes, so adopters pick what their template uses:*
  table of contents · featured image · post views · comments/discussion · related posts ·
  reading time · author box · share buttons · breadcrumbs · SEO/meta emission · pagination
  *Open question to settle during the design review (per option, not globally):* which of these
  Heisenberg renders itself, and which it exposes as an adapter contract for the adopter to supply.
  TOC/reading-time/featured-image are pure renderer concerns. Views and comments need storage, so
  they're the ones worth deciding deliberately — the existing `MediaResolver`/`RoleGate`
  null-object pattern is available if we want adopters to plug their own in.

---

## Phase 4 — Footer + editor UI language

> **Scope note.** Two different things share the word "language" and only the first is this phase:
> **(a) Editor UI language** — a French user opens `/editor` and the *interface* is French:
> buttons, labels, panel titles, tooltips, aria-labels. Switched from the footer's language
> section. **This is Phase 4.**
> **(b) Content localisation** — post bodies stored per-locale (`excerpt_en/_fr`,
> `rendered_html_en/_fr`). Already understood and migration-planned; tracked in Phase 1, not here.

- [x] **4.1 Footer connection status** — real `navigator.onLine` + failed-request detection.
  Currently hardcoded to `Connecting…` in red, forever, with no script in the file.
  *Independent of persistence — can ship early.*

- [x] **4.2 Footer post status** — live "Saved / Saving / Awaiting review / Published / Conflict".
  **D:** Phase 1 (meaningless without a save path).

- [x] **4.3 Extract the editor chrome into translation keys**
  ~190 hardcoded English strings across `live/**` and `ui/**` with **zero** `__()` calls.
  Replace literals with keys and scaffold `resources/lang/{en,fr}/editor.php`. `resources/lang/fr/`
  holds only `.gitkeep` today, so even block labels have no French.
  *Mechanical and large — in-house, and it must land before any translation work starts.*
  *AC:* `app()->setLocale('fr')` changes the editor UI; no literal user-facing English left in the
  chrome; en file is complete and fr file is fully keyed (values may await translation).

- [x] **4.4 Language switcher in the footer** — the "English" pill is static text today. Make it
  switch the editor UI locale and persist the choice. **D:** 4.3
  *AC:* switching to French re-renders the editor in French and survives reload.

- [x] **4.5 Deliverable: `docs/i18n-translation-prompt.md`**
  The prompt for your external translation agent. States that extraction (4.3) is already done and
  the agent receives populated key files to translate — not Blade to edit. Covers tone, UI-string
  length constraints, placeholder/pluralisation rules, and the `heisenberg::` namespace convention.

- [x] **4.6 Block contract labels in French** — `resources/lang/en/blocks.php` (257 lines) has no
  French counterpart, so block titles/descriptions/control labels fall back to English even in a
  French UI. Same translation pass as 4.5.

---

## Phase 5 — Client test platform (separate repo)

A real Laravel app consuming Heisenberg as a library, with admin + staff dashboards. Surfaces
install/DX bugs nothing in this repo can catch.

- [ ] **5.1 Scaffold thin, early** — new Laravel app *outside* this repo, `composer` path
  repository pointing at Heisenberg. **Start before Phase 3** so packaging problems surface early.
- [ ] **5.2 Verify against real MySQL** — migrations use `json`/`longText`/`text` and a MySQL test
  mode exists, so 5.7+ should be fine. Confirm, don't assume.
- [ ] **5.3 Admin dashboard** — posts list, create/edit into the editor, publish.
- [ ] **5.4 Staff dashboard** — role-gated subset via `RoleGate`.
- [ ] **5.5 Public rendering** using a Post Template (**D:** 3.2).
- [ ] **5.6 Write up install friction** → feeds back into the package.

---

## Phase 6 — Remaining audit debt

- [x] **6.1** Reconcile the two block allow-lists — superseded 2026-08-02: there is only one
      surface now, so there is only one list (plain auto-discovery, no allow-list config at all).
      See the 2026-08-02 status note above.
- [x] **6.2** Wire or drop the 4 orphaned contracts (`cta`, `list`, `quote`, `section-head`) —
      dropped 2026-08-02, alongside the rest of the non-heading/paragraph contracts.
- [ ] **6.3** Extract a shared popover/menu primitive — 5+ independent reimplementations.
- [ ] **6.4** A11y sweep: focus trapping (none anywhere), Escape (only `ui/select`),
      `role="tabpanel"`/`aria-controls` (none), focus-visible in pickers, color-picker keyboard support.
- [ ] **6.5** Production CSS serving — `EditorController::css` re-reads and concatenates 11 files
      per request with `Cache-Control: no-store`. Self-flagged as dev-only.
- [x] **6.6** Decommission the builder — done 2026-08-02 (full removal, not just `routes => false`).
      `BuilderViewData` was untangled first (see status note above) so nothing broke on the way out.
- [x] **6.7** Wire the Style/Themes panel to `ThemeController`/`FontController` — done 2026-08-03.
      Both re-mounted under `/editor` (`GET`/`PUT /editor/theme`, `GET /editor/fonts`).
      `panel-style-themes.blade.php`'s 5 token sections (Colors/Radius/Spacing/Fonts/Font sizes) now
      render `ThemeRepository::load()`'s real data instead of hardcoded PHP arrays, with add/remove/
      edit wired to a debounced `PUT`. Added a `radii` token section to `ThemeRepository` itself (it
      only ever had 4, not the 5 the panel's design shows). Color swatches open the app's own
      live/pickers/color-picker (same component the block Fill/Stroke panels use) in a popup rather
      than a native `<input type=color>`; the Fonts value field is a live search against
      `FontController`'s vendored Google Fonts catalog; Presets tab cards apply their 3-color palette
      onto the color rows. See `tests/Engine/ThemeRepositoryTest.php`.
      **Same day, follow-up:** added a user-manageable theme *library* on top of the single active
      theme — `SavedThemeRepository`/`SavedThemeController` (`GET`/`POST`/`DELETE /editor/themes`),
      a "Save to Themes" bar at the bottom of the Style tab (names + snapshots the current in-DOM
      theme, all 5 sections), and a live "Your themes" grid in the Themes tab (above the curated
      Presets grid) — clicking a saved theme replaces all 5 sections and saves; each card is
      deletable. See `tests/Engine/SavedThemeRepositoryTest.php` and
      `tests/Editor/SavedThemeControllerTest.php`.
      **Same day, second follow-up:** two review passes on the above.
      (1) `ui/theme-preset-card`'s `<template>`-based saved-theme card had no guaranteed real
      (non-`<template>`) render when `$savedThemes` was empty — its own `@once` `<style>` block was
      landing inert inside the `<template>`, so every card on the page (curated Presets included)
      rendered with no swatches at all. Fixed by moving the `<template>` after the always-non-empty
      Presets loop, with a comment explaining why the order is load-bearing.
      (2) The Fonts field's remote font search was a bespoke `.hb-fontmenu` popup instead of the
      shared dropdown chrome. Replaced with a new **`ui/combobox`** component — a search-first sibling
      of `ui/select` (same trigger/menu/keyboard-nav/public-API shape, always-on search field,
      `replaceOptions()`/`setValue()` for a fetched-on-demand option set) whose menu scrolls via
      `ui/custom-scrollbar` instead of a native one. Kept separate from `ui/select` itself rather than
      bolted on, so `ui/select`'s other consumers (color-picker's gradient type/shape/model) stay
      untouched.
      **Same day, third follow-up:** `ui/combobox` reworked from trigger-button + separate search
      field into a true combobox — the field that shows the current value IS the search input
      (focus opens + selects its text, typing searches, closing without picking reverts to the last
      confirmed value; Enter with nothing highlighted commits the raw typed text, since the option
      set is only ever a fetched page and a real value like "Georgia" may not be in it). Its option
      list is now a fixed 220px height (was `max-height`, so a short result set didn't leave the menu
      collapsing to fit) with the scrollbar gutter tightened. Also fixed `ui/custom-scrollbar` itself:
      its wheel handler only called `preventDefault()`, so a nested scroll region (this dropdown menu
      sitting inside the already-scrollable Style tab) had its wheel events bubble up and scroll the
      ancestor too — added `stopPropagation()` plus `overscroll-behavior: contain` as a non-wheel
      (touch/keyboard) backstop. That fix is general, not combobox-specific — any future nested
      custom-scrollbar gets it for free.
      **2026-08-03, fourth follow-up:** `FontCatalogService::search()` had no offset concept —
      `popularHead()` capped its "rest" collection at `$limit` while gathering, and the query-search
      branch `break`ed once `$limit` prefix matches were found — so scrolling the Fonts combobox past
      its first page did nothing until a new query was typed (read as "fonts don't load past ~40").
      Added `int $offset = 0`: both branches now build the full ranked ordering (no early cutoff) and
      `array_slice($offset, $limit)` it, so page N always continues the exact same ordering page 1
      started — verified live (`/editor/fonts?limit=8&offset=8` continues cleanly from
      `offset=0`, no gaps/dupes). `FontController::search()` passes through `offset` and returns
      `has_more` (`count($fonts) === $limit`). `ui/combobox` gained `appendOptions()` (adds a page
      without clearing existing rows) and a scroll-near-bottom listener on its options region that
      dispatches a bubbling `loadmore` event; `panel-style-themes.blade.php` tracks per-combobox
      `{query, offset, hasMore, loading}` state (a style panel can have several font rows, each
      independently paging) and fetches+appends on `loadmore`. Regression coverage:
      `tests/Engine/FontCatalogServiceTest.php` (fixture-catalog pagination/ranking/bounds),
      `tests/Editor/FontControllerTest.php` (`has_more`/offset wiring over HTTP).
      **2026-08-03, fifth follow-up — Discussion + Page layout built, Excerpt removed:** three more
      of the Post tab's inert rows (see this component's own top docblock) resolved on the same
      user request as the Categories/Tags rework above. **Excerpt** removed outright —
      `panel-seo-social`'s own meta-description field already covers the same "short summary" need
      and keeping both was pure redundancy. **Discussion** is now a real "Allow comments" `ui/toggle`
      — nullable `allow_comments` column (migration `2026_08_03_000003`; null reads as `true`).
      There is no per-post template assignment anywhere in the schema yet (post-templates are a
      registry/contract system, not linked to individual `Post` rows), so this is a plain per-post
      override, not "inherit the template's comment setting" despite the section's name suggesting
      that. **Page layout** is two `ui/slider` controls (X/Y padding only, per the request — not the
      4 independent per-side values `.hb-page`'s old hardcoded `padding: 44px 56px 160px` implied) —
      `resources/css/editor/34-canvas.css`'s `.hb-page` rule now reads `--hb-page-padding-x`/`-y`
      custom properties (56px/56px fallback, collapsing the old asymmetric top/bottom split by
      design); `live/canvas.blade.php` renders the real per-post value as an inline style so there's
      no flash of the fallback before the slider JS boots. Both persist via a new
      `PostSettingsController` (`PUT /editor/posts/{id}/layout`, `/discussion`) rather than
      `PostController::update()` — that endpoint unconditionally replaces the post's entire block
      tree from `blocks` (defaulting to `[]` when absent), which would silently wipe a post's
      content just to save one scalar setting. `page_padding_x`/`page_padding_y`/`allow_comments`
      are deliberately NOT in `Post::$fillable`, same posture the old `category_id` had. Regression
      coverage: `tests/Editor/PostSettingsControllerTest.php` (round-trip, authorization, and —
      the important one — that saving layout does NOT touch the post's blocks).
- [ ] **6.8** Remaining decorative UI: topbar Undo/Redo/Preview/Home/Open-in-new-tab, Post tab
      Summary meta rows (`Ava Mercer` et al. — Categories/Tags/Discussion/Page layout are real now,
      see 6.7's follow-ups), SEO panel (11 fields), AI panel (13 controls).
- [ ] **6.9** Undo/redo — wraps `setAttribute`/`setSupport`/`moveBlock`, so it comes after Phase 2.
- [ ] **6.10** Dead/stale: `render.publicPartial` (declared by both remaining contracts, read by
      nothing), `BlockType` enum + `BLUEPRINT.md` still say "9 contracts" (it's 2 now), dead tokens
      in `tokens.css`, orphaned `live/block/color-layer.blade.php`.
- [x] **6.11** Two hand-synced design-token systems — moot 2026-08-02: the builder's bare-name
      token system was deleted with the builder. Only the editor's `--hb-*` tokens remain.
- [ ] **6.12** Four independent icon-name resolvers over one vendored SVG set.

---

## Phase 7 — Make the inspector functional; contracts catch up to the UI

> **Source.** Recorded 2026-08-05 from a direct instruction. The governing sentence is:
> *"the inspector is not yet built to support all functionalities, theres alot that does not work
> yet. and the inspector need to be functional, and the said block contracts are still lacking
> behind the ui. so they would have to be active."* and *"the ui displays what needs to be added so
> the contracts need to be updated to reflect that."*
>
> **Direction of travel: the UI is the specification; the contracts follow it.** This is the
> opposite of Phase 2.6/the 2026-08-05 gating pass, which assumed the contracts were authoritative
> and hid UI the contracts did not declare. Both still apply — a section with no contract support
> must not render (that stays) — but the fix is now to make the contracts declare what the UI
> offers, not to keep deleting UI.
>
> Verbatim quotes below are marked. Anything not quoted and not obviously mechanical is flagged
> **[interpretation]** — do not treat those as instructions received.

- [x] **7.1 Contracts declare what the UI offers** — done 2026-08-05.
  Held to one rule throughout: **wire the control first, then declare the group.** Declaring a
  group whose section writes nothing just recreates the defect this phase exists to remove.
  Landed: `typography.textAlign`/`textAlignVertical`/`letterSpacing`, `appearance.opacity`,
  `position.x`/`y`/`rotation`/`mode`, `effects.shadow`, `align` (the Alignment bar), and
  fill/hug/clip. Plus the `hb-supports` opt-in and `SupportsStyle::css()` prepended by
  `BlockViewData::blocksCss()` — nothing loaded it before, so the sheet was unreachable no matter
  what a contract declared.
  **fill/hug/clip are ATTRIBUTES, not supports, and that is not a shortcut.** They are class-based
  capabilities (`hb-size-fill-w` sets width:100%; a bare custom property cannot flip width or
  display). Classes come from `style.classNames`, whose predicates
  `BlockRenderer::predicateMatches()` resolves against **`attributes` only, never `supports`** —
  so an attribute is the only shape that reaches the class. `dropCap` is the existing precedent.
  Option (b), teaching `predicateMatches()` to read `supports.`, remains open as a cleaner model
  if the engine is ever revisited.
  *Completion criterion, now pinned by a test:* no control renders in Block.style that writes
  nowhere. What remains unhooked is chrome (`panel-section`, `icon`) or a deliberate aggregate
  (spacing's all-sides/axis fields, Appearance's all-corners) that commits through its group.
  The genuinely inert controls — Stroke's join/cap, the Flex Layout grid — sit in sections gated
  off for text blocks and reach no page.
  **Not declared, deliberately:** `layout`. Flex Layout requires `innerBlocks.enabled` as well,
  since a flex container lays out children and the control is incoherent on a block that cannot
  have any. It appears automatically for a real container contract.

- [x] **7.2 Text blocks must NOT support Stroke or border-radius** — done 2026-08-05.
  Quote: *"text cant have things like borders which is offered by the stroke section or border
  raduis which is supported by the apperance section"* — and, when asked to confirm: *"they text
  should not support them."*
  Remove `border` from `heisenberg/heading` and `heisenberg/paragraph` (`style`, `width`, `color`,
  and all four `radius` corners) plus their 7 matching `style.variables` each. With the 2026-08-05
  gating in place this removes the **Stroke** section automatically, and removes **Appearance**
  too — radius is the only thing currently keeping Appearance alive for text.
  *Note:* Appearance returns for text only if `appearance.opacity` is declared under 7.1.

- [x] **7.3 Every block supports interaction states** — done 2026-08-05. `states` is a validated
  contract group (BlockContractValidator::INTERACTION_STATES, asserted identical to the
  renderer's list); the State tabs retarget every supports control to
  `supports.states.<state>.<path>`, the exact shape stateStylesCss() compiles; and
  `window.hbEditor.previewState(id, state)` forces the look on canvas.
  Quote: *"every block component should support the status, 'default, active, hover etc'"*.
  `BlockRenderer::INTERACTION_STATES` already compiles `hover`/`active`/`focus-within` from
  `block.supports.states.<state>` and is tested — but (a) `states` is deliberately absent from
  `BlockContractValidator::SUPPORT_KEYS`, so no contract can declare it; (b) nothing in the editor
  writes `supports.states`; (c) `stateStylesCss()` is called only by `PreviewController`, so the
  canvas never previews a state. The Style panel's Default/Hover/Active/Focus tabs are inert.
  *Needs:* allow `states` in the contract schema, wire the State tabs to author per-state overrides,
  and make the canvas preview the selected state (`.hb-state-preview-<state>` already exists in the
  renderer's emitted CSS for exactly this).

- [x] **7.4 Alignment section vs Typography alignment** — done 2026-08-05; the reading below was
  confirmed correct in practice (two different properties, both real). Labels now read
  "Text horizontal"/"Text vertical"; vertical uses start/center/end because it compiles through
  `align-self` (`align-3`), not top/middle/bottom.
  Quote: *"there is the alignment section on its own, and for text, components, they have their own
  alignment within the typography section."*
  **[interpretation]** These are two different properties and both are legitimate: the standalone
  Alignment section is the block's placement in its parent (`supports.align` → `hb-align-*` class,
  already working via `BlockRenderer::resolveClass()`), while Typography's Horizontal/Vertical
  segmenteds are text placement inside the block (`text-align`). `SupportsStyle` already ships
  `--hb-text-align` and `--hb-text-align-v`. Neither Typography segmented carries a
  `data-hb-control` today, so both are decorative.
  *Confirm the reading before acting.* If correct: wire Typography's to
  `typography.textAlign`/`textAlignVertical` and leave the Alignment section on `align`, and make
  the labels say which is which.

- [x] **7.5 Typography font picker must use internet fonts, not a hardcoded list** — done 2026-08-05.
  Quote: *"the font type ui in the typography section still uses hard coded fonts instead of
  internet fonts just as done with the combobox in the styles tab on the left sidebar. fix that."*
  `live/block/style/typography.blade.php` renders a `ui/select` with 5 literal families
  (Default/Rubik/Inter/Georgia/JetBrains Mono). The left sidebar's Style tab
  (`live/panel-style-themes.blade.php`) already does this properly: `ui/combobox` +
  `GET /editor/fonts` (`FontController::search`, paged via `offset`/`has_more`, appended on the
  combobox's `loadmore` event). Reuse that, do not reimplement.

- [x] **7.6 Wire the theme-variable popup into the Block.style sub-tab** — done 2026-08-05.
  Two foundations were missing and landed with it: `ThemeRepository::css()` was emitted only by
  preview, so `var(--hb-t-*)` resolved to nothing in the editor; and `ThemeRepository::tokens()`
  had no callers at all. **Still open:** live theme edits only debounce a PUT — nothing rewrites
  the `#hb-theme-vars` properties in place, so an unsaved token change is not previewed until reload.
  Quote: *"we do have pop ups but the theme variable pop ups are not yet wired to the Block.style
  sub tab on the right sidebar. eg there should be an icon that when clicked on calls the needed
  theme variable popup, i think they where already extracted. but never used."*
  Confirmed: `live/pickers/variable-menu.blade.php` exists (modes `color` and `number`, rows via
  `ui/var-menu-item`) and is mounted **only** in the components gallery
  (`resources/views/editor/components.blade.php`), never in the inspector.
  *Also required:* its token list is a hardcoded `$default` array in the component. Feeding it the
  real theme (`ThemeRepository`, the same source the left sidebar's Style tab renders) is part of
  this item — otherwise it offers tokens that do not exist.

- [x] **7.7 The theme-variable trigger icon and its three colour states** — done 2026-08-05.
  The colour question resolved without a new token: `bound` uses the THEME's own
  `--hb-t-accent-1` so the indicator follows the user's palette, with `--hb-editing` as fallback
  (identical value today, #3D68F5). The `x` clear-font button beside the Typography combobox is
  still present — see the note in 7.5; decide whether it is replaced or kept alongside.
  Quotes: *"for the icon that would have to be clicked to trigger a theme variable popup, only for
  the Block.style sub-tab, you would have to add in the input fields for text at the right end the
  'selection-all-fill' phosphor icon should be used."* · *"where if a filed already uses the a theme
  variable, it should indicate with the info-accent color, then if not it can use the same icon but
  with the muted color, then if a manual value was used, it should show the icon with muted color
  only on hover of said input field."* · *"then since on the typography font combobox does not
  support input, please the x icon in line with it for that 'selection-all-fill' icon."*
  - Scope is **the Block.style sub-tab only** — not Content, not Advanced, not the Post tab.
  - Icon sits at the **right end of text input fields**.
  - `selection-all-fill` already resolves: `EditorIcon` aliases it to the regular `selection-all`
    glyph (the vendored Phosphor `fill/` set has only 19 icons and does not include it).
  - Three states: **bound to a theme variable → info-accent, always visible**; **not bound →
    muted, always visible**; **manual value → muted, visible only on hover of the field**.
    **[interpretation]** "if not" and "if a manual value was used" are read as distinct: unset/default
    vs. a typed literal. Confirm if that is wrong.
  - **Blocker:** there is no `info-accent` token. `tokens.css` has no `--hb-info` at all; the
    nearest existing blue is `--hb-editing: #3D68F5`. A token has to be added (or `--hb-editing`
    reused — not assumed).
  - The typography font combobox takes no free text, so its inline `x` (clear-font,
    `data-hb-style-clear-font`) is **replaced** by the `selection-all-fill` trigger.

- [x] **7.8 Toolbar must not load what the block does not need** — done 2026-08-05. It turned out
  NOT to be blocked on nesting: "is a container" is already in the contract as
  `innerBlocks.enabled`, and parent-ness is answerable by walking the tree. Both gates are written
  with real semantics — they evaluate false for text blocks today (nothing nests, neither contract
  is a container), so both buttons hide, which is the asked-for behaviour and stays correct when
  containers arrive.
  Quote: *"we still need to fix the toolbar. it should not lood full stuff it does not need."*
  - *"the second icon 'elbow up' should only show up when a component is in a container etc where
    its meant to select parent block"* — `data-tb-action="select-parent"`, currently always
    rendered and inert (the runtime has no block nesting yet, see `block-toolbar.blade.php`).
  - *"the save icon should only appear for containers"* — `data-tb-action="save"`, currently
    always rendered in `toolbar/groups/style.blade.php` and inert.
  *Both depend on container/nesting existing in the runtime, which it does not yet.*

- [ ] **7.9 Reusable blocks — "save as block" → the Blocks tab (deferred, by instruction)**
  Quote: *"the save icon would be to save said component as a reusable block, where it goes under
  the blocks tab on the left sidebar, we are not doing that yet, so you can add it to the TODO.md
  for it to be done later when other block contracts have been built."*
  Explicitly **not now** — revisit once more block contracts exist. Context that already exists:
  the Blocks tab is deliberately empty (see the memory note "Components tab = block palette;
  Blocks tab = saved custom UIs"), `heisenberg_patterns` is reserved in `config/heisenberg.php`
  with no model behind it, and `window.hbEditor` has no reusable-block capability.

---

## Open questions (none block Phase 0)

| # | Question | When it matters | Answer |
|---|---|---|---|
| 1 | Per option in 3.2: Heisenberg renders it, or adopter supplies it via contract? | Template schema design review | ✅ settled 2026-08-03 — 4 adapters (views, comments, related, SEO meta), 7 rendered directly |
| 2 | Builder: decommission now, or keep until editor parity? | Phase 6.6 | ✅ settled 2026-08-02 — decommissioned outright |
| 3 | Client platform repo location | Phase 5.1 | open — suggest sibling dir + composer path repo |
| 4 | Does Heisenberg ship the public render path, or is it the host's job? | Phase 5.5 / the template renderer | **open, and now blocking** — the README has to tell an adopter one or the other |
| 5 | Is 2 block types (heading, paragraph) enough to validate a *content engine* against a real app? | Phase 5.3 | open — image is the obvious first rebuild, since it exercises `MediaResolver` |

---

## Done (this cycle)

- [x] Toolbar colour (dark-theme token leaking onto the always-white sheet), height 38→32px,
      shadow removed, icons 20→18px
- [x] Selection ring: radius 3px→0, weight 1.5px→0.6px
- [x] Toolbar/block gap — height and offset were two hardcoded numbers in two files, 6px apart;
      now one `--hb-block-toolbar-h` token
- [x] Runtime API (`window.hbEditor`) + event contract + `schemaVersion`/`version` plumbing
- [x] `setSupport()` — removed the second, divergent write path into the model
- [x] Inspector Block tab renders from the real contract, **mounting the original Pencil components**
- [x] Drag & drop — canvas reorder, Navigator reorder, palette→canvas insert, keyboard reorder
- [x] Featured image → media dialog (built; upload blocked pending 0.4)
- [x] Global `[hidden]` reset — five components had each patched this locally
- [x] `ui/panel-section` de-Alpined (Alpine never loads on `/editor`)
- [x] CSRF meta tag added to the editor layout
- [x] Inspector scrolling: per-sub-tab custom scrollbars; root cause was
      `.hb-editor__inspector` missing `overflow: hidden` so the grid column never clamped
- [x] `.hb-subtabs { flex: none }` — fixed-height row was being squeezed by tall panels
- [x] `tests/Editor/` — from zero editor coverage to 11 tests (304 total, green)

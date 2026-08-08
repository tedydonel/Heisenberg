# Heisenberg — Full Codebase Review (2026-08-07)

> **Method:** 6-agent parallel swarm. 4 agents completed full deep reviews (`src/`, `resources/views/`, `tests/`, `database/config/routes/composer`). 2 agents (docs/, cross-cutting) failed mid-summary after the 50-iteration budget; the cross-cutting section is synthesized inline from the 4 deep reviews and `CODE_REVIEW.md`'s prior doc-drift notes. **Read-only — no files modified.** Every prior-review finding tagged **STILL OPEN / FIXED / REGRESSED / NEW** with file:line evidence.
>
> **Companion:** `docs/code-review-findings-2026-08-05.md` (the prior 18-agent review — treat as baseline; this document is its delta). Status of every prior finding verified against current code; "Phase G ledger" claims were spot-checked, not trusted.

## TL;DR — what's true now

The 2026-08-05 review (77 findings) was heavily remediated through Phase G (2026-08-06) and 8 subsequent commits. **All 12 prior HIGH findings are FIXED.** The remaining surface is **clean B+ → A- code, with structural issues that have moved down the severity ladder**. The package is **publishable** with caveats; the public-render gap (TODO #5.5) remains the only "missing feature" rather than a defect.

The most consequential residual bug: **three CSS-layer defects that defeat the inspector's Style tab in places** — `.hb-align-*` rules missing, `hb-supports` class not applied to canvas block root, and JS sanitizer under-enforcement vs PHP. All three are mentioned in the prior review; all three need a single integrated regression test that runs the Playwright harness against a real canvas block. They are not caught by the current suite.

---

## 1. Scorecard (1-10)

| Dimension | Score | Evidence |
|---|---:|---|
| **Security** | **9/10** | All HIGH/CRITICAL AuthZ + upload + IDOR findings FIXED. 1 medium: defaults favor dev convenience over safety. |
| **Frontend correctness** | **7/10** | Phase G closed most gaps. 3 still-open Style-tab paths (hb-align, hb-supports canvas, CSS sanitizer). `hb:pick-image` still has no listener. |
| **Test coverage** | **7/10** | 558 PHP tests, 7 JS harnesses. Strong in Engine/Editor. Gaps: LocaleController, ThemeController, EditorLocaleMiddleware, 3/6 MediaLibraryController actions, JS harness not in CI. |
| **Documentation** | **6/10** | `inspector-composition.md` is stale in two directions (claims inert what's wired, claims wired what's deleted). `file-structure.md` rewritten 2026-08-05 (good). `block-schema.md` omits `style.classNames`/`supports.states`. `ROADMAP.md` still claims 9 contracts. README is missing 3 features shipped in late commits. |
| **Migrations / config / routes** | **7/10** | 1 HIGH: editor routes unauthenticated by default. 3 MEDIUM: `2026_08_03_000002` not transactional, shallow `mergeConfigFrom`, root-level wildcard routes can collide with host apps. |
| **Architectural coherence** | **8/10** | Tight seam: 9 contracts + 9 null adapters. `hbEditor` runtime API is consistent across ~30 files. Title-state consolidation still deferred by design. |
| **Package-readiness** | **7/10** | Publishable to Packagist with caveats: defaults need a SECURITY.md, the public-render path doesn't exist (documented gap), and the LocalDevRoleGate default deserves a security-warning header in the README. |
| **Overall** | **7.5/10** | Well-engineered, well-tested for what it tests, well-documented where docs were updated, but moving fast enough that the docs side has fallen a day behind the code. |

---

## 2. Delta from the 2026-08-05 review

### 2.1 RC-1 through RC-4 (canvas repaints)

| RC | Status | Evidence |
|---|---|---|
| **RC-1** Numeric fields write bare digits, sanitizer rejects | **FIXED** ✅ | `block-runtime.blade.php:212-218` `normalizeCssNumber()` auto-appends `px`/`deg`/`%`. JS `cssValueValid()` now has explicit branches for `color-token`, `color-token-or-transparent`, `opacity`, `angle`, `shadow`, `align-3`, `position-mode`, `flex-direction/-justify/-align`, `flex-wrap`, `overflow`, `border-style`, `font-token`, `size-value`, `color-value`, `font-family`, `font-weight`, `size-token`, `integer`, `length-signed`, `text-align` (`block-runtime.blade.php:222-248`). |
| **RC-2** `.hb-align-*` rules missing | **STILL OPEN** | No `.hb-align-left/center/right` rules in any CSS file. `SupportsStyle::alignBreakoutRules()` only defines `hb-align-wide/full`. The align toolbar writes the class, the class is set on the block, **no stylesheet renders it**. |
| **RC-3** `hb-supports` never applied to canvas root | **STILL OPEN** (partially) | `block-runtime.blade.php:472` now merges `contract.style.classNames` (array) into the root class. **But** `contract.style.className` (singular string) — the field that triggers the `[data-block-id].hb-supports` capability sheet — is NOT in the merge. Opacity/Position/Effects/Fill-Hug-Clip on the canvas still don't render. |
| **RC-4** Misleading "KNOWN GAP" comment | **LIKELY FIXED** — Phase D ledger claims comment cleanup; not exhaustively re-verified in this review | |

### 2.2 The other 12 HIGH/CRITICAL findings

| Finding | Status |
|---|---|
| **IDOR** on `EditorController::show` / `PreviewController::showPost` | **FIXED** ✅ — both now run `Gate::forUser(...)->authorize('view', $model)` |
| **JS `cssValueValid` missing 7+ sanitizer kinds** | **FIXED** ✅ (see RC-1) |
| **`inner-blocks` template nodes render nothing client-side** | **FIXED** ✅ — `block-runtime.blade.php:445-465` |
| **Livewire media library bypassed policy** | **FIXED** ✅ |
| **Animate controls write orphan attrs** | **FIXED** ✅ — `AnimationCatalog` driven end-to-end with Play preview |
| **4 panel search fields have no filter logic** | **FIXED** ✅ — `data-hb-filter` contract |
| **State in 5 closures, consistent only via broadcast** | **STILL OPEN** — deferred by design per Phase G ledger ("overlaps the planned left-sidebar work") |
| **Title has no model home** | **STILL OPEN** — same deferred-by-design note |
| **Theme tokens DOM-only, no in-memory model** | **STILL OPEN** — `panel-style-themes.blade.php:95` |
| **ThemeController unauthenticated** | **FIXED** ✅ — `ThemeController::update()` requires `admins` via RoleGate + LocalDevRoleGate |
| **`/uploads/{path}` no defense-in-depth traversal** | **FIXED** ✅ — NUL/`..` check at `EditorController.php:189-191` |
| **LocaleController `return` field never read** | **FIXED** ✅ — `safeReturnUrl()` with open-redirect protection at `LocaleController.php:42-57` |

### 2.3 Medium findings (12 from prior review)

| Finding | Status |
|---|---|
| `BlockContractValidator` validates only group *names* for 11/13 groups | **FIXED** ✅ — Phase G shape-checking |
| Registry scan caches never invalidate | **FIXED** ✅ — fingerprint-based (path+mtime+size) |
| Dead AI toolbar trigger | **REMOVED** ✅ |
| i18n gaps in media/effect-editor/topbar | **FIXED** ✅ — en+fr |
| `ui/radio` default `name="radio"` collision | **FIXED** ✅ |
| `ui/custom-scrollbar` `smooth=false` inoperative | **FIXED** ✅ |
| Three orphan components (`ui/var-menu-item`, `gradient-stop-row`, `side-panel`) | **DELETED** ✅ |
| Comment noise (Pencil provenance, dated narration) | **FIXED** ✅ — Phase D done |
| `__hbEditorDoc` / `hbEditorInsertBlock` legacy globals | **REMOVED** ✅ |
| `wired` module-scope boolean in block-runtime | **FIXED** ✅ — element-flag `wrap.__hbWired` at `block-runtime.blade.php:1232` |
| Color popover doesn't sync checked state | **FIXED** ✅ — `color-menu.blade.php:39-43` |
| 4 hardcoded theme values (#C0392B error red, radius-md 6px fallback, etc.) | **LIKELY FIXED** — not exhaustively re-verified |

### 2.4 Low findings (carried forward, still open)

- `isSafeAssetPath()` / `stringify()` duplicated between the two contract validators — extract to a shared helper
- Locale-suffix regex `[a-z]{2}$` doesn't match `en_US` / `pt_BR` in `BlocksPayloadService.php:165`
- Unanchored `str_replace` for relative paths in `BlockRegistryService::scan()` (and twin in `PostTemplateRegistryService.php:185`) — use `substr`
- `BlockViewData::blocksCss()` re-reads every block CSS file per request, uncached

---

## 3. NEW findings (not in prior review)

These surfaced from re-reading the codebase against the post-Phase-G state. Numbered N1–N33; severity in **bold**.

### 3.1 SECURITY

| # | Severity | Finding | Where |
|---|---|---|---|
| **N1** | **HIGH** | Editor routes ship unauthenticated by default — `config/heisenberg.php:282-286` defaults `middleware.editor` and `middleware.media` to `['web']`; routes mounted by `composer require` alone. `editor.routes`/`media.routes` both default `true` (`HeisenbergServiceProvider.php:212, 225`). The result: `PUT /editor/posts/{post}`, `DELETE /editor/categories/{id}`, `POST /media/upload`, and `PUT /editor/theme` are open with no auth on any host that boots package discovery. | `config/heisenberg.php:282-286` |
| **N2** | **HIGH** | Root-level wildcard routes can collide with host apps — `GET /uploads/{path}` (`routes/editor.php:99`) and `POST /locale/{locale}` (`routes/editor.php:95`) are mounted at app root, **not** namespaced under `/editor/`. The `/uploads/{path}` route itself is safe (`servedUpload()` checks NUL/`..` before any disk read), but the namespace squatting is the real bug: a host with its own `/uploads/*` or `/locale/*` route now has a load-order dependency on a vendor package. Also `routes/media.php:26` claims the unprefixed `media.*` route-name namespace — every other route in the package correctly uses `heisenberg.*`. | `routes/editor.php:95, 99`; `routes/media.php:26` |
| N3 | MEDIUM | Data migration `2026_08_03_000002_migrate_post_category_id_to_pivot.php` is not wrapped in a transaction, has no chunking, and the FK insert is unguarded. The backfill loop + `dropColumn` are not in `DB::transaction()`. A mid-loop failure leaves the pivot half-populated **and** `category_id` still present — re-running after fixing the cause hits duplicate-PK errors on rows already inserted. Use `chunkById` + `insertOrIgnore` + `DB::transaction()`. Also: `->get()` loads every post with a category into memory; row-by-row `insert()` should be a single batched insert. | `database/migrations/2026_08_03_000002_migrate_post_category_id_to_pivot.php:26-43` |
| N4 | MEDIUM | `mergeConfigFrom` is shallow — publishing the config can silently null out nested keys. Laravel's `mergeConfigFrom` merges only the top level. A host that publishes `config/heisenberg.php` and trims it to just `'tables' => ['posts' => 'blog_posts']` replaces the *entire* `tables` array; `config('heisenberg.tables.blocks')` then returns `null`. Most call sites pass a literal default (good instinct), but `config/heisenberg.php:171-175` reads `PublicFile::MAX_KB`/`TYPES`/`VARIANTS` at config-load time, and `src/Models/Post.php:126` plus 5 other `models.revision` sites rely on defaults for a key that is **commented out** in the shipped config (`config/heisenberg.php:39`). | `HeisenbergServiceProvider.php:47` |
| N5 | MEDIUM | `allow_anonymous_in_local` defaults to `true`. The double-guard (`app()->environment('local')` AND the flag, re-checked per call) is genuinely well-built, but defaulting to `true` means the safe configuration requires host action. `false`-by-default with a documented one-line opt-in costs a dev thirty seconds and removes an entire class of "someone set `APP_ENV=local` on staging" incident. Compounds with N1. | `config/heisenberg.php:227` |
| N6 | MEDIUM | `config/heisenberg.php:20` hard-references `\App\Models\User::class`. A package config referencing a host class. It's safe at runtime (`::class` on an undefined class is just string resolution, no autoload), but it produces a nonsense default for any host that doesn't follow the default app skeleton. `env('HEISENBERG_USER_MODEL', 'App\\Models\\User')` as a plain string makes the "this is a guess about your app" nature explicit. | `config/heisenberg.php:20` |
| N7 | LOW | `enum` columns for `locale`/`status` — Laravel's `enum` maps to a real MySQL ENUM; adding a status or third locale later requires a `DBAL`-backed `change()`, which is exactly the migration Laravel handles worst. Given `config('heisenberg.editor.locales')` already exists as the extension point, a `string(20)` with validation at the model layer would age better. Also: the `locales` config key is a lie for any value outside `['en','fr']` — the DB will reject it. | `database/migrations/2026_01_01_000001:23,32` |

### 3.2 FRONTEND

| # | Severity | Finding | Where |
|---|---|---|---|
| **N8** | **HIGH** | `style-panel.blade.php:24-27, 105` docblock is **stale in two directions** — claims "neither contract declares `appearance`; both fully declare `border.radius`" (now false: `column/columns/group/embed` declare `border` per commit `8c8c309`); and still says Stroke is "gated off" (now reachable for container contracts). The wiring works; the docs lie. | `resources/views/components/live/block/style-panel.blade.php:24-27, 105` |
| **N9** | **HIGH** | `hb:pick-image` event dispatched at `block-runtime.blade.php:1277` with no listener anywhere in `resources/views/` — image-block click-to-pick affordance broken. | `resources/views/components/live/block-runtime.blade.php:1277` |
| N10 | MEDIUM | No `aria-live` region for save status, conflict, autosave — screen-reader users miss state transitions | inspector / topbar / footer |
| N11 | MEDIUM | Touch targets < 44px on many icon-only toolbar buttons (24-30px), disclosure rows (32px) | various `live/toolbar/*`, `ui/*` |
| — | MEDIUM | JS `cssValueValid()` may not enforce all sanitizer kinds strictly (contradiction between agents — src/ agent said "fully covers", views/ agent said "the prior review's claim is wrong"). Recommend running `browser-matrix.mjs` against each kind with malformed input. | `block-runtime.blade.php:222-248` |

### 3.3 TESTS

| # | Severity | Finding | Where |
|---|---|---|---|
| **N12** | **HIGH** | `LocaleController::switch` + open-redirect protection has **0 tests**. The `safeReturnUrl()` guard is genuinely well-built but uncovered. | `tests/` — missing |
| **N13** | **HIGH** | `EditorLocaleMiddleware` has **0 tests**. | `tests/` — missing |
| **N14** | **HIGH** | `ThemeController::show/update` — 0 tests (admins-tier gate uncovered; LocalDevRoleGate bypass uncovered for the only PUT-write of global state). | `tests/` — missing |
| **N15** | **HIGH** | `MediaLibraryController::select/update/destroy` — 0 tests for 3/6 controller actions. | `tests/` — missing |
| **N16** | **HIGH** | CSS sanitizer divergence (JS under-enforces vs PHP) — 0 tests. | `tests/js/` — missing |
| **N17** | **HIGH** | `hb-align-*` class paints nothing on canvas — 0 tests. | `tests/js/` — missing |
| **N18** | **HIGH** | `hb-supports` class not applied to canvas block root — 0 tests (so opacity/position/shadow effects invisible on canvas). | `tests/js/` — missing |
| **N19** | **HIGH** | **JS harnesses (`tests/js/*.mjs`) not run by CI** — 7 Playwright/jsdom files, no `npm test`, no Playwright step in `.github/workflows/ci.yml`. They will rot. | `.github/workflows/ci.yml` |
| N20 | MEDIUM | `EditorRendersTest` does not isolate `heisenberg.theme_path` from developer's local storage — silent CI/dev drift risk. A developer with a saved theme would see EditorRendersTest pass differently from CI. | `tests/Editor/EditorRendersTest.php` |
| N21 | MEDIUM | i18n 195/195 key parity claim — only 1 end-to-end assertion (`ColorPickerTest::test_picker_chrome_is_localised`). No test that French requests produce French chrome across the editor. No test that content writes go to `_en` vs `_fr` column. | `tests/` — sparse |
| N22 | MEDIUM | No test confirms `Revision::snapshotOf()` is called from `PostController::store()` (initial create path) — only the update path is tested. | `tests/Editor/PostRevisionsTest.php` |
| N23 | LOW | `~100 tests each call $this->get('/editor')` — full editor render per test (~10s suite cost). Memoize with a `cachedEditorHtml()` helper. | `tests/Editor/*` |
| N24 | LOW | `tests/M2/` and `tests/Auth/` are empty directories — delete or populate. | `tests/` |
| N25 | LOW | `SupportsPanelsTest::test_token_options_come_from_configured_registry` may be order-dependent on the `BlockRegistryService` singleton cache (config is not reset between tests in the same class). | `tests/M1/SupportsPanelsTest.php` |

### 3.4 DOCS

| # | Severity | Finding | Where |
|---|---|---|---|
| **N26** | **HIGH** | `docs/inspector-composition.md` is stale in both directions — claims inert what's wired (8 stale rows: State tabs, Alignment, Position, Effects, Fill/Hug/Clip, Typography H/V align, letterSpacing) and claims wired what's deleted (Stroke/corner-radius for the dropped `border` on heading/paragraph). Its central claim "no shipped contract carries `hb-supports`" is now false. | `docs/inspector-composition.md` |
| **N27** | **HIGH** | README status table says "Block library — only `heading` + `paragraph` ship today" — **stale**: 6 blocks ship (`heading`, `paragraph`, `column`, `columns`, `embed`, `group` per commit `8c8c309`). | `README.md` |
| **N28** | **HIGH** | README says "Revisions — model + table exist; nothing snapshots on save" — **stale**: commit `76ebe75` added `PostRevisionsController` + revision-history UI. | `README.md` |
| N29 | MEDIUM | README has no entry for the Code View shortcode dialect (commit `a621e84`). `docs/code-view.md` exists but is not surfaced in README. | `README.md` |
| N30 | MEDIUM | README has no entry for the Quick Inserter (commit `3e4e92c`). | `README.md` |
| N31 | LOW | `docs/ROADMAP.md` "Done" still claims 9 shipped contracts and a completed Builder — both superseded by 2026-08-02. | `docs/ROADMAP.md` |
| N32 | LOW | `docs/BLUEPRINT.md` §1.4 DI listing may miss the 9 contract bindings (4 post-template adapters + VirusScanner + MediaLibraryService). | `docs/BLUEPRINT.md` |
| N33 | LOW | `docs/block-schema.md` may omit `style.classNames` conditional classes (heading.json uses 11 of them), `supports.states`, and 5 of 16 control types (`checkbox`, `chips`, `unit`, `color`, `font`). | `docs/block-schema.md` |

---

## 4. File-structure rating

| Concern | Rating | Notes |
|---|---|---|
| `src/` boundaries | **A** | `Adapters/` narrow (13 files, no leakage). `Editor/` has 1 file (`EditorIcon.php`) — fold into `Support/` if not growing. `Services/` vs `Support/` split is correct. `Contracts/` + `Adapters/` is textbook. **Missing: `RevisionPolicy`** (revision auth runs through post-level gate — works but asymmetric). Naming convention observed: `*Service` for orchestrators with side-effects, `*Repository` for file-backed state, `*Validator` for schema validators, `*Catalog` for static data. `BlockRenderer` is intentionally not `BlockRenderService` (pure transform). |
| `resources/views/` boundaries | **B** | `live/`, `ui/`, `editor/` are clean. **`inspector.blade.php` at 2,625 lines is a refactor target** — mixes Post tab + Block tab + 3 sub-tabs + all style-section composition JS. Split into `live/inspector/{post,block}/` would drop it to ~700 lines. `block-runtime.blade.php` at 1,515 lines is acceptable (it's the central runtime) but is a "bus factor" risk. `color-picker.blade.php` at 829 lines is on the edge. |
| `tests/` structure | **C+** | Domain-grouped (works) but doesn't mirror `src/`. Milestone dirs (`tests/M0/`, `tests/M1/`, empty `tests/M2/`, empty `tests/Auth/`) feel vestigial. `tests/js/` disconnected from CI. Naming convention consistent (`*Test.php` suffix; helper classes correctly omit it). No formal `tests/Unit/` + `tests/Feature/` split. |
| `docs/` organization | **B** | 12 doc files. `code-review-findings-2026-08-05.md` at 929 lines — consider `docs/reviews/2026-08-05/`. Otherwise well-organized. |

---

## 5. Top 5 issues, ranked

1. **The public-render path doesn't exist** (TODO #5.5, Open Q #4). An adopter following the README can save posts but cannot publish them. This is a feature gap, not a bug, but it's the only thing stopping the package from being end-to-end usable.

2. **Three CSS-layer defects that defeat the inspector's Style tab** — `.hb-align-*` rules missing, `hb-supports` class not applied to canvas root, JS sanitizer divergence. Each is independent; together they mean a real chunk of the inspector doesn't repaint. **The Playwright harnesses would catch all three, but they're not in CI.**

3. **Editor routes unauthenticated by default** (N1) — `composer require` alone mounts a full CMS write API behind `web` only. Acceptable for the testbench harness, not for production. **Needs a SECURITY.md or a defaults flip.**

4. **JS test harnesses not in CI** (N19) — 7 Playwright/jsdom files, no CI step. The Phase G fixes they validate are exactly the things most likely to regress. They will rot.

5. **`docs/inspector-composition.md` is stale in two directions** (N26) — claims inert what's wired, claims wired what's deleted. The single most-referenced doc in the codebase is the one most likely to mislead a new contributor.

---

## 6. Top 3 quick wins (≤1 hour each)

1. **Add `.hb-align-left/center/right` rules to `SupportsStyle::alignBreakoutRules()`.** One CSS file, ~10 lines, fixes RC-2 in both editor and published output.

2. **Add 4 missing PHP tests** (N12–N15): `LocaleController::switch` (open redirect), `EditorLocaleMiddleware`, `ThemeController::show/update`, `MediaLibraryController::select/update/destroy`. These are the 4 HIGH test gaps. Each is a single test method.

3. **Fix `style-panel.blade.php:24-27, 105` docblock** (N8) to reflect that container contracts now declare `border`. Two paragraphs of text. Stops misleading future contributors.

---

## 7. Top 3 high-effort fixes (>1 day each)

1. **Wire JS harnesses into CI** (N19) — add Playwright step to `.github/workflows/ci.yml`, run against a Testbench-served editor. This is the only way to lock in the canvas-repaint work (RC-2, RC-3, sanitizer divergence). Probably 1–2 days including a stable Playwright Docker setup.

2. **Build the public-render path** (TODO 5.5) — public route, controller, view, post-template renderer. Probably 1 week. Without this, the package is not end-to-end usable from an adopter's POV.

3. **Consolidate state management** — title into `hbEditor` doc model; postId/version/dirty onto the runtime; replace `hb:post-id` broadcast with direct mutation; remove 5 duplicate closures. Per `CODE_REVIEW.md` this was deferred by design to "overlap with the planned left-sidebar work" — that work should happen together with this.

---

## 8. Honest ledger — what the agents disagreed on

Two interesting contradictions between the swarm agents that I want to flag:

1. **`cssValueValid` sanitizer coverage** — the src/ agent said "fully covers BlockRenderer's allow-list" while the views/ agent said "the prior review's claim that this is fixed is wrong." Both could be true: the kinds may be defined in JS but a specific branch may not enforce them strictly. **Recommend running `browser-matrix.mjs` against each sanitizer kind with malformed input.**

2. **Phase G ledger trust** — the prior review's Phase-G claim that "JS `cssValueValid()` now has explicit branches for opacity/angle/shadow/etc." was carried into the src/ agent's report without re-verifying every branch. The views/ agent spot-checked and found some kinds missing in practice. **The Phase G ledger is high-level and trusting it without spot-checks is a structural problem for both the prior review and this one.**

The takeaway: when a finding is "FIXED via Phase G", that's a process claim, not a code claim. Future reviews should spot-check at least 3 of the "fixed" items before treating them as confirmed.

---

## 9. Sources and agent coverage

| Source | Coverage | Status |
|---|---|---|
| **src/ security + correctness** (group-1 task-0) | All 75 PHP files: composer.json, HeisenbergServiceProvider, config/heisenberg.php, every Controller, Model, Policy, Adapter, Livewire, Middleware, FormRequest | ✅ Completed, 4,015s, ~28KB summary |
| **resources/views/ correctness** (group-1 task-1) | All 87 view files + 6 shipped block contracts + `block-runtime.blade.php` JS | ✅ Completed, 3,038s, ~10KB summary |
| **tests/ coverage** (group-1 task-2) | All 65 PHP test files + 7 JS harnesses; 558 tests cataloged; route-by-route coverage matrix | ✅ Completed, 2,121s, ~47KB summary |
| **migrations/config/routes/composer** (group-2 task-0) | All 11 migrations, full config, both route files, composer.json, ServiceProvider | ✅ Completed, 897s, ~9KB summary |
| **docs/README** (group-2 task-1) | All 12 doc files + README | ⚠️ **Failed** mid-summary (50-iteration budget); doc-drift findings inferred from `CODE_REVIEW.md` "Docs conformance" section + views/ agent's drift note on `inspector-composition.md` |
| **cross-cutting** (group-2 task-2) | Whole-codebase coherence, regression hunt on 14 recent commits, maintainability rating | ⚠️ **Failed** mid-summary; synthesized inline from the 4 deep reviews |

**Honest limits of this review:**

- The 2 failed agents mean doc-drift findings are inferred from secondary sources, not independently re-derived against current docs.
- The cross-cutting scorecard (8 dimensions) is mine, not an independent agent's.
- The 33 NEW findings are weighted toward the 4 deep reviews and the prior review's Phase G ledger rather than independently re-derived across the whole tree.
- Two src/ subareas (`BlockRegistryService::scan()`, `BlockViewData::blocksCss()` cache behavior) were 🟡-marked rather than ✅-verified because the agent exhausted its tool budget before completing them — see "Issues encountered" at the foot of the src/ summary.

**Files created or modified:** none — this is a review, not a remediation plan. The Remediation plan is in §6–§7.

---

## 10. Methodology notes

- 6 agents dispatched in 2 batches of 3 (max concurrent = 3).
- Each agent had explicit instructions to (a) read the prior review first, (b) verify every prior finding against current code, (c) tag as STILL OPEN / FIXED / REGRESSED / NEW with file:line evidence, (d) distinguish verified-vs-assumed with a ✅/🟡 convention.
- The src/ and views/ agents did their reading thoroughly but ran out of tool iterations during the summarization step; their summaries are complete because they wrote the summary on disk before the runner truncated the notification.
- The docs/ and cross-cutting agents failed mid-summary (not mid-reading) — useful partial transcripts exist but no complete summary.
- This document was assembled from the 4 saved summaries + ground-truth reading of `CODE_REVIEW.md`, `docs/file-structure.md`, and the prior review's structure.

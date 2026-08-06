# Heisenberg — Full Codebase Review

> **Remediation status (2026-08-05, same day):** Phases A, B, C (safe subset), E, and F are
> **done and green** (541 tests). Phase D (comment cleanup) is done for `src/` and every
> flagged worst-offender; the mechanical Pencil-provenance and TODO-N.M markers were swept
> repo-wide. Details per finding in the sections below still describe the *pre-remediation*
> state — read them as the review record, with this note as the ledger of what was fixed.
> Full unabridged findings: `docs/code-review-findings-2026-08-05.md`.
>
> **Phase G done (2026-08-06):** canvas renders `innerBlocks` children (read-only, depth-capped
> in lockstep with `BlockRenderer::MAX_NESTING_DEPTH`); `BlockContractValidator` shape-checks
> every supports group's interior (unknown features / non-boolean flags now fail validation);
> both registry scan caches self-invalidate on a file-set fingerprint (path+mtime+size);
> the Animate section is `AnimationCatalog`-driven end-to-end with a working Play preview
> (the canvas now loads the catalog CSS via `blocksCss()`); all four panel search fields
> filter client-side (`ui/search-field`'s `data-hb-filter` contract); the dead AI toolbar
> trigger was removed; `live/media/*`, `effect-editor`, the topbar's save-failure copy and
> the featured-image dialog title route through `__()` (en+fr); `ui/radio` no longer merges
> unrelated groups and `ui/custom-scrollbar` honors `:smooth="false"`; the three orphan
> components (`ui/var-menu-item`, `pickers/gradient-stop-row`, `live/side-panel`) are deleted.
> Verified: 553 PHP tests green + `tests/js/phase-g-matrix.mjs` (14 Chromium checks) alongside
> the existing browser/inspector matrices. Still deferred by design: the title/postId
> shared-store refactor (overlaps the planned left-sidebar work).

**Date:** 2026-08-05
**Method:** 10 parallel review agents across dimensions (data flow, state, theme tokens, docs conformance, comment noise, PHP services, HTTP/security, UI components), followed by adversarial verification of every critical/high finding, followed by first-hand re-verification of the root-cause chain. 77 deduplicated findings survived: **2 critical, 12 high, 32 medium, 31 low**.

---

## TL;DR — why the canvas "never updates"

**The inspector and toolbar are NOT disconnected from the blocks.** The wiring — selection events, `data-hb-control` → `window.hbEditor.setAttribute/setSupport`, re-render — is intact and correct end-to-end. Every edit updates the block model and re-renders the block's DOM. The pixels don't change because of **three independent defects downstream of the model, all at the CSS layer**:

| # | Defect | Affects | Where |
|---|--------|---------|-------|
| RC‑1 | Numeric fields write unit-less values (`"12"`, `"0"`) that the CSS sanitizers reject; the value silently falls back to an empty default | Width/Height, all 8 Padding/Margin sides, Font size, Letter spacing, Position X/Y | `block-runtime.blade.php:137-154`, `inspector.blade.php:1213-1291` |
| RC‑2 | `.hb-align-left/center/right` classes are applied correctly but **no stylesheet anywhere defines them** (they lived in the deleted `resources/css/builder.css`) | Align — toolbar popover AND inspector panel, in **both** the editor and published output | `SupportsStyle.php:42` (stale docblock), no CSS rule in repo |
| RC‑3 | The canvas renderer never applies `contract.style.className`, so the `hb-supports` marker class never lands on canvas block roots — and **every** SupportsStyle capability rule is gated on `[data-block-id].hb-supports` | Opacity, Position mode/X/Y/Rotation, Shadow, Letter spacing, Text H/V align, Overflow, Border sides, Fill/Hug/Clip — all dead **in the canvas only** (they work in preview/publish) | `block-runtime.blade.php:243-245` vs `SupportsStyle.php:134` |

And one force-multiplier:

- **RC‑4:** `inspector.blade.php:26-31` carries a "KNOWN GAP" comment that **misdiagnoses** the problem ("no matching contract.style.variable" — false; the variables exist and are read back). Any AI or human starting from that comment is sent in the wrong direction. This is very likely why previous fix attempts kept circling.

These four are small, surgical fixes. Everything else in this report is real but secondary.

### The fixes, precisely

1. **RC‑1** — in `inspector.blade.php`'s `handleControlEvent` / `commitSpacingGroup`, normalize bare numbers to `<n>px` before calling `setSupport` (or add a bare-number branch to `size-value`/`length-signed` in the JS `cssValueValid` **and** PHP `BlockRenderer::cssValueValid()` together — they must stay in lockstep). Note: even the shipped default `"0"` for padding/margin fails the current `size-value` regex.
2. **RC‑2** — add `.hb-align-left/center/right` rules in `SupportsStyle::alignBreakoutRules()` (where `hb-align-wide/full` already live and work). This fixes editor and published output in one place.
3. **RC‑3** — in `block-runtime.blade.php` `renderNode()` (line ~243), merge `contract.style.className` into the root element's class list, mirroring what the server-side `BlockRenderer::resolveClass()` (line 251-284) already does. One change un-deadens ~10 inspector controls.
4. **RC‑4** — rewrite the `inspector.blade.php:26-31` comment to point at RC‑1/RC‑3, or delete it.

### Verification note on RC‑3

One agent's verdict claimed typography controls (text-align, letter-spacing) already work in the canvas because the SupportsStyle rule exists and is served. Verified first-hand: the rule exists and **is** served (`BlockViewData::blocksCss()` prepends `SupportsStyle::css()`), but it is gated on `.hb-supports`, which only exists in `style.className` (`paragraph.json:215`, `heading.json` equivalent) — a field the canvas renderer provably never reads (the template root class is `"hb-block hb-block-paragraph {{attributes.extraClasses}}"`, `paragraph.json:448`). So typography, and also the Fill/Hug/Clip size classes (`[data-block-id].hb-supports.hb-size-*`), are **dead in the canvas, working in preview** — all cured by RC‑3.

---

## 1. Editor ↔ publish divergence (high)

The same content can render differently in the canvas vs the published page:

- **JS `cssValueValid()` is missing 7+ sanitizer kinds** that PHP enforces strictly (`opacity`, `angle`, `shadow`, `align-3`, `position-mode`, `flex-*`, `overflow`, `color-token*`). They fall through to a permissive generic regex (`block-runtime.blade.php:153`), so e.g. rotation `"45"` (no `deg`) previews fine in the editor and silently reverts on the published page. Both shipped contracts use five of the missing kinds — this is live, not theoretical. Port the missing branches verbatim from `BlockRenderer::cssValueValid()` (`BlockRenderer.php:649-683`). *(high, confirmed)*
- **`inner-blocks` template nodes render as nothing client-side** (`block-runtime.blade.php:239-240`) while `BlockRenderer::renderInnerBlocks()` recurses properly. Latent today (no container contract ships), but the first `columns` block will preview empty while publishing full content. Port the recursion before building container blocks. *(high, confirmed)*

## 2. State management (verdict: one good pattern, three ad hoc ones)

**Correction to the project's own assumptions:** there is **no Alpine.js anywhere** in the editor — ~30 vanilla-JS IIFEs with a consistent `@once`/`DOMContentLoaded`/`hb:refresh` boot convention. That convention is solid and uniformly applied. The block document (`doc.blocks`) is a genuinely well-governed single source of truth, mutated only through `window.hbEditor`. The problems are everything *around* it:

| Severity | Finding | Location |
|----------|---------|----------|
| high | Post title has no model home — lives in two DOM elements hand-synced by cross-writes with a reentrancy flag; every consumer re-queries the DOM | `canvas.blade.php:23-51`, `inspector.blade.php:287` |
| high | `postId`/save/version state duplicated across **five** independent closures, consistent only via the `hb:post-id` broadcast | `topbar.blade.php:120-131`, `inspector.blade.php:640/745/787` |
| medium | Theme tokens are a third pattern: DOM-only, no in-memory model; every read/write re-scans `[data-hb-token-row]` | `panel-style-themes.blade.php:95-166` |
| medium | Five events dispatched with **zero listeners**: `hb:pick-image` (breaks the image-block click-to-pick affordance), `hb:featured-image-change`, `hb:format`, `hb:toolbar-action`, `hb:toolbar-popover` | `block-runtime.blade.php:829` et al. |
| medium | `block-runtime`'s boot uses a module-scope `wired` flag instead of the element-flag idempotency pattern every other file uses — a replaced canvas wrap would permanently lose its listeners | `block-runtime.blade.php:807-811` |
| medium | `window.__hbEditorDoc` / `window.hbEditorInsertBlock` expose the live mutable model with zero encapsulation; nothing reads them — delete | `block-runtime.blade.php:884-885` |
| low | Navigator reads the block list from the DOM in one function and from `hbEditor.getDoc()` in another | `panel-navigator.blade.php:95-99` vs `:226-256` |
| low | Title-sync `syncing` guard defends against a race that structurally cannot occur (`el.value =` never fires `input`) | `canvas.blade.php:27` |

**Recommendation:** promote `doc.title`, `postId`, `contentVersion`, and dirty state onto the runtime (`window.hbEditor`) as the single store, and make DOM elements views of it. The block runtime already demonstrates the right pattern — extend it rather than inventing a fourth.

## 3. Security (from an otherwise solid layer)

Authorization, mass-assignment scoping, upload hardening (extension allow-list, fail-closed virus scan, decompression-bomb guard), and the XSS boundary (`BlockRenderer` + `HtmlSanitizationService`) were all reviewed and are in good shape. Three gaps:

- **medium/IDOR:** `GET /editor/{post}` and `GET /editor/{post}/preview` skip the `PostPolicy` view check the JSON API enforces (`EditorController.php:63`, `PreviewController.php:90-102`), and the default editor middleware is just `['web']` — an anonymous visitor can enumerate IDs and read unpublished drafts. Run the same `Gate::forUser($actor)->authorize('view', …)` check `PostController::show()` already performs.
- **low:** dev-only `GET /uploads/{path}` (`.*` wildcard) relies solely on Flysystem's normalizer against traversal; add the same explicit guard `font()` has (`EditorController.php:228-240`).
- **low/bug:** locale switcher submits a `return` field the controller never reads — the redirect always falls back to Referer (`LocaleController.php:35`, `footer.blade.php:235-259`).

## 4. PHP services

- **medium:** `BlockContractValidator::validateSupports()` validates only group *names* for 11 of 13 groups — `"supports": {"color": "yes"}` passes and silently degrades the inspector. Add per-group shape validation (`BlockContractValidator.php:195-239`).
- **medium:** both registry services cache their disk scan for the singleton's lifetime with no invalidation — fine under PHP-FPM, stale under Octane/queue workers (`BlockRegistryService.php:131`, `PostTemplateRegistryService.php:31`).
- **low:** unanchored `str_replace($realRoot, '', $real)` for relative paths can corrupt paths when the root string recurs (`BlockRegistryService.php:185` + twin); use `substr`.
- **low:** `isSafeAssetPath()`/`stringify()` duplicated byte-for-byte between the two validators — extract to a shared helper.
- **low:** `BlockViewData::blocksCss()` re-reads every block CSS file from disk per call, uncached (`BlockViewData.php:100`).
- **low:** locale-suffix regex assumes exactly 2 lowercase letters — `content_en_US` would silently skip validation (`BlocksPayloadService.php:165`).

## 5. Dead / decorative UI (should be wired or hidden — currently indistinguishable from "broken")

| Item | Location | Status |
|------|----------|--------|
| Advanced → "Animate on scroll" (3 controls + Play button) | `block/advanced.blade.php:18-37` | Writes orphan attributes no contract declares, nothing consumes; Play has no handler at all |
| Search fields on Components, Blocks, Tools, Themes tabs | `panel-components-blocks.blade.php:82/100`, `panel-ai-tools.blade.php:169`, `panel-style-themes.blade.php:701` | Rendered, zero filtering logic (media-library's identical field IS wired — use it as the template) |
| Toolbar AI + More (⋯) buttons | `toolbar/groups/action.blade.php:5` | Visible and clickable, no popover container exists — hide until built |
| Toolbar color popover checked-state | `toolbar/color-menu.blade.php:27` | Never syncs to the selected block (align-menu and type-menu both do — copy their `hb:block-selected` listener) |
| `ui/var-menu-item`, `pickers/gradient-stop-row`, `live/side-panel` | — | Orphaned components; the live pickers hand-duplicate their markup under different class names. Delete or actually use them |
| Stroke, per-side corner radius, Flex Layout sections | `style-panel.blade.php:108/148` | Correctly gated but permanently unreachable — no shipped contract declares `border` or is a container. Fine, but note it so they aren't reported as "broken" |

## 6. Hardcoded theme values (verdict: mostly disciplined; 10 real gaps)

The sweep found the codebase largely token-clean — no hand-rolled duplicates of `ui/select`/`checkbox`/`toggle`, correct `var(--hb-*, fallback)` usage almost everywhere, and the flagged exceptions below are the genuine remainder:

- `panel-style-themes.blade.php:61` — literal `#C0392B` error red; not even the design system's danger token (`--hb-danger: #D4191A`) and misses the dark-theme remap. **Fix first.**
- `topbar.blade.php:87` — `var(--hb-radius-md, 6px)` — the only wrong fallback in the codebase (token is 5px).
- `preview.blade.php:85-86, 57/64` — re-hardcodes `#0a0a0a`/`#9a9a9a` literals that are already declared as `--ink`/`--muted` in the same file's `:root`.
- No shadow tokens exist; `0 8px 28px rgba(0,0,0,.14)` appears verbatim in both `32-pickers.css:8` and `topbar.blade.php:87` — add `--hb-shadow-sm/md/lg`.
- Off-scale literals used repeatedly: `6px` radius (7+ rules in `33-toolbar.css`/`32-pickers.css`), `4px` radius (3 rules), `15px`/`10px`/`12.5px` font sizes, literal `12px` where `--hb-fs-sm` exists. Snap to tokens or add deliberate scale steps.

## 7. Docs conformance (verdict: code is ahead of the docs; docs now actively mislead)

- **`docs/file-structure.md` is obsolete top to bottom** — it describes the pre-2026-08-02 Builder/Editor coexistence world (frozen `/builder`, Livewire gated on "Phase 3 approval", `resources/js/editor/*` files). The Builder is deleted, Livewire is a hard dependency powering the media library, all editor JS ships inline in Blade, and the real CSS file list is completely different. Rewrite or mark superseded.
- **`docs/inspector-composition.md` is stale in both directions:** it still calls State tabs, Alignment, Position, Effects, Fill/Hug/Clip, Typography H/V align, and letterSpacing "inert/gated off" (all now wired, 8 stale rows), while claiming Stroke and corner-radius render (they no longer do — both contracts dropped `border` entirely). Its central claim "no shipped contract carries hb-supports" is now false.
- **`docs/toolbar-composition.md`** still matches the code closely — no action.
- **`docs/block-schema.md`** omits three real, validated features: `style.classNames` conditional classes (heading.json uses 11 of them), `supports.states`, and 5 of the 16 control types (`checkbox`, `chips`, `unit`, `color`, `font`).
- **`docs/ROADMAP.md`** "Done" still claims 9 shipped contracts and a completed Builder — both superseded by the 2026-08-02 reset (2 contracts, Builder deleted).
- **`docs/BLUEPRINT.md` §1.4** DI listing misses the four post-template adapter contracts plus `VirusScanner`/`MediaLibraryService`.
- **`TODO.md` checkmarks are honest** — a ~10-item spot-check of `[x]` items found every one genuinely implemented. (Exception: `TODO.md:507-509` claims align "already works" — see RC‑2.)

## 8. Comment noise (verdict: confirmed, quantified)

~887 comment-marker lines across blade files + ~745 in PHP; true span estimated 2,500–3,500+ lines. Four deletable categories dominate:

- **(a)** Pencil/.pen/builder provenance tags — open nearly every `live/*` component ("from Pencil Block (BfaTx)…"). ~60–80 occurrences. Delete all.
- **(b)** Cross-file narration ("see 34-canvas.css", "mirrors BlockRenderer::…") — the largest category, 150–200+. Delete, or compress truly load-bearing couplings to one line.
- **(c)** Dated changelog/why-narration ("Full-kit overhaul 2026-07-19 (Phase 1)", "TODO 7.3", "see the task report", fixed-bug histories) — 80–120. Delete all; several describe bugs already fixed.
- **(d)** Restating the next line. Delete.

**Worst offenders (comment-markers/lines):** `inspector.blade.php` 288/2213, `EditorController.php` 68/325 (21%), `SavePostRequest.php` 40/158 (25%, highest ratio), `MediaLibraryService.php` 66/505, `topbar.blade.php` 60/585, `block-toolbar.blade.php` 53/347, `block-runtime.blade.php` 92/918, plus `panel-style-themes`, `BlockRegistryService`, `BlockRenderer`, `color-picker`, `BlockContractValidator`, `custom-scrollbar`, `HeisenbergServiceProvider`, `PostController`.

**Keep (compressed to 1–2 lines each):** the security/concurrency/DOM-timing invariants — SVG/mime service-side enforcement + Windows filename chars (`MediaLibraryService.php:61-68/366-370`), reverse-tabnabbing guard (`BlockRenderer.php:149-153`), row-lock concurrency gate (`PostController.php:109-111`), `blocks` present-vs-required (`SavePostRequest.php:85-88`), cssValueValid JS/PHP lockstep warning (`BlockContractValidator.php:37-47`), mousedown-preserves-selection (`block-toolbar.blade.php:181-185`), synchronous `window.open()` (`topbar.blade.php:371-375`), caret save/restore (`block-runtime.blade.php:316-319`).

## 9. i18n gaps

The `live/media/*` subtree, `effect-editor.blade.php`, and the topbar's save/error messages hardcode English while every sibling routes through `__()`. The featured-image dialog title at `inspector.blade.php:329` ships hardcoded. Two latent primitive bugs: `ui/radio` defaults `name="radio"` (unrelated groups would merge), `ui/custom-scrollbar`'s `smooth=false` can never disable smoothing.

---

## Remediation plan (ordered)

**Phase A — make the canvas honest (do first; ~1 day):**
RC‑1 unit normalization → RC‑3 apply `style.className` in `renderNode()` → RC‑2 align CSS in `SupportsStyle` → RC‑4 fix the misleading comment → port the 7 missing JS sanitizer kinds → color-menu checked-state sync. *After this phase, every wired control visibly updates the canvas, and canvas = published output.*

**Phase B — security:** PostPolicy check on `EditorController::show()` + `PreviewController::showPost()`; traversal guard on `/uploads/{path}`; `LocaleController` return field.

**Phase C — state consolidation:** title into the doc model; postId/version/dirty onto `hbEditor`; element-flag the runtime boot; delete `__hbEditorDoc`/`hbEditorInsertBlock`; navigator reads `getDoc()`; wire or remove the five dead events (decide `hb:pick-image` when the image block lands).

**Phase D — comment cleanup:** delete categories (a)–(d) across the 15+ flagged files; compress the keep-list to 1–2 lines each.

**Phase E — tokens:** danger red, radius-md fallback, preview.blade vars, shadow tokens, off-scale literals.

**Phase F — docs refresh:** rewrite `file-structure.md`; update `inspector-composition.md` (both directions), `ROADMAP.md`, `block-schema.md` (+classNames/states/control types), `BLUEPRINT.md` §1.4; fix `TODO.md:507-509`.

**Phase G — before building new blocks** *(done 2026-08-06 — see the ledger at the top)*: client-side `inner-blocks` rendering; supports shape validation in the contract validator; registry cache invalidation; i18n for media/effect-editor/topbar strings; wire or hide the four search fields, Animate section, AI/More buttons; delete or adopt the three orphan components.

---

## What's healthy (verified, so it doesn't get "fixed")

- The boot/data-injection pipeline (`EditorController` → `BlockViewData::clientBlocks()` → `window.__hbEditor.registry` → runtime) is sound, including the `registryHash` save contract.
- The `window.hbEditor` mutation API and the `@once`/`hb:refresh` boot convention are consistent and correct across ~30 files.
- Toolbar format/color/heading-level/move/drag are fully wired and repaint.
- Authorization on the JSON API, upload hardening, and the rendered-output XSS boundary are strong.
- `TODO.md`'s checked items are truthful; `toolbar-composition.md` matches the code.

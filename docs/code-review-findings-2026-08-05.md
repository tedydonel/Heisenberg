# Code Review 2026-08-05 - Full Findings Appendix

Unabridged output of the 18-agent review behind `CODE_REVIEW.md`: per-dimension summaries and wiring maps, all 77 findings with full evidence, and the adversarial-verification verdicts for every critical/high claim.

## Part 1 - Dimension summaries and maps

### inspector-dataflow

The inspector→canvas write pipeline itself (selection events, setAttribute/setSupport, reRenderBlock) is intact and correctly wired end-to-end — this is not a broken-event or dead-listener problem. The reported disconnect instead comes from two distinct downstream defects that make a large share of edits produce no visible change even though the model updates correctly: (1) numeric Style fields (Width/Height, Padding/Margin, Position X/Y, Font size, Letter spacing) write bare digits with no unit, which the client-side CSS-value sanitizers in block-runtime.blade.php reject outright, silently falling back to each variable's empty default; and (2) an entire cluster of Style sections (Position, Effects/shadow, Appearance→Opacity, Alignment, and Typography's text-align/vertical-align/letter-spacing) write correctly into the model and get inlined as CSS custom properties/classes on the block root, but neither shipped block stylesheet (paragraph.css, heading.css) contains a single rule that consumes those properties — so the write succeeds and the block re-renders, yet nothing on screen changes. A few sections (Stroke, per-side Border radius, Flex Layout) are also unreachable today because no shipped contract satisfies their supports/isContainer gate, and the Advanced tab's "Animate on scroll" controls are pure decoration with no backing attribute or consumer at all.

### toolbar-canvas-dataflow

The toolbar-to-runtime wiring is almost entirely correct: format (bold/italic/underline/strike/link/code), text-colour, heading-level (type menu), move-up/down, and drag-reorder all call real window.hbEditor mutations that visibly repaint the block, confirmed by tracing into the block CSS that consumes the resulting CSS variables/classes. The one genuine, high-impact disconnect is the Align control (both the toolbar's Align popover and the inspector's Alignment panel): it writes `supports.align` and correctly emits an `hb-align-<value>` class in both the client runtime (block-runtime.blade.php) and the server-side renderer (BlockRenderer::resolveClass), but no stylesheet anywhere in the shipped CSS actually defines `.hb-align-left/center/right` — the class was apparently only ever styled in a `resources/css/builder.css` file that no longer exists in the repo. This exactly matches the reported symptom of a toolbar action that appears to do nothing. A few other affordances (AI, More, Save-as-block) are dead by design and already self-documented as unfinished, plus one minor cosmetic desync in the colour popover's checked-state. The boot/data-injection path (EditorController -> BlockViewData::clientBlocks -> window.__hbEditor.registry -> block-runtime's REGISTRY) is verified sound end-to-end, including the registryHash contract with BlocksPayloadService.

WIRING MAP — toolbar affordance -> runtime mutation -> visual repaint:

Format group (bold/italic/underline/strikethrough) — block-toolbar.blade.php:190-200 — document.execCommand -> native `input` event on .hb-ce -> block-runtime.blade.php's delegated input listener (line 833-842) writes model.attributes[rt-key] -> WIRED, repaints.
Format: Code toggle — block-toolbar.blade.php:117-142 (toggleInlineCode, manual DOM surgery + synthetic InputEvent) -> same input listener -> WIRED, repaints.
Format: Link — block-toolbar.blade.php:207-256, dynamically-built popover, execCommand('createLink') -> same input path -> WIRED, repaints (relies on deprecated but still-functional execCommand).
Handle: drag (.hb-tb__btn--drag) — native pointerdown, entirely owned by block-runtime.blade.php's wireCanvasBlockDrag() (lines 674-727) -> moveBlock() -> WIRED, repaints (DOM reorder).
Handle: select-parent — block-toolbar.blade.php:278-281 calls hbEditor.selectById(parentIdOf(...)) — WIRED but currently unreachable: gateToolbar (block-runtime.blade.php:424) hides it because parentIdOf() always returns null (no block nesting shipped yet). Correctly hidden, not misleadingly visible.
Handle: move-up/move-down — block-toolbar.blade.php:261-266 -> hbEditor.moveBlock(i,j) -> WIRED, repaints (DOM reorder).
Handle: type pill -> type-menu popover -> `blocktype` event -> block-toolbar.blade.php:298-304 -> hbEditor.setAttribute(id,'level',n) -> WIRED, repaints (h-tag actually changes; heading.css has per-tag font-size rules h1..h6). Minor: the pill's own visible aria-label ("Block type: Text") is set once from a hardcoded prop in index.blade.php:35 and never updated by JS on selection change (type-menu.blade.php only updates the dropdown's internal items, not the pill itself) — cosmetic staleness, not reported as a formal finding since it doesn't affect the block itself.
Style: text-colour swatch (data-tb-popover="color") -> color-menu.blade.php `colorselect` -> block-toolbar.blade.php:312-320 -> hbEditor.setSupport(id,'color.text',value) -> styleDeclarations() reads contract.style.variables['--hb-heading-color'/'--hb-paragraph-color'] (source: supports.color.text) -> heading.css/paragraph.css use `color: var(--hb-*-color)` -> WIRED, genuinely repaints. Popover's own checked-state sync is missing (see findings) but the mutation is real.
Style: Align (data-tb-popover="align") -> align-menu.blade.php `alignselect` -> hbEditor.setSupport(id,'align',value) -> renderNode adds class `hb-align-<value>` -> DEAD: no CSS anywhere defines .hb-align-left/center/right (critical finding). Wiring is 100% correct through the JS/PHP layer; the break is a missing stylesheet rule, not missing JS wiring.
Style: Save-as-block (floppy) -> dispatches hb:toolbar-action only, no capability exists on window.hbEditor -> DEAD (documented, and currently hidden for both shipped contracts since neither has innerBlocks.enabled=true, so in practice not user-visible today).
Action: AI trigger -> dispatches hb:toolbar-popover only; no `[data-tb-pop="ai"]` container exists anywhere -> DEAD (documented as "no host content yet").
Action: More (⋯) -> same as AI, no `[data-tb-pop="more"]` container -> DEAD (documented).

BOOT / DATA-INJECTION PATH (verified correct, no findings):
EditorController::index()/show() -> sharedViewData() -> BlockViewData::clientBlocks($registry) (src/Support/BlockViewData.php:30-73) produces, per block name: attributes (flattened DEFAULTS), attributeDefinitions (full enum/type metadata), supports, template, style.variables/classNames/className, version, innerBlocks, richText.
This is exactly the shape block-runtime.blade.php's REGISTRY[name] consumer expects: newBlockModel() reads REGISTRY[name].attributes for defaults + .version for schemaVersion (lines 84-93); resolveTag()/renderNode() read REGISTRY[name] as `contract` for .attributeDefinitions, .style.variables, .style.classNames, .template (lines 117-286); alignmentValuesFor() reads REGISTRY[name].supports.align (line 211-215).
index.blade.php (line 53) passes :registry="$registry" :blocks-css="$blocksCss" :registry-hash="$registryHash" straight into <x-live.block-runtime>, which JSON-embeds them into window.__hbEditor.registry/.registryHash (block-runtime.blade.php:56-62) — read synchronously by the same <script> tag's IIFE. registryHash flows to hbEditor.buildSavePayload() (lines 908-914), matching BlocksPayloadService::validatePayload()'s expectation of a `sha256:` string equal to BlockRegistryService::computeHash() (src/Services/BlocksPayloadService.php:43-52) — confirmed both sides call the identical registry->computeHash().
initialBlocks (existing-post hydration, index.blade.php:55-99) hydrates purely through the public window.hbEditor API (insertBlock/setAttribute/setSupport) — no bypass of the runtime, so a saved post's toolbar-driven align/color/level values, once fixed, would repaint correctly through the same code path as a live edit.
No allow-list narrows the registry (EditorController.php:93-96 comment) — every discovered contract (heading, paragraph) ships to the client as-is.
Conclusion: the boot/data-injection pipeline is sound; the reported "toolbar doesn't affect blocks" symptom traces to one specific, concrete, and easily fixed gap — the missing `.hb-align-*` CSS — plus a handful of already-self-documented and comparatively minor dead affordances (AI/More/Save) that were never claimed to be wired.

### state-management

The editor has no Alpine.js despite CLAUDE.md's description of the stack — confirmed absent by grep and by explicit in-code comments ("Alpine is not loaded on /editor"). State management is ~30 independent vanilla-JS IIFEs using a consistent, safe @once/DOMContentLoaded/hb:refresh boot convention. Within that, there IS one well-governed single source of truth: the block document (`doc.blocks` in block-runtime.blade.php, mutated only through window.hbEditor's setAttribute/setSupport/moveBlock/removeBlock, which always re-render and always fire events) — inspector and toolbar correctly resolve selection only through this API. Everything else, however, uses three different ad hoc strategies with no shared store: post title is DOM-only (two elements hand-synced), save/version/postId state is duplicated across five private closures synced only by a broadcast event, and theme tokens have no in-memory model at all (DOM rows are re-scanned on every read/write). Two dead legacy globals still expose the live mutable block array with no encapsulation, and five custom events (hb:pick-image, hb:featured-image-change, hb:format, hb:toolbar-action, hb:toolbar-popover) are dispatched with no listener anywhere — hb:pick-image being consequential since it's the documented, unimplemented hook for the empty-image-block click-to-pick affordance.

Full state inventory:

Alpine: none anywhere (confirmed absent; codebase uses vanilla JS throughout, contra the assumption of Alpine.js usage). grep across live/ui for Alpine.store()/Alpine.data()/x-data returned zero matches; multiple files explicitly say so, e.g. panel-section.blade.php:8 "Alpine is not loaded on /editor (no @vite, no Alpine script...)".

Canonical/model state:
- `doc = { blocks: [] }` — block-runtime.blade.php:72, the only real document model; mutated exclusively via insertBlock/setAttribute/setSupport/moveBlock/removeBlock (block-runtime.blade.php:359-610).
- `selected` (DOM element ref) — block-runtime.blade.php:74, select()/deselect() at 460-480; read via `window.hbEditor.getSelectedId()`.
- `previewStates` (per-block id -> interaction state) — block-runtime.blade.php:159.
- `blockSeq` (id counter) — block-runtime.blade.php:73.

window.* globals:
- `window.hbEditor` — block-runtime.blade.php:889-915, the documented public API (getDoc/getSelectedId/getModel/getContract/indexOf/insertBlock/setAttribute/setSupport/previewState/parentIdOf/moveBlock/removeBlock/selectById/reRenderBlock/buildSavePayload).
- `window.__hbEditor` — block-runtime.blade.php:56, holds `registry`/`registryHash` only (server-seeded contract data, read-only in practice).
- `window.hbEditorInsertBlock`, `window.__hbEditorDoc` — block-runtime.blade.php:884-885, dead legacy globals (no reader found anywhere in live/ui).
- `window.hbEditorShowPanel` — sidebar.blade.php:110, the left-panel switcher, called by topbar.blade.php:367 for the Layers button.
- `window.hbSetPanelCollapsed` — topbar.blade.php:110, toggles a shell class + writes localStorage.

Private closure state (per file, not shared):
- topbar.blade.php:120-132 — hbPostId, hbContentVersion, hbSaveUrl/templates, hbSeeded, hbDirty, hbConflicted, hbSaveInFlight, hbAutosaveTimer — the save/autosave state machine.
- footer.blade.php:288-290 — hbOnline, hbSaveState, hbSaveMessage — a second, display-only projection of save state, synced via hb:save-state.
- inspector.blade.php:640, 745, 787 — separate `let postId` copies per taxonomy/discussion/layout field closure, each synced via hb:post-id.
- panel-style-themes.blade.php — no persistent JS state; DOM rows are the state (collectTheme() line 95, applyThemeVars() line 150); `colorTarget` (line 259) and `fontTimer`/per-combobox `__hbFontPage` (lines 423-452) are transient UI state.

DOM-as-state (data-* attributes read back AS the value, not just a hook):
- `data-hb-title` — canvas.blade.php:13, inspector.blade.php:287 — the ONLY home for the post title.
- `data-hb-post-id` — topbar.blade.php:495, inspector.blade.php:381/413/446/462 — seeds each closure's postId copy.
- `data-hb-token-*` (name/color/weights) — panel-style-themes.blade.php:571 etc. — the only home for theme token values.
- `data-hb-var-bound` — inspector.blade.php:1130 — marks a text field as bound to a theme variable reference vs. a literal.
- `data-hb-device` — topbar.blade.php:426 — current device-preview selection.
- localStorage `hb-editor:{sidebar,panel,inspector}-collapsed`, `hb-editor:theme` — panel collapse/dark-mode persistence, written by topbar.blade.php:110-113/328, read at boot by editor/layouts/app.blade.php:26-48 (a second, independently-maintained copy of the same three-key list: `HB_PANEL_KEYS` in topbar.blade.php:108 vs `panelKeys` in app.blade.php:26).

Custom event inventory (dispatcher -> listener(s)):
- hb:refresh — dispatched by ~9 files (sidebar.blade.php:102-103, panel-style-themes.blade.php:236/246, inspector.blade.php:681/2084, combobox.blade.php:134-135, tablist-script.blade.php:26-27) -> listened by ~29 files (every @once component's own boot()). Universal re-init broadcast; safe because every listener is idempotent per-element.
- hb:blocks-changed — block-runtime.blade.php:383/533/555/597/608/841 -> panel-navigator.blade.php:308 (rebuild), topbar.blade.php:299 (mark dirty).
- hb:block-selected — block-runtime.blade.php:469 -> inspector.blade.php:1171, toolbar/align-menu.blade.php:40, toolbar/block-toolbar.blade.php:335, toolbar/type-menu.blade.php:62.
- hb:block-deselected — block-runtime.blade.php:479 -> inspector.blade.php:1183.
- hb:block-updated — block-runtime.blade.php:528/554 -> inspector.blade.php:1196.
- hb:post-id — topbar.blade.php:245 -> inspector.blade.php:727/761/825 (three listeners, one per field-closure).
- hb:doc-title — canvas.blade.php:42 -> panel-navigator.blade.php:305, topbar.blade.php:300.
- hb:save-state — topbar.blade.php:148 -> footer.blade.php:318.
- hb:media-select — media-dialog.blade.php:177 -> inspector.blade.php:549.
- hb:media-pick — media-library.blade.php:47 -> media-dialog.blade.php:174.
- hb:nav-open — topbar.blade.php:368 -> panel-navigator.blade.php:306.
- hb:pick-image — block-runtime.blade.php:829 -> NO LISTENER (dead; documented feature gap, see findings).
- hb:featured-image-change — inspector.blade.php:538 -> NO LISTENER (dead, arguably by design per its own comment).
- hb:format — toolbar/block-toolbar.blade.php:199 -> NO LISTENER (dead).
- hb:toolbar-action — toolbar/block-toolbar.blade.php:282 -> NO LISTENER (dead).
- hb:toolbar-popover — toolbar/block-toolbar.blade.php:210/255/293 -> NO LISTENER (dead).
- Local (non-hb:) events, all correctly consumed within their own popover component: colorselect (color-menu.blade.php:36 -> block-toolbar.blade.php), varselect (variable-menu.blade.php:47), blocktype (type-menu.blade.php:57 -> block-toolbar.blade.php:298), colorchange (color-picker.blade.php internal -> panel-style-themes.blade.php:278), search/loadmore (combobox.blade.php -> panel-style-themes.blade.php:453/459).

Boot-order / @once pattern: consistently implemented across ~30 files — every script does `if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();` then `document.addEventListener('hb:refresh', boot);`, with per-element (`root.__hbXxx`) idempotency flags almost everywhere. This part of the architecture is genuinely solid and uniform; the one exception is block-runtime.blade.php's module-scope `wired` flag (see findings) which breaks that convention.

### hardcoded-theme

Swept resources/css/tokens.css, 02-dark-theme.css, all of resources/css/editor/*.css, and resources/views/**/*.blade.php for hardcoded theme values and hand-rolled component duplicates. The codebase is largely disciplined: the vast majority of colors/spacing/radii/type already use var(--hb-*, fallback) with correct fallbacks, and several apparent hex/rgba hits are deliberate, commented exceptions (the .hb-page block-content palette in 35-blocks.css/preview.blade.php, the toolbar's white-on-accent overlays, the color picker's hue-wheel math). No hand-rolled duplicates of ui/select, ui/checkbox, or ui/toggle were found — all usages go through the real primitives. Findings below are the genuine gaps: one incorrect token fallback, one non-token danger red, off-scale font-size/radius literals with no matching token (some recurring enough to warrant new tokens, e.g. a shadow scale), and one file re-hardcoding colors that are already defined as local custom properties a few lines above.

### docs-inspector-toolbar

The two composition docs are substantially stale relative to the current tree. toolbar-composition.md still matches the code closely (no material findings). inspector-composition.md, however, was verified against a snapshot (2026-08-04/05) that predates a wave of same-day changes tagged \"TODO 7.1\"-\"7.9\" in the code comments: both shipped block contracts (heading, paragraph) gained new supports groups (position, effects, appearance.opacity, states, typography.textAlign/textAlignVertical/letterSpacing) and had matching inspector controls wired up, directly reversing several of the doc's \"inert\"/\"gated off\" claims (State tabs, Alignment segmented, Position, Effects, Fill/Hug/Clip, Typography H/V align, letterSpacing). In the other direction, the contracts also dropped `border` support entirely, so Stroke and Appearance's corner-radius fields — which the doc says render — no longer render at all for either block. The doc's central claim that \"no shipped contract carries hb-supports\" is also now false; both contracts carry it, which is the root reason most of the previously-'unclaimed' capabilities now work.

### docs-blueprint-structure

docs/file-structure.md is the single largest source of drift: it describes a pre-2026-08-02 Builder/Editor coexistence plan (frozen /builder, Livewire gated behind unlanded 'Phase 3' approval, resources/js/editor with numbered bootstrap/selection/canvas files) that TODO.md itself documents as deleted — Builder is fully gone (no BuilderController, routes/web.php, or resources/{css,js,views}/builder anywhere), Livewire is already a hard composer dependency powering the media library, and there is no resources/js/editor directory at all (JS ships inline in Blade). docs/block-schema.md is largely accurate against BlockContractValidator (the 16 top-level keys, 10 attribute types, and the full full-kit-overhaul sanitizer/support-group lists all match exactly), but has three real gaps versus the validator and the live heading.json contract: the entirely undocumented style.classNames conditional-class feature, the undocumented supports.states group, and an undercounted (11 vs. actual 16) control-type list missing checkbox/chips/unit/color/font. docs/BLUEPRINT.md's §1.4 DI bind-order code sample is stale versus the real service provider, which binds four additional post-template adapter contracts plus VirusScanner/MediaLibraryService that the blueprint never lists. docs/ROADMAP.md's Done section still claims '9 shipped contracts' and a completed 'Builder' milestone, both superseded by the 2026-08-02 reset (only heading/paragraph contracts remain; Builder was deleted). A ~10-item spot-check of TODO.md's checked-off items (0.3 middleware.editor, 1.6 unique slug index, 1.9 innerBlocks recursion depth cap, 1.10 SoftDeletes/cascade fix, 2.4 toolbar popover containers, 4.1 footer navigator.onLine wiring) found all of them genuinely implemented as described — no claimed-done-but-absent items surfaced in that sample.

Coverage: docs/file-structure.md read in full and diffed against a live `find` of resources/{views,css,js}, src/, routes/, config/ — confirmed Builder is fully deleted (no builder dirs/controller/route file) and Livewire is already a hard dependency, both contradicting the doc's premise; this is the dominant finding. docs/block-schema.md read in full and cross-checked field-by-field against BlockContractValidator.php (all constants: REQUIRED_TOP_LEVEL_KEYS, ATTRIBUTE_TYPES, SANITIZERS, SUPPORT_KEYS, ALIGN_VALUES, CONTROL_TYPES, TEMPLATE_NODE_TYPES, ORIENTATIONS, APPENDERS) and against the full heading.json contract; BlockRegistryService::derivePanels() panel-key table and BlockRenderer's dynamic-tag enum-constraint logic were also spot-checked and matched the doc closely (no material drift found there). docs/BLUEPRINT.md was skimmed for §1.4 (DI bind order) and the block-system/editor sections and diffed against HeisenbergServiceProvider.php in full; one drift item found (contract count). TODO.md and docs/ROADMAP.md were both read; ~10 TODO items checked off as [x] were spot-checked against source (0.3, 0.4 mimes/authorize, 1.2 PostController/PostPolicy existence, 1.6 unique(['locale','slug']) index, 1.9 innerBlocks recursion + MAX_NESTING_DEPTH, 1.10 SoftDeletes on Post/Block + cascadeOnDelete, 2.3-2.5 toolbar wiring, 2.4 data-tb-pop containers, 3.1 categories/tags pivot+controllers, 4.1 footer navigator.onLine) — all confirmed present as described; ROADMAP.md's own Done section was the one place stale-completion language was found, not TODO.md's checklist itself. Not independently re-verified in this pass: BLUEPRINT.md sections beyond §1.4 and the general block/editor prose (only skimmed per instructions), and TODO.md Phase 5-7 items beyond the sampled ~10.</notes>


### comment-noise

Swept 85 blade files under resources/views/ and 74 PHP files under src/ for comment noise. Found ~887 comment-marker lines in blade files and ~745 in PHP (true multi-line span materially higher, especially in inspector.blade.php whose comments regularly span 3-10 lines each). The dominant patterns are (a) Pencil/.pen design-file provenance tags opening nearly every live/* blade component, and (c) dated phase/overhaul changelog narration with stray TODO-N.M markers, both near-total candidates for deletion. Filed 15 worst-offender findings (inspector.blade.php, block-runtime.blade.php, EditorController.php, MediaLibraryService.php, topbar.blade.php, panel-style-themes.blade.php, BlockRegistryService.php, block-toolbar.blade.php, BlockRenderer.php, color-picker.blade.php, BlockContractValidator.php, SavePostRequest.php, custom-scrollbar.blade.php, HeisenbergServiceProvider.php, PostController.php), each with verbatim examples and any comments worth keeping. A small minority of comments (security/concurrency/DOM-timing invariants) are genuinely critical and should survive, but even those are currently over-written as multi-line paragraphs and should be compressed to 1-2 lines.

SWEEP SCOPE: 85 .blade.php files under resources/views/ (blade {{-- --}}, HTML <!-- -->, and // inside inline <script>) and 74 .php files under src/ (// , #, /* */ — excluding license headers and @props/@php). vendor/ and .pen files were not touched.

RAW COUNTS (marker-line counts, i.e. one count per comment occurrence/opening line — actual line-span of multi-line blade/JS comment blocks is materially higher, often 3-9 lines per marker in the worst files, e.g. inspector.blade.php's file-header comment alone spans 10 lines):
- Blade files: 887 comment-marker lines across 40 files that had any comments (out of 85 total blade files).
- PHP files: 745 comment-marker lines across ~40 files that had any (out of 74 total).
- Combined marker-line estimate: ~1,632 across the codebase; true comment-line span is likely 2,500-3,500+ once multi-line blocks are counted fully.

CATEGORY BREAKDOWN (based on the ~15 files inspected in depth plus spot checks of the remainder):
(a) design-file/builder references ("from Pencil Block (BfaTx)", "mirrors the builder's X", ".pen control kit") — very common, appears at the top of nearly every blade component (inspector.blade.php L1, topbar.blade.php L1, panel-style-themes.blade.php L1, block-toolbar.blade.php L1, color-picker.blade.php L1, plus references to resources/js/builder/02-toolbar.js scattered through block-toolbar.blade.php) and in PHP (BlockRegistryService.php L464-468, BlockContractValidator.php L37-47). Estimated 60-80 occurrences codebase-wide; all deletable.
(b) cross-file narration ("see 34-canvas.css", "see EditorController's constants", "mirrors BlockRenderer::stateStylesCss()") — the single largest category by volume (EditorController.php L21-24, block-runtime.blade.php L155-158, custom-scrollbar.blade.php L88-95/L103-111, panel-style-themes.blade.php L561). Estimated 150-200+ occurrences. Deletable except where the coupling is truly load-bearing and unenforced by tests/types, in which case a one-line pointer (not a paragraph) should survive.
(c) changelog/why-I-did-this narration, often dated ("Overhaul 2026-07-18", "Full-kit overhaul 2026-07-19 (Phase 1)", "Phase 3.1, 2026-08-04; reworked 2026-08-03") or referencing external process artifacts ("see the task report", "TODO 7.3", "TODO 7.6", "TODO 7.8") — extremely common, especially inspector.blade.php, block-runtime.blade.php, EditorController.php, SavePostRequest.php, HeisenbergServiceProvider.php, PostController.php's "Revision seam" block. Estimated 80-120 occurrences. All deletable; several describe bugs that are already fixed (SavePostRequest.php L50-56, custom-scrollbar.blade.php L103-111), pure history with no forward value.
(d) restating what the next line does — present but less dominant; scattered single-line instances (HeisenbergServiceProvider.php L169-171).
(e) genuinely critical constraints worth keeping — a small minority. Candidates: MediaLibraryService.php L61-68 (SVG/mime allow-list must be enforced service-side, security) and L366-370 (Windows-illegal filename chars); BlockRenderer.php L149-153 (reverse-tabnabbing guard) and L242-243 (static-vs-dynamic tag allow-list, XSS-adjacent); SavePostRequest.php L85-88 (present vs required on `blocks`); PostController.php L109-111 (transaction row-lock is the actual optimistic-concurrency gate); BlockContractValidator.php's "lockstep with BlockRenderer::cssValueValid()" warning (buried in L37-47, should be extracted); block-toolbar.blade.php L181-185 (mousedown must be blocked to preserve text selection); topbar.blade.php L371-375 (window.open() must be synchronous, pre-await, to avoid popup blocking); block-runtime.blade.php L316-319 (caret save/restore across re-render). All should survive but be cut to 1-2 lines each, stripped of surrounding narrative.

RANKED WORST-OFFENDERS TABLE (comment-marker lines / total lines, approx ratio):
1. resources/views/components/live/inspector.blade.php — 288/2213 (13.0%)
2. resources/views/components/live/block-runtime.blade.php — 92/918 (10.0%)
3. src/Http/Controllers/EditorController.php — 68/325 (20.9%)
4. src/Services/MediaLibraryService.php — 66/505 (13.1%)
5. resources/views/components/live/topbar.blade.php — 60/585 (10.3%)
6. resources/views/components/live/panel-style-themes.blade.php — 58/748 (7.8%)
7. src/Services/BlockRegistryService.php — 58/1366 (4.2%)
8. resources/views/components/live/toolbar/block-toolbar.blade.php — 53/347 (15.3%)
9. src/Services/BlockRenderer.php — 49/852 (5.8%)
10. resources/views/components/live/pickers/color-picker.blade.php — 46/829 (5.5%)
11. src/Services/BlockContractValidator.php — 45/580 (7.8%)
12. src/Http/Requests/SavePostRequest.php — 40/158 (25.3%, highest ratio of any file)
13. resources/views/components/ui/custom-scrollbar.blade.php — 38/343 (11.1%)
14. src/HeisenbergServiceProvider.php — 37/348 (10.6%)
15. src/Http/Controllers/PostController.php — 36/308 (11.7%)

Next tier not filed individually but comparably noisy: resources/views/components/ui/combobox.blade.php (27/350), resources/views/components/live/panel-navigator.blade.php (22/360), src/Services/PostTemplateContractValidator.php (21/455), src/Models/Post.php (20/248), resources/views/components/live/block/style-panel.blade.php (18/223), resources/views/components/live/footer.blade.php (17/327), resources/views/components/live/media/media-dialog.blade.php (17/286).

OVERALL PATTERN: the two dominant deletable categories are (a) Pencil/.pen/builder-file provenance comments — nearly always the very first comment in every live/* blade component, a single-sentence "from Pencil X (id)" tag — and (c) dated phase/overhaul changelog narration plus stray "TODO N.M" markers left over from a phased build-out (7.3, 7.6, 7.8 in block-runtime.blade.php and EditorController.php). Both categories are near-total to delete. The rarest and most valuable category (e) clusters almost entirely around security/concurrency/DOM-timing invariants in MediaLibraryService.php, BlockRenderer.php, PostController.php, and a handful of JS event-timing notes in block-toolbar.blade.php / topbar.blade.php / block-runtime.blade.php — these should be preserved but uniformly shortened to 1-2 lines, since even the "keep" candidates are currently written as 4-9 line paragraphs.

### php-services

Reviewed the PHP service layer end-to-end (BlockRegistryService, BlockRenderer, BlockContractValidator, PostTemplateContractValidator, PostTemplateRegistryService, BlocksPayloadService, ThemeRepository, MediaLibraryService, Support/*, Adapters/*, Contracts/*, Enums/*) plus a targeted diff against the JS runtime's render/sanitize logic in block-runtime.blade.php. The security-critical sanitizers (safeUrl, rich-text tag/attribute scrubbing, color/shadow/length grammars) are generally sound and match GTC parity as documented. The most consequential findings are on the explicitly-requested renderer/runtime fidelity axis: the JS canvas silently no-ops `inner-blocks` template nodes (container blocks preview empty vs. BlockRenderer's real recursive render), and the JS `cssValueValid()` port is missing 7 of BlockRenderer's Phase-1 sanitizer kinds (opacity, angle, shadow, align-3, position-mode, flex-*, overflow), falling through to a permissive generic regex where PHP enforces a strict allowlist — and `resources/blocks/heading/heading.json` actually uses several of the unported kinds, so this is a live, not theoretical, divergence. Secondary findings: BlockContractValidator validates only group *names* under `supports`, not their internal shape, for every group except align/states; the two contract validators duplicate `isSafeAssetPath`/`stringify` verbatim; both registry services cache their disk scan for the life of the (singleton-bound) service object with no invalidation hook, which is fine under classic PHP-FPM but stale under any persistent-worker runtime; a couple of low-severity robustness gaps (unanchored str_replace for relative-path computation, a 2-letter-only locale-suffix regex, and uncached per-request CSS file reads).

### php-http-security

Reviewed the HTTP/domain layer (controllers, middleware, requests, models, policies, service provider, media/editor routes, migrations, plus the block-rendering/sanitization services the mission called out for XSS). Authorization is generally solid and consistent: MediaLibraryController, PostController's JSON API, CategoryController, TagController, PostCategoryController, PostTagController, and PostSettingsController all correctly resolve an actor (real user or a GuestActor stand-in) and run the matching Policy check before any mutation, with a narrowly-scoped, environment-gated LocalDevRoleGate bypass that only ever affects GuestActor and only in app()-&gt;environment('local'). Mass assignment is properly scoped via fillable arrays (never guarded=[]) on every model, with lifecycle-sensitive fields (status/published_at/scheduled_at, page_padding_x/y, allow_comments) deliberately excluded from fillable and written only through their own guarded code paths. File uploads (UploadPublicFileRequest + MediaLibraryService::storeOne) layer request-level and service-level extension allow-listing, a virus-scan gate that fails closed by default, filename sanitization against path traversal and collisions, and a decompression-bomb guard before any image variant is decoded. BlockRenderer and HtmlSanitizationService (the XSS boundary for rendered post output) are heavily defensive: every text/attribute value is escaped or scheme/token-validated, dynamic tags are allow-listed, rich text is stripped to a safe tag/attribute set, and CSS values are validated per-kind. The one clear gap is that the page-rendering routes for a single post (GET /editor/{post} and GET /editor/{post}/preview) skip the PostPolicy view check that the equivalent JSON API (PostController::show) enforces, which, combined with the package's default open web-only editor middleware, lets an unauthenticated visitor read any post's full content, including unpublished drafts, by ID. A minor functional bug was also found in the locale switcher (a client-supplied return-URL field is silently dropped), and a low-severity defense-in-depth note on the dev-only upload-serving route.

### ui-components-pickers

Reviewed every file in resources/views/components/ui/ (36 primitives) and resources/views/components/live/pickers/, plus panel-navigator, panel-style-themes, panel-components-blocks, panel-seo-social, panel-ai-tools, side-panel, sidebar, topbar, footer, and the live/media/* components. The shared tab-family script (ui/partials/tablist-script.blade.php) and most atomic primitives (button, input, select, combobox, toggle, checkbox, radio, custom-scrollbar, disclosure-row, category-head) are well-built, idempotently re-bootable, and avoid id collisions by using data-attribute scoping rather than DOM ids. The most consequential problems found are: (1) three components that are effectively or completely dead in production (ui/var-menu-item, gradient-stop-row.blade.php, live/side-panel.blade.php) with live pickers hand-duplicating the same markup under separate class names instead of reusing them; (2) a systemic i18n gap in the live/media/* subtree and effect-editor.blade.php, where hardcoded English strings ship even at real (non-gallery) call sites, in sharp contrast to every sibling panel which routes its strings through __(); (3) four rendered search fields (Components, Blocks, Tools, Themes tabs) with zero filtering logic wired, while the visually identical media-library search field is fully functional; and (4) two small but real latent bugs (ui/radio's unnamespaced default name, ui/custom-scrollbar's unreachable smooth=false branch).

## Part 2 - Adversarially verified findings (critical/high)

### [partially-correct] [critical/bug] Numeric Style fields write bare digits; every sanitizer that consumes them requires an explicit unit, so the write is silently discarded at render time

**Location:** `resources/views/components/live/block-runtime.blade.php:137`

**Evidence:** block-runtime.blade.php:137 `isSafeLengthSignedValue` and :142 the `size-value` branch of `cssValueValid` both require a unit suffix (px|rem|em|%|vw|vh) on any non-zero number: `/^(var\(--[a-z0-9-]+\)|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i`. Every Style-tab field that writes a bare number types a raw digit string with no unit: style/dimensions.blade.php:4-5 (`value="Auto"`, `data-hb-control="size.width"/"size.height"`), style/spacing.blade.php:64-69 and :92-97 (`value="0"`, `data-hb-control="spacing.padding.*"/"spacing.margin.*"`), style/typography.blade.php:65 (`value="12.5"`, `typography.fontSize`) and :75 (`value="0"`, `typography.letterSpacing`), style/position.blade.php:4-8 (`value="0"`, `position.x`/`position.y`/`position.rotation`). inspector.blade.php's write path (handleControlEvent, ~1265-1277) passes `input.value` straight through with no unit normalization; `window.hbEditor.setSupport` (block-runtime.blade.php:541-557) stores it verbatim; `reRenderBlock`->`styleDeclarations` (block-runtime.blade.php:161-191) then runs `cssValueValid(value, sanitizer)` and, on failure, falls back to `definition.default` (empty string for every one of these variables in resources/blocks/*/*.json) — i.e. the field the user just edited renders as if untouched. Even the shipped DEFAULT value "0" for padding/margin fails this regex (bare "0" is only whitelisted by `length-signed`, not by `size-value`), so padding/margin literally never take effect from the inspector unless the user manually types e.g. "10px".

**Recommendation:** Normalize numeric Style-field values to include a unit before calling setSupport (e.g. append 'px' when the raw value is a bare number), or relax the size-value/length-signed sanitizers to accept a bare integer/decimal and assume px, mirroring what the UI actually collects.

**Verifier:** Verified the core mechanism end-to-end. block-runtime.blade.php:137 `isSafeLengthSignedValue` and :142 the `size-value` branch of `cssValueValid` (line 138-154) both require a unit suffix on any non-zero (and for size-value, even zero) number, exactly as quoted. Confirmed the write path: inspector.blade.php's `handleControlEvent` (1213-1291) for `type==='text'` sets `raw = ... input.value` verbatim (line 1271) with zero unit normalization anywhere in the file (grepped for px-appending/normalization logic — none exists outside the unrelated page-padding slider at lines 800-817); `commitSpacingGroup` (1491-1503) likewise writes `sides.top.value` etc. raw. `setSupport` (block-runtime.blade.php 541-557) stores the value verbatim. `styleDeclarations` (161-191) then calls `cssValueValid(value, sanitizer)`, and on failure falls back to `definition.default`, which is genuinely `""` for every one of these variables (verified in resources/blocks/paragraph/paragraph.json and heading/heading.json, e.g. lines 328-397). Verified the field markup exactly as cited: dimensions.blade.php:4-5 (`value="Auto"`, no `unit` prop), spacing.blade.php:64-69/92-97 (`value="0"`), typography.blade.php:65/75 (`value="12.5"`/`"0"`), position.blade.php:4-8 (`value="0"` x3) — none pass a `unit` prop to ui/field, and ui/field.blade.php's `unit` slot (line 46,63-67) is purely a decorative trailing label, never appended to the input's actual value. Confirmed `size-value`'s regex has no bare-"0" exception (unlike `length-signed`), so padding/margin/width/height/fontSize genuinely never take effect from a bare-digit inspector write, even "0". This is a real, critical, silently-failing defect exactly as described for size.width/height, spacing.padding.*/margin.* (all 8 sides), typography.fontSize, typography.letterSpacing, and position.x/position.y.

However, one item in the evidence is wrong: position.rotation (`style/position.blade.php:7-8`, `data-hb-control="position.rotation"`) is NOT governed by `length-signed` or `size-value`. Its contract sanitizer is `"angle"` (verified paragraph.json:428-432 and heading.json:465-468), and `cssValueValid` (block-runtime.blade.php:138-154) has no `angle` branch — it falls through to the permissive catch-all `/^[a-z0-9\s().,%_\/-]+$/i` (line 153), which happily accepts bare digits like "45" or "0". So the rotation field is not actually broken by this bug, contrary to being lumped in with X/Y in the claim's evidence list. The claim's central mechanism, recommendation, and severity are otherwise well-supported, so I'm marking this partially-correct rather than confirmed outright — the defect is real and critical for the large majority of cited fields, but the evidence over-reaches by including position.rotation.

**Corrected:** The unit-suffix bug is real for size.width/height, spacing.padding.*/margin.* (all 8 sides), typography.fontSize, typography.letterSpacing, and position.x/position.y — confirmed via block-runtime.blade.php:137-154, inspector.blade.php:1213-1291/1491-1503, and the size-value/length-signed defaults ("") in resources/blocks/*/*.json. position.rotation is NOT affected: its sanitizer is 'angle', which cssValueValid does not special-case, so it falls through to a permissive regex that accepts bare numbers — that field works fine with plain digits.

### [confirmed] [critical/bug] Toolbar Align (and inspector Alignment) writes `hb-align-<value>` but no CSS anywhere defines that class — clicking Left/Center/Right never moves the block

**Location:** `src/Support/SupportsStyle.php:42`

**Evidence:** The full mutation chain is wired correctly end-to-end: align-menu.blade.php's option click dispatches `alignselect` (line 35) -> block-toolbar.blade.php's listener (lines 306-310) calls `window.hbEditor.setSupport(ctx.id, 'align', e.detail.value)` -> block-runtime.blade.php's setSupport() writes model.supports.align and calls reRenderBlock() -> renderBlockEl()->renderNode() (lines 249-250) checks `alignmentValuesFor(model.name).indexOf(alignment) >= 0` and adds `el.classList.add('hb-align-' + alignment)`. The identical mechanism exists server-side in src/Services/BlockRenderer.php:273-283 (resolveClass()), used for the saved/public-preview render, and the inspector's own Alignment control (resources/views/components/live/block/style/alignment.blade.php) writes the exact same `supports.align` path. BUT the CSS class `.hb-align-left/center/right` is never defined anywhere in the codebase. SupportsStyle.php's own docblock (lines 42-45) and docs/block-schema.md (lines 116, 203) and TODO.md (line 507-509, which incorrectly claims it is 'already working') all assert these rules live in `resources/css/builder.css` — that file does not exist anywhere in the repo (confirmed via filesystem search; it was presumably deleted when 'the builder was removed' per src/Support/BlockViewData.php's own comment, 2026-08-02). None of the actually-shipped stylesheets (resources/css/editor/*.css, which EditorController::css() concatenates for the live editor; resources/blocks/heading/heading.css; resources/blocks/paragraph/paragraph.css; SupportsStyle::css(), used by both the editor and preview.blade.php) contain any `.hb-align-*` rule. A grep for `hb-align-(left|center|right)` across the whole repo (excluding vendor/) matches only doc comments and a unit test asserting the class STRING is present in the HTML — never a CSS selector. Net effect: the class is added to the DOM correctly (which is presumably why a prior author believed 'it already works'), but zero visual change occurs, in both the live editor canvas and the public preview/saved page.

**Recommendation:** Add `.hb-align-left { margin-left:0; margin-right:auto; }` / `.hb-align-center { margin-left:auto; margin-right:auto; }` / `.hb-align-right { margin-left:auto; margin-right:0; }` (or the equivalent text-align/flex rule appropriate to how these blocks are laid out) to a stylesheet that is actually served — either resources/css/editor/35-blocks.css (editor) plus wherever preview.blade.php's blocksCss is assembled, or centralize it in SupportsStyle::alignBreakoutRules() alongside the existing (and working) hb-align-wide/hb-align-full rules, since that method already runs on every request that needs it.

**Verifier:** Verified every link in the claim's evidence chain by reading the actual files:

1. src/Support/SupportsStyle.php:42-45 (docblock) explicitly states hb-align-left/center/right are "declared in `resources/css/builder.css`".
2. `resources/css/builder.css` does not exist anywhere in the repo (filesystem search over the whole tree, vendor excluded, returned nothing).
3. Client-side: resources/views/components/live/block-runtime.blade.php:249-250 confirms `alignmentValuesFor(model.name).indexOf(alignment) >= 0` gates `el.classList.add('hb-align-' + alignment)` — exactly as cited.
4. Server-side: src/Services/BlockRenderer.php:277-282 (resolveClass()) confirms the identical `hb-align-<alignment>` class is appended for left/center/right/wide/full, guarded by the same allow-list check the claim describes.
5. The inspector's Alignment control (resources/views/components/live/block/style/alignment.blade.php:5-11, 30-31) writes through `data-hb-control="align" data-hb-control-kind="supports"`, i.e. `supports.align`, matching the claim's description of the inspector path.
6. A grep for `hb-align-(left|center|right)` across the whole repo (vendor excluded) hits only: two doc-comment mentions in docs/block-schema.md, two docblock mentions in SupportsStyle.php itself, and one unit test (tests/Engine/BlockRendererTest.php:192) asserting the class STRING appears in rendered HTML — never a CSS selector definition.
7. Directly grepped resources/css/**/*.css and resources/blocks/**/*.css for `hb-align`: the only hit is the unrelated `.hb-alignmenu` (toolbar dropdown width) in resources/css/editor/33-toolbar.css:56 — not a rule for the block-root alignment classes.

So the claim's core assertion holds: the class-mutation pipeline (toolbar → setSupport → reRenderBlock → DOM class add, and the parallel server-side resolveClass()) is wired correctly end-to-end and does add `hb-align-left/center/right` to the block root in both the editor canvas and the server-rendered/preview HTML, but no stylesheet actually shipped in the app (editor CSS bundle, block CSS, or SupportsStyle::css()) defines any rule for those class names, because the file that was supposed to hold them (`resources/css/builder.css`) no longer exists. The net effect described — clicking Left/Center/Right changes the DOM class but produces zero visual change — is accurate and verified, not inferred from memory.

Location and line citations (SupportsStyle.php:42, BlockRenderer.php:273-283, block-runtime.blade.php:249-250, align-menu/block-toolbar/alignment.blade.php) all check out against the actual file contents.

### [partially-correct] [high/bug] Position (x/y/rotation/mode), Effects/shadow, Appearance/opacity and block Alignment write and re-render correctly but paint nothing — the block stylesheets never consume the custom properties/class the runtime sets

**Location:** `resources/blocks/paragraph/paragraph.css:1`

**Evidence:** block-runtime.blade.php's styleDeclarations() (161-191) correctly resolves `supports.position.x/y/rotation/mode`, `supports.effects.shadow`, `supports.appearance.opacity` (all declared with real `style.variables` entries in resources/blocks/paragraph/paragraph.json:413-442 and resources/blocks/heading/heading.json:450-479) and writes them inline as `--hb-tx`, `--hb-ty`, `--hb-rotate`, `--hb-position-mode`, `--hb-shadow`, `--hb-opacity` on the block's root element (renderNode, block-runtime.blade.php:251-252). Alignment is handled by bespoke code at block-runtime.blade.php:249-250, which adds a `hb-align-<value>` class. All the corresponding inspector controls are wired end-to-end: style/position.blade.php:4-16 (`data-hb-control="position.x/y/rotation/mode"`), style/effects.blade.php + inspector.blade.php:1629-1653 (`hbComposeShadow` -> `setSupport(id, ..., 'effects.shadow', css)`), style/appearance.blade.php:16 (`appearance.opacity`), style/alignment.blade.php:30-31 (`data-hb-control="align"`). But grepping the actual stylesheets that style these two shipped blocks (resources/blocks/paragraph/paragraph.css, resources/blocks/heading/heading.css) and the whole resources/ tree shows no rule anywhere reads `--hb-tx`, `--hb-ty`, `--hb-rotate`, `--hb-position-mode`, `--hb-shadow`, `--hb-opacity`, or defines `.hb-align-left/-center/-right`. The custom properties/class land on the DOM but map to no declared CSS property, so the canvas is visually identical before and after the edit.

**Recommendation:** Add the missing consuming rules to each block's own stylesheet (e.g. `transform: translate(var(--hb-tx,0), var(--hb-ty,0)) rotate(var(--hb-rotate,0))`, `position: var(--hb-position-mode, static)`, `opacity: var(--hb-opacity, 1)`, `box-shadow: var(--hb-shadow, none)`, and `.hb-align-left { margin-inline: 0 auto }` etc.) so the variables/class the runtime already computes actually render.

**Verifier:** Verified by reading the cited files plus the wider system they connect to.

What the claim gets wrong: it asserts "no rule anywhere reads --hb-tx/--hb-ty/--hb-rotate/--hb-position-mode/--hb-shadow/--hb-opacity" and that fixes belong in each block's own CSS file (paragraph.css/heading.css). That's false. src/Support/SupportsStyle.php:115-135 defines exactly such a rule — `[data-block-id].hb-supports { opacity: var(--hb-opacity,1); position: var(--hb-position-mode,static); transform: translate(var(--hb-tx,0px), var(--hb-ty,0px)) rotate(var(--hb-rotate,0deg)); box-shadow: var(--hb-shadow,none); ... }` — and this generated stylesheet is prepended into the CSS actually served to the editor canvas: BlockViewData::blocksCss() (src/Support/BlockViewData.php:90-92) calls SupportsStyle::css(), EditorController.php:101 passes it as `$blocksCss`, and block-runtime.blade.php:53 emits `<style id="hb-blocks-css">{!! $blocksCss !!}</style>` inside the editor page. So a consuming rule for these six variables genuinely exists and genuinely loads in the canvas — the claim's central factual assertion is refuted.

What the claim gets right, for a different reason than stated: the rule only fires on `[data-block-id].hb-supports` (both selectors required). paragraph.json:215 and heading.json:254 do declare `"className": "hb-block-paragraph hb-supports"`, and the *server-side* PHP renderer (src/Services/BlockRenderer.php:251-284, `resolveClass()`) does apply `contract.style.className` (adding `hb-supports`) when rendering — but that path is used for preview/publish (resources/views/preview.blade.php), not the editor canvas. The editor canvas is built entirely by the client-side JS in block-runtime.blade.php: `renderNode()` (lines 218-253) sets the root element's class only from `node.class` (the render.template's own class string, e.g. `"hb-block hb-block-paragraph {{attributes.extraClasses}}"` — no `hb-supports`) and from `contract.style.classNames` (the conditional array) — it never reads `contract.style.className` (singular) at all (confirmed by grep: only one hit, and it's the `classNames` field, not `className`). `renderBlockEl`/`renderNode` is also the sole rendering path used for both initial paint and re-renders (no separate server-rendered initial DOM). Consequently `.hb-supports` never lands on a block root in the canvas, so the SupportsStyle rule for opacity/position/transform/shadow never actually matches there, even though the inline `--hb-*` custom properties are correctly written (confirming that half of the reviewer's chain).

Alignment: the reviewer's narrower claim here holds up on its own evidence — `hb-align-left/-center/-right` classes are applied (block-runtime.blade.php:250 client-side, and BlockRenderer.php:282 server-side) but no CSS rule defining `.hb-align-left/-center/-right` exists anywhere in the repo (full-tree grep across all *.css found zero matches). Only `hb-align-wide`/`hb-align-full` have rules (SupportsStyle.php:182-185). Note the misleading source comment at SupportsStyle.php:45 claiming left/center/right are "declared in resources/css/builder.css" — no such file exists in the repo (Glob found none).

Net: the reviewer correctly identified a real defect (canvas doesn't visually reflect position/effects/appearance/shadow edits, and alignment left/center/right also paints nothing) but mischaracterized the mechanism and cause. The location cited (paragraph.css:1) and the recommended fix (hand-add rules to each block's own stylesheet) are wrong/redundant — a shared, correctly-gated stylesheet (SupportsStyle) already implements the fix for opacity/position/transform/shadow; the actual bug is that block-runtime.blade.php's client-side `renderNode()` never applies `contract.style.className` (the `hb-supports` activation class) to the canvas block root, so a shared rule that does exist never gets a chance to match. Alignment's fix would need a genuinely new rule, but placed in SupportsStyle.php or a shared editor stylesheet — not in per-block CSS as recommended, since the codebase's convention (per its own docblocks) is not to hand-author per-block CSS for these generic capabilities.

**Corrected:** The custom properties are NOT unconsumed in general — src/Support/SupportsStyle.php:115-135 defines `[data-block-id].hb-supports { opacity: var(--hb-opacity,...); position: var(--hb-position-mode,...); transform: translate(var(--hb-tx,...), var(--hb-ty,...)) rotate(var(--hb-rotate,...)); box-shadow: var(--hb-shadow,...); }`, and this is loaded into the editor canvas via BlockViewData::blocksCss() -> EditorController.php:101 -> block-runtime.blade.php:53. The real defect is that the editor canvas is rendered purely client-side by block-runtime.blade.php's renderNode()/renderBlockEl(), which builds the root element's class from render.template's `class` field and `style.classNames` only — it never reads `contract.style.className` (confirmed: the only match for that string in resources/ is the unrelated `classNames` field), so the `hb-supports` class that paragraph.json:215/heading.json:254 declare, and that the SupportsStyle rule requires as a selector prefix, never gets attached to canvas block roots even though the server-side BlockRenderer.php:251-284 does apply it correctly for preview/publish rendering. Separately, alignment (`hb-align-left/-center/-right`) genuinely has no consuming CSS rule anywhere in the repo (confirmed by full-tree grep) — only `hb-align-wide/-full` are styled, in SupportsStyle.php, not in any per-block CSS or a `resources/css/builder.css` (which does not exist despite being referenced in a comment). The fix belongs in block-runtime.blade.php's renderNode() (apply contract.style.className) and/or SupportsStyle.php (add left/center/right rules), not in each block's own paragraph.css/heading.css as recommended.

### [refuted] [high/bug] Typography's Text-horizontal, Text-vertical and Letter-spacing controls have the same dead-CSS problem as Position/Effects

**Location:** `resources/blocks/heading/heading.css:1`

**Evidence:** style/typography.blade.php:83-109 wires `typography.textAlign`/`typography.textAlignVertical`/`typography.letterSpacing` through the same delegated `setSupport` path, and both contracts declare matching `style.variables` (`--hb-text-align`, `--hb-text-align-v`, `--hb-letter-spacing` — paragraph.json:398-412, heading.json:435-449), so `styleDeclarations()` computes and inlines them correctly. Neither paragraph.css nor heading.css contains a `text-align: var(--hb-text-align)`, `align-self: var(--hb-text-align-v)`, or `letter-spacing: var(--hb-letter-spacing)` rule (grep across resources/ confirms these three custom-property names appear only in the JSON contracts and the picker/typography Blade files, never in a stylesheet), so these three Style controls can never visibly change the canvas.

**Recommendation:** Add the missing text-align / align-self / letter-spacing declarations (reading the corresponding custom properties) to both block stylesheets.

**Verifier:** The claim's central evidence — 'grep across resources/ confirms these three custom-property names appear only in the JSON contracts and the picker/typography Blade files, never in a stylesheet' — is true only because the grep scope was limited to resources/. The actual consumer lives in src/Support/SupportsStyle.php, outside that scope. SupportsStyle::baseCapabilitiesRule() (lines 115-135) emits `letter-spacing: var(--hb-letter-spacing, normal)`, `text-align: var(--hb-text-align, initial)`, and `align-self: var(--hb-text-align-v, auto)` inside a rule scoped to `[data-block-id].hb-supports`.

This generated stylesheet is not dead code: BlockViewData::blocksCss() (src/Support/BlockViewData.php:90-92) explicitly prepends `SupportsStyle::css()` to the concatenated block CSS ('$chunks = ["/* supports capabilities */\n" . SupportsStyle::css()];'), and that method is called by both EditorController.php:101 (feeds the live editor canvas) and PreviewController.php:123 (feeds the published output) — so the rule reaches every render path.

The gating marker class `.hb-supports` — which the claim never mentions — is present on both blocks: heading.json:254 declares `"className": "hb-block-heading hb-supports"` and paragraph.json:215 declares `"className": "hb-block-paragraph hb-supports"`. So the rule is reachable for exactly the two blocks named in the claim.

typography.blade.php's own comments (lines 81-83) document this directly: 'via SupportsStyle's --hb-text-align / --hb-text-align-v' and 'SupportsStyle emits these through align-self, whose sanitizer is align-3'. Both sanitizer kinds ('text-align', 'align-3') are registered in BlockContractValidator::SANITIZERS (line 49), confirming the pipeline from control -> setSupport -> styleDeclarations() -> sanitized inline var -> consuming CSS rule is intact.

The BlockViewData.php docblock (lines 81-88) even narrates that this exact defect used to exist ('was unreachable in the canvas no matter what a contract declared... It is prepended... TODO 7.1') and was already fixed by prepending SupportsStyle::css() and having contracts opt in via the hb-supports class — which heading and paragraph both already carry.

**Corrected:** The three custom properties are consumed by a generated, shared stylesheet (src/Support/SupportsStyle.php::baseCapabilitiesRule(), lines 118-121: letter-spacing/text-align/align-self declarations) rather than by heading.css/paragraph.css directly. That sheet is prepended to every block's CSS by BlockViewData::blocksCss() (lines 90-92), which both EditorController and PreviewController call, and it is gated behind the `.hb-supports` marker class, which both heading.json (line 254) and paragraph.json (line 215) already carry in their `className`. Text-horizontal, Text-vertical, and Letter-spacing are wired and should visibly affect the canvas for these two blocks — no CSS is missing.</corrected>
<parameter name="severity">low

### [confirmed] [high/state] No canonical document model — post title lives only in the DOM, split across two hand-synced elements

**Location:** `resources/views/components/live/canvas.blade.php:27`

**Evidence:** block-runtime.blade.php:72 defines the ONLY document-model object in the app: `const doc = { blocks: [] };` — it has no `title` field. The post title instead lives purely as DOM state on two separate elements, both marked `data-hb-title`: the canvas h1 (canvas.blade.php:13) and the inspector Post-tab `<input>` (inspector.blade.php:287). canvas.blade.php:23-51 keeps them in sync by hand: on `input`, it walks `document.querySelectorAll('[data-hb-title]')` and copies the typed value into every OTHER title element (line 39), guarded by a `let syncing = false;` reentrancy flag (line 27, checked/set lines 37-43). Every consumer that needs 'the current title' re-derives it independently by re-querying the DOM: topbar.blade.php:142-146 `hbReadTitle()`, panel-navigator.blade.php:93-94 `titleText()`. There is no single place that owns 'what is the document title right now'.

**Recommendation:** Fold title into the same doc model block-runtime already owns (e.g. `doc.title`) with a `window.hbEditor.setTitle()`/`getTitle()` pair mirroring setAttribute, and drive both DOM inputs from `hb:doc-title` instead of letting each one write directly into the others' DOM nodes.

**Verifier:** Verified every citation directly against the source:

- block-runtime.blade.php:72 — `const doc = { blocks: [] };` — confirmed, no `title` field. Also confirmed window.hbEditor's public API (grep across the file) exposes no getTitle/setTitle — title is entirely outside the runtime's owned state.
- canvas.blade.php:13 — the h1 carries `data-hb-title`; inspector.blade.php:287 — the Post-tab `<input>` also carries `data-hb-title`. Two independent DOM nodes for the same conceptual value, exactly as claimed.
- canvas.blade.php:27 `let syncing = false;`, and the input handler at 36-44: on `input` it sets `syncing = true` (37), does `document.querySelectorAll('[data-hb-title]').forEach(...)` to copy the value into every *other* title element (39), then clears `syncing = false` (43) — matches the claim's description of hand-rolled reentrancy-guarded DOM-to-DOM sync exactly, line numbers included.
- topbar.blade.php:142-146 `hbReadTitle()` — re-queries `document.querySelector('[data-hb-title]')` independently rather than reading any shared model.
- panel-navigator.blade.php:93-94 `titleText()`/`titleEl()` — same pattern, its own independent DOM re-query.

One nuance not credited in the claim's recommendation: an `hb:doc-title` CustomEvent already exists and is dispatched (canvas.blade.php:42) and is already consumed by block-runtime.blade.php:300 (marks doc dirty for autosave) and panel-navigator.blade.php:305 (rebuilds outline) — so the event-driven plumbing the recommendation asks for partially already exists. However the actual synchronization of the two title DOM nodes is still done by direct cross-element DOM writes (line 39), not by the event, and the doc model genuinely has no title field — so the core defect (no canonical document-model field for title; two hand-synced DOM elements; independent re-derivation by multiple consumers) is accurate as described. This is a real state-management gap, not merely a description error, though it is a deliberate/working pattern (documented in canvas.blade.php's own header comment) rather than a currently-broken feature — consistent with "high" severity for an architectural/maintainability defect rather than a live user-facing bug.

### [confirmed] [high/state] Save/version state and postId are duplicated across five independent closures, synced only by a broadcast event

**Location:** `resources/views/components/live/topbar.blade.php:120`

**Evidence:** topbar.blade.php:120-131 owns the only real save state machine (`hbPostId`, `hbContentVersion`, `hbDirty`, `hbConflicted`, `hbSaveInFlight`) in a private closure invisible outside the file — not on `window.hbEditor`. inspector.blade.php then independently re-derives its OWN copy of `postId` in four separate closures, each seeded from its own `data-hb-post-id` DOM attribute and each listening for `hb:post-id` on its own: wirePostTaxonomy (line 640, instantiated once for Categories and again for Tags), wirePostDiscussion (line 745), wirePostLayout (line 787). That is five separate in-memory copies of the same scalar (postId), kept consistent only because every one of them happens to also listen for the single `hb:post-id` broadcast (topbar.blade.php:245; listeners at inspector.blade.php:727, 761, 825) — there is no shared store a sixth consumer could just read.

**Recommendation:** Expose postId/contentVersion/dirty state on window.hbEditor (or a small shared store) as the one place it lives, and have every consumer read from it instead of keeping a private mirrored copy seeded from a DOM data attribute.

**Verifier:** Every specific assertion in the claim checks out against the actual code. topbar.blade.php:120-131 declares hbPostId, hbContentVersion, hbDirty, hbConflicted, hbSaveInFlight as `let` variables inside a private IIFE closure (verified by reading the surrounding boot script, e.g. lines 161-281 all reference/mutate these same closure-scoped variables). window.hbEditor, defined at block-runtime.blade.php:889-915, exposes only document/block-model methods (getDoc, getSelectedId, getModel, buildSavePayload, insertBlock, setAttribute, etc.) — no postId/contentVersion/dirty/conflicted/saveInFlight fields, confirming topbar's state machine is invisible outside its own file (topbar even calls window.hbEditor.buildSavePayload for the doc but keeps postId/version locally, per topbar.blade.php:204-213).

In inspector.blade.php, wirePostTaxonomy (defined at line 629) is registered via document.querySelectorAll('[data-hb-post-taxonomy-field]').forEach(wirePostTaxonomy) at line 835, and there are indeed two separate DOM nodes carrying that attribute — Categories (line 380) and Tags (line 412) — each with its own data-hb-post-id attribute (lines 381, 413), so wirePostTaxonomy runs twice, each invocation creating its own `let postId` closure variable (line 640) seeded from that node's own dataset.hbPostId, and each registering its own `hb:post-id` listener (line 727) that reassigns only its local postId. wirePostDiscussion (line 737, field at line 445-446, postId at line 745, listener at line 761) and wirePostLayout (line 776, field at line 461-462, postId at line 787, listener at line 825) follow the identical pattern.

That is exactly four independent postId closures in inspector.blade.php (Categories, Tags, Discussion, Layout) plus the topbar's own copy — five in total — each seeded from a DOM data attribute and kept in sync only by all independently listening for the same 'hb:post-id' CustomEvent (dispatched once, at topbar.blade.php:245). There is no shared store; a hypothetical sixth consumer would have to duplicate the same seed+listen pattern rather than read one source of truth. The recommendation (expose the state on window.hbEditor or a shared store) is a reasonable fix given the verified facts. Severity of "high" is defensible given this is a real architectural smell (five independent mirrors of save-critical state kept in sync only by convention), though it hasn't caused an observed bug in the code read — it's a latent maintainability/consistency risk rather than a currently-firing defect.

### [confirmed] [high/bug] Editor canvas never renders inner-blocks — container blocks preview empty while BlockRenderer renders their children

**Location:** `resources/views/components/live/block-runtime.blade.php:239`

**Evidence:** renderNode() explicitly does `// inner-blocks (columns) not supported in this pass — render nothing.` / `if (type === 'inner-blocks') return document.createDocumentFragment();` (lines 239-240). By contrast, `src/Services/BlockRenderer.php` renderNode() (lines 135-137) handles the same `inner-blocks` template node type by calling `renderInnerBlocks()` (lines 190-200), which recurses through every child in `block.innerBlocks`, rendering each through its own contract. Any block contract that uses an `inner-blocks` template node (e.g. a future `columns`/container block — `BlockType::COLUMNS` already exists in `src/Enums/BlockType.php`) would show up completely empty in the editor's live canvas while the published/rendered output shows the real nested content. Not yet triggered by the two shipped contracts (heading, paragraph — neither declares innerBlocks), but it is a genuine, load-bearing gap between the two engines that will silently break the first container block shipped.

**Recommendation:** Port BlockRenderer::renderInnerBlocks()'s recursion into block-runtime.blade.php's renderNode(), or explicitly gate innerBlocks-enabled contracts out of the editor until the client-side walk supports the node type, with a visible placeholder rather than a silent empty render.

**Verifier:** Verified every load-bearing element of the claim by reading the actual code:

1. resources/views/components/live/block-runtime.blade.php:239-240 — the client-side `renderNode()` walk explicitly short-circuits `inner-blocks` template nodes: `// inner-blocks (columns) not supported in this pass — render nothing.` / `if (type === 'inner-blocks') return document.createDocumentFragment();`. This is the only client-side render path: `renderBlockEl()` (line 273-286) calls `renderNode(c.template, model, c, true)` directly — there is no server round-trip for the live canvas preview.

2. src/Services/BlockRenderer.php:135-137 handles the same `inner-blocks` node type by calling `renderInnerBlocks()`, which (lines 190-200) iterates `block['innerBlocks']` and recurses each child through `renderBlockAtDepth`, i.e. through the child's own contract. This is the "publish/render" engine, confirmed by tests/Engine/BlockRendererInnerBlocksTest.php, which is written explicitly against "temp fixture contracts so the capability is exercised before any real container block ships" — corroborating that no real container block ships yet, matching the claim's own caveat.

3. `BlockType::COLUMNS` does exist at src/Enums/BlockType.php:35 (`case COLUMNS = 'columns';`), with a label and icon already wired at lines 76 and 107 — i.e. the enum anticipates a container block that hasn't shipped a contract yet.

4. Checked the two shipped contracts: resources/blocks/heading/heading.json:509-511 and resources/blocks/paragraph/paragraph.json:472-474 both declare `"innerBlocks": { "enabled": false }` and neither template uses an `inner-blocks` template node — confirming the claim's statement that the gap is real but "not yet triggered" by shipped contracts.

Every cited line number, mechanism, and asymmetry between the two engines checks out under direct reading. The severity framing (a genuine, currently-latent, high-impact gap that will silently break the first container block, e.g. the already-enum'd `columns` block) is accurate and appropriately calibrated — not overstated, not already mitigated elsewhere.

### [confirmed] [high/bug] JS cssValueValid() is missing 7 of the Phase-1 sanitizer kinds; falls through to a permissive generic regex for values BlockRenderer validates strictly

**Location:** `resources/views/components/live/block-runtime.blade.php:138`

**Evidence:** PHP's `BlockRenderer::cssValueValid()` (src/Services/BlockRenderer.php:649-683) has an explicit `match` arm — with the comment 'never fall back to the permissive default case for any of these' — for `color-token`, `color-token-or-transparent`, `opacity`, `angle`, `shadow`, `align-3`, `position-mode`, `flex-direction`, `flex-justify`, `flex-align`, `overflow`. The JS port (block-runtime.blade.php lines 138-154) only implements `border-style`, `font-token`, `size-value`, `color-value`, `font-family`, `font-weight`, `size-token`, `integer`, `length-signed`, `text-align` — every other sanitizer, including `opacity`, `angle`, `shadow`, `align-3`, `position-mode`, falls through to the generic `/^[a-z0-9\s().,%_\/-]+$/i` (line 153), which accepts values the strict server-side grammars reject (e.g. `position-mode` accepts any lowercase token instead of only static|relative|absolute; `angle` accepts a bare number with no `deg` suffix; `shadow` accepts any comma/paren string instead of requiring the structured inset/length×2-4/single-color layer grammar `isSafeShadowValue()` enforces). `resources/blocks/heading/heading.json` (lines 435-479) actually declares style.variables using `align-3` (`--hb-text-align-v`), `opacity` (`--hb-opacity`), `angle` (`--hb-rotate`), `shadow` (`--hb-shadow`), and `position-mode` (`--hb-position-mode`) — so this is a live divergence on a shipped block, not theoretical: a value the editor canvas happily previews can be silently rejected/defaulted by BlockRenderer at publish time, or conversely an out-of-spec value (e.g. rotation '45' without 'deg', or an invalid position mode) previews fine in the editor and then reverts to the CSS default on the published page.

**Recommendation:** Port the missing sanitizer branches (color-token, color-token-or-transparent, opacity, angle, shadow, align-3, position-mode, flex-direction, flex-justify, flex-align, overflow) into the JS cssValueValid() verbatim from BlockRenderer::cssValueValid(), removing the permissive default fallback for these kinds exactly as the PHP side's docblock mandates.

**Verifier:** Read both implementations directly and confirmed the gap exactly as described.

PHP `BlockRenderer::cssValueValid()` (src/Services/BlockRenderer.php:649-683) has explicit match arms for color-token, color-token-or-transparent, border-style, font-token, size-value, color-value, font-family, font-weight, size-token, integer, opacity, angle, length-signed, shadow, text-align, align-3, position-mode, flex-direction, flex-justify, flex-align, overflow — with a comment at lines 667-669 stating every Phase-1 kind "gets its OWN explicit case (never the permissive default below)".

The JS port in resources/views/components/live/block-runtime.blade.php:138-154 (`cssValueValid`, the sole JS implementation of this function — confirmed no duplicate exists in inspector.blade.php) only has explicit branches for border-style, font-token, size-value, color-value, font-family, font-weight, size-token, integer, length-signed, text-align. It is missing color-token, color-token-or-transparent, opacity, angle, shadow, align-3, position-mode, flex-direction, flex-justify, flex-align, and overflow — all of which fall through to the generic `/^[a-z0-9\s().,%_\/-]+$/i` at line 153, exactly as claimed.

Verified this is a live, shipped divergence, not theoretical: resources/blocks/heading/heading.json (lines 440-479) and resources/blocks/paragraph/paragraph.json (lines 406-441) both declare style.variables using align-3, opacity, angle, shadow, and position-mode sanitizers — two shipped blocks, five affected sanitizer kinds each. I spot-checked the practical effect for `angle`: the rotation UI control (resources/views/components/live/block/style/position.blade.php:8) is a free-text field with no unit enforcement anywhere in the JS (`grep` for "deg" in both block-runtime.blade.php and inspector.blade.php returns nothing), so a user-entered value like "45" is accepted by the JS generic fallback but would be rejected by PHP's strict `/^-?\d{1,3}(\.\d+)?deg$/i` angle regex — a genuine editor/publish-time behavioral mismatch, matching the claim's described failure mode.

Line citations (block-runtime.blade.php:138-154, BlockRenderer.php:649-683) are accurate. The comment quote is a close paraphrase rather than verbatim but captures the same substance, not a material inaccuracy.

On severity: this is a genuine correctness/consistency bug reachable through normal editor use on shipped blocks, not merely theoretical — I'd call it credible for "high" though reasonable people could argue "medium" since the generic fallback regex is still fairly restrictive (no security/XSS exposure was found; this is a preview/publish divergence, not an injection vector).</reasoning>
<parameter name="severity">high

## Part 3 - All findings by severity

### [critical/bug] Numeric Style fields write bare digits; every sanitizer that consumes them requires an explicit unit, so the write is silently discarded at render time

**Location:** `resources/views/components/live/block-runtime.blade.php:137`  |  dimension: inspector-dataflow

**Evidence:** block-runtime.blade.php:137 `isSafeLengthSignedValue` and :142 the `size-value` branch of `cssValueValid` both require a unit suffix (px|rem|em|%|vw|vh) on any non-zero number: `/^(var\(--[a-z0-9-]+\)|-?\d+(\.\d+)?(px|rem|em|%|vw|vh))$/i`. Every Style-tab field that writes a bare number types a raw digit string with no unit: style/dimensions.blade.php:4-5 (`value="Auto"`, `data-hb-control="size.width"/"size.height"`), style/spacing.blade.php:64-69 and :92-97 (`value="0"`, `data-hb-control="spacing.padding.*"/"spacing.margin.*"`), style/typography.blade.php:65 (`value="12.5"`, `typography.fontSize`) and :75 (`value="0"`, `typography.letterSpacing`), style/position.blade.php:4-8 (`value="0"`, `position.x`/`position.y`/`position.rotation`). inspector.blade.php's write path (handleControlEvent, ~1265-1277) passes `input.value` straight through with no unit normalization; `window.hbEditor.setSupport` (block-runtime.blade.php:541-557) stores it verbatim; `reRenderBlock`->`styleDeclarations` (block-runtime.blade.php:161-191) then runs `cssValueValid(value, sanitizer)` and, on failure, falls back to `definition.default` (empty string for every one of these variables in resources/blocks/*/*.json) — i.e. the field the user just edited renders as if untouched. Even the shipped DEFAULT value "0" for padding/margin fails this regex (bare "0" is only whitelisted by `length-signed`, not by `size-value`), so padding/margin literally never take effect from the inspector unless the user manually types e.g. "10px".

**Recommendation:** Normalize numeric Style-field values to include a unit before calling setSupport (e.g. append 'px' when the raw value is a bare number), or relax the size-value/length-signed sanitizers to accept a bare integer/decimal and assume px, mirroring what the UI actually collects.

### [critical/bug] Toolbar Align (and inspector Alignment) writes `hb-align-<value>` but no CSS anywhere defines that class — clicking Left/Center/Right never moves the block

**Location:** `src/Support/SupportsStyle.php:42`  |  dimension: toolbar-canvas-dataflow

**Evidence:** The full mutation chain is wired correctly end-to-end: align-menu.blade.php's option click dispatches `alignselect` (line 35) -> block-toolbar.blade.php's listener (lines 306-310) calls `window.hbEditor.setSupport(ctx.id, 'align', e.detail.value)` -> block-runtime.blade.php's setSupport() writes model.supports.align and calls reRenderBlock() -> renderBlockEl()->renderNode() (lines 249-250) checks `alignmentValuesFor(model.name).indexOf(alignment) >= 0` and adds `el.classList.add('hb-align-' + alignment)`. The identical mechanism exists server-side in src/Services/BlockRenderer.php:273-283 (resolveClass()), used for the saved/public-preview render, and the inspector's own Alignment control (resources/views/components/live/block/style/alignment.blade.php) writes the exact same `supports.align` path. BUT the CSS class `.hb-align-left/center/right` is never defined anywhere in the codebase. SupportsStyle.php's own docblock (lines 42-45) and docs/block-schema.md (lines 116, 203) and TODO.md (line 507-509, which incorrectly claims it is 'already working') all assert these rules live in `resources/css/builder.css` — that file does not exist anywhere in the repo (confirmed via filesystem search; it was presumably deleted when 'the builder was removed' per src/Support/BlockViewData.php's own comment, 2026-08-02). None of the actually-shipped stylesheets (resources/css/editor/*.css, which EditorController::css() concatenates for the live editor; resources/blocks/heading/heading.css; resources/blocks/paragraph/paragraph.css; SupportsStyle::css(), used by both the editor and preview.blade.php) contain any `.hb-align-*` rule. A grep for `hb-align-(left|center|right)` across the whole repo (excluding vendor/) matches only doc comments and a unit test asserting the class STRING is present in the HTML — never a CSS selector. Net effect: the class is added to the DOM correctly (which is presumably why a prior author believed 'it already works'), but zero visual change occurs, in both the live editor canvas and the public preview/saved page.

**Recommendation:** Add `.hb-align-left { margin-left:0; margin-right:auto; }` / `.hb-align-center { margin-left:auto; margin-right:auto; }` / `.hb-align-right { margin-left:auto; margin-right:0; }` (or the equivalent text-align/flex rule appropriate to how these blocks are laid out) to a stylesheet that is actually served — either resources/css/editor/35-blocks.css (editor) plus wherever preview.blade.php's blocksCss is assembled, or centralize it in SupportsStyle::alignBreakoutRules() alongside the existing (and working) hb-align-wide/hb-align-full rules, since that method already runs on every request that needs it.

### [high/docs-mismatch] docs/file-structure.md describes an obsolete pre-deletion Builder/Editor coexistence model that no longer exists

**Location:** `docs/file-structure.md:13`  |  dimension: docs-blueprint-structure

**Evidence:** The doc's entire premise (lines 13-30, 38, 48, 83, 90, 94-98) is that '/builder' remains live and frozen while '/editor' is built independently, and that Livewire under src/Livewire/Editor/{Topbar,Sidebar,Inspector,Canvas} is 'Phase 3 only; requires approval first' (line 94). TODO.md's 2026-08-02 entry states plainly: 'the builder is gone... /builder and everything under it (resources/js/builder, resources/views/builder, resources/css/builder, BuilderController, routes/web.php, tests/Builder) — deleted.' Confirmed on disk: resources/css/builder, resources/js/builder, resources/views/builder all report 'No such file or directory'; no *Builder* file exists under src/; routes/web.php does not exist (routes live in routes/editor.php and routes/media.php instead). Livewire (livewire/livewire ^4.3) is already a hard composer.json dependency and src/Livewire/MediaLibrary.php + resources/views/livewire/media-library.blade.php already exist and are routed at /editor/media, despite the doc gating any Livewire on unlanded 'Phase 3' approval.

**Recommendation:** Rewrite or retire file-structure.md to describe the current single-surface /editor architecture (no Builder, routes/editor.php + routes/media.php, Livewire already present for the media library) instead of the superseded migration plan.

### [high/docs-mismatch] Doc says Style-panel State tabs are inert; they are now fully wired end-to-end (state-scoped read/write + canvas preview)

**Location:** `docs/inspector-composition.md:202`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 1 marks State 'inert — see §6', and §6/§9 known-defect #2 say 'the State tabs at the top of the Style panel carry no data-hb-control and no listener' / 'State tabs inert — the renderer's state system has no editor front-end'. But resources/views/components/live/block/style-panel.blade.php:118 mounts `<x-ui.tabs data-hb-style-state ...>`; inspector.blade.php:969-976 define hbActiveState()/hbStatePath() which retarget every supports-keyed control to `supports.states.<state>.<path>` on non-default tabs (used in syncControls ~line 1064 and handleControlEvent's setSupport call at line 1284); a `change` listener at inspector.blade.php:1517-1528 switches `root.dataset.hbStyleState` and calls `window.hbEditor.previewState?.(id, state)`. block-runtime.blade.php implements previewState() (lines 564-575) which merges `supports.states.<state>` into the live canvas style and toggles `.hb-state-preview-<state>`, exposed on the public API at line 898. Both shipped contracts now declare `supports.states: {hover,active,focus}`.

**Recommendation:** Update docs/inspector-composition.md §4.2/§6/§9 to reflect that State authoring is implemented, not preview-only via BlockRenderer.

### [high/docs-mismatch] Doc says Stroke and Appearance's corner-radius fields render (because both contracts fully declare border.radius); the contracts have since removed border support entirely, so these sections no longer render at all

**Location:** `docs/inspector-composition.md:221`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 rows 9-10 and §9 known-defect #1 assume `border`/`border.radius` is declared by both shipped contracts ('both fully declare border.radius', 'the section legitimately declares border, so it renders'). Neither resources/blocks/heading/heading.json nor paragraph.json contains a `border` key anywhere under `supports` (grep confirms zero matches for the string "border" in resources/blocks/**/*.json). resources/blocks/heading/heading.css:1 and paragraph.css:1 explicitly state 'Border/radius intentionally absent (2026-08-05, TODO 7.2): text blocks do not support …'. Consequently `$showStroke = $has('border')` is false in style-panel.blade.php:108/168, so the entire Stroke section is unmounted, and `$showCorners` (= $showStroke) in style/appearance.blade.php:12/18-33 is also false, so the four corner-radius fields never render either. Appearance now renders opacity-only.

**Recommendation:** Rewrite §4.2 rows 8-10 to reflect that Stroke and corner-radius are currently unreachable for both shipped blocks (a regression relative to the doc's snapshot caused by a contract change), and retire known-defect #1 (per-side border shadowing) since there is no `border.width` declared to shadow anymore.

### [high/docs-mismatch] Doc says Position section is gated off entirely for both shipped contracts and that no contract carries hb-supports; both are now false

**Location:** `docs/inspector-composition.md:210`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 4 and §5 assert 'Position … whole section gated off — no contract declares position' and 'no shipped contract carries [hb-supports]'. resources/blocks/heading/heading.json and paragraph.json both declare `supports.position: {x,y,rotation,mode}` and `style.className: "hb-block-heading hb-supports"` / "hb-block-paragraph hb-supports", plus `--hb-tx`/`--hb-ty`/`--hb-rotate`/`--hb-position-mode` style.variables. resources/views/components/live/block/style/position.blade.php:4-16 wires all four fields with data-hb-control. Position now renders, writes, and visibly applies via SupportsStyle's capability stylesheet for both blocks.

**Recommendation:** Rewrite §4.2 row 4 and §5's framing — Position is no longer an 'unclaimed' capability, it's live on both shipped contracts.

### [high/docs-mismatch] Doc says Effects section is gated off entirely; both contracts now declare effects.shadow and the shadow composer writes it

**Location:** `docs/inspector-composition.md:225`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 11: 'Effects … whole section gated off — no contract declares effects'. Both resources/blocks/heading/heading.json and paragraph.json declare `supports.effects.shadow: true` and a `--hb-shadow` variable sourced from `supports.effects.shadow`. resources/views/components/live/pickers/effect-editor.blade.php:18-21 documents 'Hooks added 2026-08-05 (TODO 7.1): these five fields compose ONE box-shadow string written to supports.effects.shadow'; inspector.blade.php defines and uses hbComposeShadow() (~lines 1629, 1649). style-panel.blade.php:172-174/186-190 renders the Effects section and its popup whenever `$has('effects')` is true, now true for both blocks.

**Recommendation:** Update §4.2 row 11 and §9's framing of Effects as 'unclaimed'.

### [high/bug] Position (x/y/rotation/mode), Effects/shadow, Appearance/opacity and block Alignment write and re-render correctly but paint nothing — the block stylesheets never consume the custom properties/class the runtime sets

**Location:** `resources/blocks/paragraph/paragraph.css:1`  |  dimension: inspector-dataflow

**Evidence:** block-runtime.blade.php's styleDeclarations() (161-191) correctly resolves `supports.position.x/y/rotation/mode`, `supports.effects.shadow`, `supports.appearance.opacity` (all declared with real `style.variables` entries in resources/blocks/paragraph/paragraph.json:413-442 and resources/blocks/heading/heading.json:450-479) and writes them inline as `--hb-tx`, `--hb-ty`, `--hb-rotate`, `--hb-position-mode`, `--hb-shadow`, `--hb-opacity` on the block's root element (renderNode, block-runtime.blade.php:251-252). Alignment is handled by bespoke code at block-runtime.blade.php:249-250, which adds a `hb-align-<value>` class. All the corresponding inspector controls are wired end-to-end: style/position.blade.php:4-16 (`data-hb-control="position.x/y/rotation/mode"`), style/effects.blade.php + inspector.blade.php:1629-1653 (`hbComposeShadow` -> `setSupport(id, ..., 'effects.shadow', css)`), style/appearance.blade.php:16 (`appearance.opacity`), style/alignment.blade.php:30-31 (`data-hb-control="align"`). But grepping the actual stylesheets that style these two shipped blocks (resources/blocks/paragraph/paragraph.css, resources/blocks/heading/heading.css) and the whole resources/ tree shows no rule anywhere reads `--hb-tx`, `--hb-ty`, `--hb-rotate`, `--hb-position-mode`, `--hb-shadow`, `--hb-opacity`, or defines `.hb-align-left/-center/-right`. The custom properties/class land on the DOM but map to no declared CSS property, so the canvas is visually identical before and after the edit.

**Recommendation:** Add the missing consuming rules to each block's own stylesheet (e.g. `transform: translate(var(--hb-tx,0), var(--hb-ty,0)) rotate(var(--hb-rotate,0))`, `position: var(--hb-position-mode, static)`, `opacity: var(--hb-opacity, 1)`, `box-shadow: var(--hb-shadow, none)`, and `.hb-align-left { margin-inline: 0 auto }` etc.) so the variables/class the runtime already computes actually render.

### [high/bug] Typography's Text-horizontal, Text-vertical and Letter-spacing controls have the same dead-CSS problem as Position/Effects

**Location:** `resources/blocks/heading/heading.css:1`  |  dimension: inspector-dataflow

**Evidence:** style/typography.blade.php:83-109 wires `typography.textAlign`/`typography.textAlignVertical`/`typography.letterSpacing` through the same delegated `setSupport` path, and both contracts declare matching `style.variables` (`--hb-text-align`, `--hb-text-align-v`, `--hb-letter-spacing` — paragraph.json:398-412, heading.json:435-449), so `styleDeclarations()` computes and inlines them correctly. Neither paragraph.css nor heading.css contains a `text-align: var(--hb-text-align)`, `align-self: var(--hb-text-align-v)`, or `letter-spacing: var(--hb-letter-spacing)` rule (grep across resources/ confirms these three custom-property names appear only in the JSON contracts and the picker/typography Blade files, never in a stylesheet), so these three Style controls can never visibly change the canvas.

**Recommendation:** Add the missing text-align / align-self / letter-spacing declarations (reading the corresponding custom properties) to both block stylesheets.

### [high/bug] Editor canvas never renders inner-blocks — container blocks preview empty while BlockRenderer renders their children

**Location:** `resources/views/components/live/block-runtime.blade.php:239`  |  dimension: php-services

**Evidence:** renderNode() explicitly does `// inner-blocks (columns) not supported in this pass — render nothing.` / `if (type === 'inner-blocks') return document.createDocumentFragment();` (lines 239-240). By contrast, `src/Services/BlockRenderer.php` renderNode() (lines 135-137) handles the same `inner-blocks` template node type by calling `renderInnerBlocks()` (lines 190-200), which recurses through every child in `block.innerBlocks`, rendering each through its own contract. Any block contract that uses an `inner-blocks` template node (e.g. a future `columns`/container block — `BlockType::COLUMNS` already exists in `src/Enums/BlockType.php`) would show up completely empty in the editor's live canvas while the published/rendered output shows the real nested content. Not yet triggered by the two shipped contracts (heading, paragraph — neither declares innerBlocks), but it is a genuine, load-bearing gap between the two engines that will silently break the first container block shipped.

**Recommendation:** Port BlockRenderer::renderInnerBlocks()'s recursion into block-runtime.blade.php's renderNode(), or explicitly gate innerBlocks-enabled contracts out of the editor until the client-side walk supports the node type, with a visible placeholder rather than a silent empty render.

### [high/bug] JS cssValueValid() is missing 7 of the Phase-1 sanitizer kinds; falls through to a permissive generic regex for values BlockRenderer validates strictly

**Location:** `resources/views/components/live/block-runtime.blade.php:138`  |  dimension: php-services

**Evidence:** PHP's `BlockRenderer::cssValueValid()` (src/Services/BlockRenderer.php:649-683) has an explicit `match` arm — with the comment 'never fall back to the permissive default case for any of these' — for `color-token`, `color-token-or-transparent`, `opacity`, `angle`, `shadow`, `align-3`, `position-mode`, `flex-direction`, `flex-justify`, `flex-align`, `overflow`. The JS port (block-runtime.blade.php lines 138-154) only implements `border-style`, `font-token`, `size-value`, `color-value`, `font-family`, `font-weight`, `size-token`, `integer`, `length-signed`, `text-align` — every other sanitizer, including `opacity`, `angle`, `shadow`, `align-3`, `position-mode`, falls through to the generic `/^[a-z0-9\s().,%_\/-]+$/i` (line 153), which accepts values the strict server-side grammars reject (e.g. `position-mode` accepts any lowercase token instead of only static|relative|absolute; `angle` accepts a bare number with no `deg` suffix; `shadow` accepts any comma/paren string instead of requiring the structured inset/length×2-4/single-color layer grammar `isSafeShadowValue()` enforces). `resources/blocks/heading/heading.json` (lines 435-479) actually declares style.variables using `align-3` (`--hb-text-align-v`), `opacity` (`--hb-opacity`), `angle` (`--hb-rotate`), `shadow` (`--hb-shadow`), and `position-mode` (`--hb-position-mode`) — so this is a live divergence on a shipped block, not theoretical: a value the editor canvas happily previews can be silently rejected/defaulted by BlockRenderer at publish time, or conversely an out-of-spec value (e.g. rotation '45' without 'deg', or an invalid position mode) previews fine in the editor and then reverts to the CSS default on the published page.

**Recommendation:** Port the missing sanitizer branches (color-token, color-token-or-transparent, opacity, angle, shadow, align-3, position-mode, flex-direction, flex-justify, flex-align, overflow) into the JS cssValueValid() verbatim from BlockRenderer::cssValueValid(), removing the permissive default fallback for these kinds exactly as the PHP side's docblock mandates.

### [high/state] Save/version state and postId are duplicated across five independent closures, synced only by a broadcast event

**Location:** `resources/views/components/live/topbar.blade.php:120`  |  dimension: state-management

**Evidence:** topbar.blade.php:120-131 owns the only real save state machine (`hbPostId`, `hbContentVersion`, `hbDirty`, `hbConflicted`, `hbSaveInFlight`) in a private closure invisible outside the file — not on `window.hbEditor`. inspector.blade.php then independently re-derives its OWN copy of `postId` in four separate closures, each seeded from its own `data-hb-post-id` DOM attribute and each listening for `hb:post-id` on its own: wirePostTaxonomy (line 640, instantiated once for Categories and again for Tags), wirePostDiscussion (line 745), wirePostLayout (line 787). That is five separate in-memory copies of the same scalar (postId), kept consistent only because every one of them happens to also listen for the single `hb:post-id` broadcast (topbar.blade.php:245; listeners at inspector.blade.php:727, 761, 825) — there is no shared store a sixth consumer could just read.

**Recommendation:** Expose postId/contentVersion/dirty state on window.hbEditor (or a small shared store) as the one place it lives, and have every consumer read from it instead of keeping a private mirrored copy seeded from a DOM data attribute.

### [high/state] No canonical document model — post title lives only in the DOM, split across two hand-synced elements

**Location:** `resources/views/components/live/canvas.blade.php:27`  |  dimension: state-management

**Evidence:** block-runtime.blade.php:72 defines the ONLY document-model object in the app: `const doc = { blocks: [] };` — it has no `title` field. The post title instead lives purely as DOM state on two separate elements, both marked `data-hb-title`: the canvas h1 (canvas.blade.php:13) and the inspector Post-tab `<input>` (inspector.blade.php:287). canvas.blade.php:23-51 keeps them in sync by hand: on `input`, it walks `document.querySelectorAll('[data-hb-title]')` and copies the typed value into every OTHER title element (line 39), guarded by a `let syncing = false;` reentrancy flag (line 27, checked/set lines 37-43). Every consumer that needs 'the current title' re-derives it independently by re-querying the DOM: topbar.blade.php:142-146 `hbReadTitle()`, panel-navigator.blade.php:93-94 `titleText()`. There is no single place that owns 'what is the document title right now'.

**Recommendation:** Fold title into the same doc model block-runtime already owns (e.g. `doc.title`) with a `window.hbEditor.setTitle()`/`getTitle()` pair mirroring setAttribute, and drive both DOM inputs from `hb:doc-title` instead of letting each one write directly into the others' DOM nodes.

### [high/docs-mismatch] The entire media/ component subtree hardcodes English strings instead of using __(), including the real production call site

**Location:** `resources/views/components/live/media/media-dialog.blade.php`  |  dimension: ui-components-pickers

**Evidence:** media-dialog.blade.php:231 default title, 240-241 tab labels, 255 close aria-label, 274-276 upload error text are all literal strings. media-card.blade.php:9-10 default name/meta, 39 uploading text, 43-44 error/retry text follow the same pattern. media-library.blade.php:55/102/138 empty and error text, and 121/130 default props, are likewise hardcoded. upload-dropzone.blade.php:25-26 hardcodes the dropzone copy. Critically, the real production usage at resources/views/components/live/inspector.blade.php:329 passes title="Select Featured Image" as a raw string, not through __(), so this is not just unused demo scaffolding, it ships. Every sibling panel (panel-seo-social, panel-ai-tools, panel-navigator, footer) routes its strings through __().

**Recommendation:** Add lang keys for every hardcoded string in this subtree and thread them through __() the same way the rest of the editor UI does, starting with inspector.blade.php's title call site.

### [medium/comment-noise] block-runtime.blade.php — narrative JS comments mixing changelog, TODOs and design mirroring

**Location:** `resources/views/components/live/block-runtime.blade.php:155`  |  dimension: comment-noise

**Evidence:** 92 comment-marker lines/918 total (~10%), several spanning many lines. Examples: L95 '// pure helpers (ported 1:1 from the builder render engine)' (design/builder reference, category a); L155-158 'Which interaction state the canvas is forcing... This mirrors BlockRenderer::stateStylesCss()'s .hb-state-preview-<state> hook... (TODO 7.3)' (cross-file narration + stray TODO, category b/c); L396-400 walks through a TODO-7.8 justification for why a parent-walk 'is the correct answer now and stays correct once containers exist, instead of a hardcoded false' (changelog/why-narration, category c). Worth keeping in trimmed form: L316-319's caret save/restore rationale is a genuinely non-obvious behavioral constraint (re-render rebuilds DOM from scratch and drops focus/cursor), but it should be cut from 4 lines to 1.

**Recommendation:** Strip TODO-numbered narration and builder-mirroring commentary; keep only the caret-preservation constraint, shortened to a single line.

### [medium/comment-noise] inspector.blade.php — pervasive narrative/design-reference comments, worst offender in the sweep

**Location:** `resources/views/components/live/inspector.blade.php:1`  |  dimension: comment-noise

**Evidence:** 288 comment-marker lines counted ({{-- occurrences)/2213 total lines (~13%), but most are multi-line blocks — e.g. the file-header comment alone spans lines 1-10+. Verbatim examples to delete: L1 '{{-- live/inspector — from Pencil Block (BfaTx): the right sidebar, 260px, border-left. Structure: ...' (design-file reference, category a); L365 '{{-- Categories/Tags (Phase 3.1, 2026-08-04; reworked 2026-08-03 into ONE shared checklist —' (changelog narration, category c); L577 '{{-- Two-layer scroll shell — see live/panel-components-blocks.blade.php's own note on' (cross-file narration, category b). No comments in this file rise to a genuinely critical, concise constraint worth keeping as-is — the closest candidates (L849, L917 referencing block-runtime.blade.php's sync behavior) are themselves cross-file narration and would need trimming to a one-line pointer rather than kept verbatim.

**Recommendation:** Delete all Pencil/.pen provenance and phase/date changelog comments; where a cross-file coupling is genuinely load-bearing (e.g. L849's dependency on block-runtime.blade.php's updateInspector()), replace with a single terse line, not the current paragraph.

### [medium/comment-noise] block-toolbar.blade.php — dense narrative JS comments justifying implementation choices against the legacy builder

**Location:** `resources/views/components/live/toolbar/block-toolbar.blade.php:90`  |  dimension: comment-noise

**Evidence:** 53 comment-marker lines/347 total (~15%, second-highest ratio among blade files). L90-92 '// Nearest ancestor of the caret matching a tag, bounded by the editable — mirrors the house builder's inlineAncestor (resources/js/builder/02-toolbar.js) so Code's toggle/state logic matches the one other formatting toolbar in this codebase.' (cross-file/design mirroring, category a/b); L105-110 cites 'resources/js/builder/02-toolbar.js, whose own comment documents exactly this' — a comment about another file's comment, category b. Worth keeping, trimmed: L181-185's explanation that mousedown must be blocked to prevent browsers from collapsing the text selection before the click handler runs is a genuinely non-obvious, critical DOM-behavior constraint.

**Recommendation:** Delete all references to resources/js/builder/02-toolbar.js and 'mirrors the builder' framing; keep the mousedown/selection-preservation constraint in 1-2 lines.

### [medium/comment-noise] topbar.blade.php — long JS comment blocks narrating design decisions and rejected alternatives

**Location:** `resources/views/components/live/topbar.blade.php:181`  |  dimension: comment-noise

**Evidence:** 60 comment-marker lines/585 total (~10%). Examples: L181-184 'Autosave never CREATES a post on its own... Rationale (see the task report for the full version): a debounce timer firing before any deliberate save would silently spawn a new draft row...' (explicit changelog/rationale narration referencing an external task report, category c); L347-349 'Layers — open the Navigator ... mirroring the builder's Layers toolbar button' (design-file reference, category a); L478-479 'Preview lives here now, not on a separate eye icon in the centre group: open in a new tab IS what preview does, so one button carries it instead of two competing affordances' (why-I-did-this narration). Worth keeping in trimmed form: L371-375's explanation that window.open() must be called synchronously before any await to avoid popup-blocking is a genuinely critical, non-obvious technical constraint.

**Recommendation:** Delete rationale/history comments including the 'see the task report' reference; keep only the popup-blocking constraint, shortened to 1-2 lines.

### [medium/comment-noise] PostController.php — mixes a critical concurrency-lock comment with speculative future-work narration

**Location:** `src/Http/Controllers/PostController.php:131`  |  dimension: comment-noise

**Evidence:** 36 comment lines/308 total (~12%). L131-136 is a 6-line block flagged '--- Revision seam ---' describing work for 'a second agent' that is 'explicitly out of scope for this change', including a speculative code snippet ('e.g.: Revision::snapshot($post, $post->blocks()->get());') — this is planning/scratch narration that does not belong in shipped code (category c). Worth keeping, trimmed: L109-111 'Locked for the lifetime of the transaction — the actual optimistic-concurrency gate: nothing else can read-then-write this row between the version check and our own write below.' documents a genuinely critical invariant for the save path's correctness.

**Recommendation:** Delete the entire 'Revision seam' planning block (L131-136); keep the optimistic-concurrency-lock comment in 1-2 lines.

### [medium/comment-noise] BlockRenderer.php — mix of genuinely critical security comments and changelog narration

**Location:** `src/Services/BlockRenderer.php:149`  |  dimension: comment-noise

**Evidence:** 49 comment lines/852 total (~6%). L149-153 'Reverse-tabnabbing guard (§4.10): any anchor whose resolved target opens a new browsing context gets a forced-safe rel, regardless of what the contract template did or didn't declare. Enforced here (not per-contract) so it can never be forgotten...' is a genuinely critical security rationale (category e) and should be KEPT, condensed. By contrast L278-280 'Full-kit overhaul 2026-07-19 (Phase 1) — wide/full were advertised by ALIGN_VALUES but never actually emitted; this list now matches the validator's allow-list exactly.' is pure changelog narration (category c) with a dated phase reference and should go.

**Recommendation:** Keep the reverse-tabnabbing security rationale in short form; delete the dated 'Full-kit overhaul' changelog note and other '§N' spec citations that just restate the code.

### [medium/comment-noise] custom-scrollbar.blade.php — long multi-paragraph JS comments explaining historical bugs and fixes

**Location:** `resources/views/components/ui/custom-scrollbar.blade.php:103`  |  dimension: comment-noise

**Evidence:** 38 comment-marker lines/343 total (~11%). L103-111 spans 9 lines narrating a past bug: 'Resolution order matters once a page has MORE THAN ONE instance of the same component... which returns the FIRST match anywhere on the page. Every combobox's scrollbar then drove the first combobox's list, so the others appeared unscrollable. Checking the bar's own parent subtree first keeps each instance on its own container...' — this is changelog/bug-history narration (category c). L88-95 similarly narrates why a ResizeObserver 'in practice doesn't always fire in time' with a cross-reference to sidebar.blade.php's showPanel() and tablist-script.blade.php's activate() (category b).

**Recommendation:** Delete the historical bug narration; if the multi-instance resolution-order behavior must be documented, state the current rule in 1-2 lines without the 'used to be broken because' framing.

### [medium/comment-noise] SavePostRequest.php — highest comment ratio among validation/request classes, mostly why-narration

**Location:** `src/Http/Requests/SavePostRequest.php:50`  |  dimension: comment-noise

**Evidence:** 40 comment lines/158 total (~25%, highest ratio of any PHP file swept). L50-56 'title_en is NOT NULL with no default at the DB level (see create_heisenberg_posts_table), but an untitled draft is legitimate ... It was required on create, which meant the very first Save from a fresh editor (empty title) 422'd; and because the client only starts autosaving once a post id exists, that one failure also meant autosave never activated at all.' is changelog/bug-history narration (category c) describing a past bug rather than current behavior. Worth keeping, trimmed: L85-88's explanation that the `blocks` field must be `present` not `required` (an empty array is a legitimate emptied post) is a genuinely non-obvious validation-rule constraint.

**Recommendation:** Delete the bug-history narration at L50-56 (keep only 'untitled drafts are legitimate' as one line); keep the present-vs-required rationale for `blocks` in 2 lines.

### [medium/comment-noise] EditorController.php — highest comment density in src/, entirely cross-file/changelog narration

**Location:** `src/Http/Controllers/EditorController.php:21`  |  dimension: comment-noise

**Evidence:** 68 comment lines/325 total (~21%, the densest PHP file swept). Verbatim: L21-24 'Falls back to a uniform 56px on all four sides when a post has no saved override — matches resources/css/editor/34-canvas.css's own --hb-page-padding-x/-y CSS fallback, which must stay in sync with this (see that file's docblock on the two collapsing the previous asymmetric 44/56/160 hardcoded padding into one configurable X/Y pair).' (cross-file + changelog, category b/c); L103-106 references what 'preview.blade.php ever emitted' before this change and cites '(TODO 7.6)'; L108-110 restates a class name and method with no new information. No comment in this file rises to a critical constraint worth keeping verbatim — the CSS-fallback coupling at L21-24 is the closest, but it should be trimmed to a single pointer line, not kept as written.

**Recommendation:** Delete all TODO-numbered and 'matches X's own Y' narration; if the CSS-fallback coupling must be flagged, reduce to one line naming the file and property, no history.

### [medium/comment-noise] MediaLibraryService.php — long security-rationale comments; some genuinely critical, most narrative

**Location:** `src/Services/MediaLibraryService.php:61`  |  dimension: comment-noise

**Evidence:** 66 comment lines/505 total (~13%). L61-68 explains why the extension allow-list must be enforced service-side rather than only in a FormRequest (SVG-with-inline-script RCE risk) — this is a genuinely critical constraint (category e) and should be KEPT, though condensed from 8 lines to 2-3. L366-370 'Windows-illegal filename characters ... Left alone, these either break outright on a Windows-hosted disk, or a Flysystem adapter throws a raw filesystem exception' is also a real constraint worth a short note. By contrast L120 '// Failure cleanup (blueprint §5 step 9): no orphaned bytes on disk.' and repeated '(blueprint §X)' citations throughout are cross-file/spec narration (category b) that should go.

**Recommendation:** Keep the SVG-mime and filename-sanitization rationale in 1-2 line form; delete all '(blueprint §N step M)' citations and restate-the-next-line comments.

### [medium/docs-mismatch] docs/file-structure.md's directory tree and CSS/JS layout no longer match the real tree

**Location:** `docs/file-structure.md:39`  |  dimension: docs-blueprint-structure

**Evidence:** Doc lists resources/css/editor/{00-base.css,10-components.css,20-shell.css,30-canvas.css,40-inspector.css} and resources/js/editor/{00-bootstrap.js,10-selection.js,20-canvas.js,30-inspector.js,40-history.js}. Actual resources/css/editor/ contains 00-fonts.css, 01-reset.css, 02-dark-theme.css, 20-shell.css, 30-media.css, 31-block-inspector.css, 32-pickers.css, 33-toolbar.css, 34-canvas.css, 35-blocks.css — different names/numbers, and resources/js/editor/ does not exist at all; TODO.md ground rule 4 states /editor is 'Vanilla JS... no bundler. The page loads none of them [Alpine/Livewire]' with JS instead delivered inline via Blade (e.g. block-toolbar.blade.php's own <script> block). EditorController methods that assemble/serve these assets (css(), animationsCss(), supportsCss(), font(), logo(), servedUpload()) are also entirely undocumented.

**Recommendation:** Replace the directory tree and asset-assembly description with the actual resources/css/editor/*.css list and the real (Blade-embedded, no resources/js/editor) JS delivery mechanism, or explicitly mark the doc historical/superseded.

### [medium/docs-mismatch] block-schema.md's style section omits the classNames conditional-class feature, which is validated and used by the real heading contract

**Location:** `docs/block-schema.md:320`  |  dimension: docs-blueprint-structure

**Evidence:** The `style` section (lines 320-334) documents only `css`, `className`, and `variables`. BlockContractValidator::validateStyle() (src/Services/BlockContractValidator.php:337-364) validates a fourth top-level `style.classNames` array of `{class, when}` predicate bindings (reusing the same showWhen/disableWhen/forceWhen predicate grammar). resources/blocks/heading/heading.json actually declares 11 such classNames entries (lines 255-333, e.g. hb-hide-xs/hb-size-fill-w gated on boolean attributes) — a real, validated, exercised schema feature with zero mention in block-schema.md.

**Recommendation:** Document style.classNames (shape, predicate grammar, and its relationship to showWhen/disableWhen/forceWhen) in the `style` section.

### [medium/docs-mismatch] block-schema.md's supports groups list omits `states`, which the validator specially recognizes and heading.json declares

**Location:** `docs/block-schema.md:100`  |  dimension: docs-blueprint-structure

**Evidence:** Line 100-102 lists the recognized support groups as 'color, typography, spacing, border, dimensions, layout, size, animation, appearance, position, effects, plus align (special-cased)' — `states` is absent. BlockContractValidator::validateSupports() (src/Services/BlockContractValidator.php:222-233) special-cases `supports.states` exactly like `align`, restricting its keys to INTERACTION_STATES = ['hover','active','focus'] (referenced as '(TODO 7.3)'). resources/blocks/heading/heading.json declares `"states": {"hover": true, "active": true, "focus": true}` (lines 237-241) as a real, validated, in-use contract field.

**Recommendation:** Add `supports.states` to the documented groups list alongside `align`, describing its restriction to hover/active/focus and its BlockRenderer::stateStylesCss() consumer.

### [medium/docs-mismatch] docs/ROADMAP.md's 'Done' section is stale: it still claims 9 shipped block contracts and a completed Builder, both since removed

**Location:** `docs/ROADMAP.md:39`  |  dimension: docs-blueprint-structure

**Evidence:** ROADMAP.md line 39-40 lists as Done: 'M1 — contract core ... the 9 shipped contracts + EN lang labels', and lines 43-51 list 'Builder (shell + chrome)' as a completed milestone with sidebars/toolbar/inspector detail. TODO.md's 2026-08-02 entry ('the builder is gone, block contracts pruned to heading + paragraph') states the Builder and 11 of the 13 non-heading/paragraph block contracts were deleted; resources/blocks/ contains only heading/ and paragraph/ subdirectories, and no BuilderController/routes/web.php exist anywhere in the repo.

**Recommendation:** Update ROADMAP.md's Done section to reflect the 2026-08-02 reset: 2 shipped contracts (heading, paragraph), and either remove the Builder milestone or annotate it as since-deleted/superseded by the Editor.

### [medium/docs-mismatch] Doc says Fill/Hug/Clip checkboxes are inert; they now write attributes with matching contract classNames predicates

**Location:** `docs/inspector-composition.md:217`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 7: 'Fill / Hug / Clip checkboxes | — | ❌ inert'. resources/views/components/live/block/style/dimensions.blade.php:16-24 wires all five checkboxes (`fillWidth`, `fillHeight`, `hugWidth`, `hugHeight`, `clipContent`) with `data-hb-control`, kind=attributes. Both resources/blocks/heading/heading.json and paragraph.json declare these as attributes and add matching `style.classNames` predicates (`hb-size-fill-w`, `hb-size-fill-h`, `hb-size-hug-w`, `hb-size-hug-h`, `hb-size-clip`), implemented by SupportsStyle's capability stylesheet, now reachable since both contracts carry `.hb-supports`.

**Recommendation:** Update §4.2 row 7 to wired+renders.

### [medium/docs-mismatch] Doc says Typography's horizontal/vertical text-align segmenteds are decorative; they now write typography.textAlign / textAlignVertical and both contracts declare + render them

**Location:** `docs/inspector-composition.md:209`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 3 lists 'Horizontal / vertical align | — | ❌ inert' and never mentions `typography.textAlign`/`typography.textAlignVertical` as supports keys. resources/views/components/live/block/style/typography.blade.php:83-109 wires both segmenteds with `data-hb-control="typography.textAlign"`/`"typography.textAlignVertical"`, kind=supports. resources/blocks/heading/heading.json and resources/blocks/paragraph/paragraph.json both declare `supports.typography.textAlign`/`textAlignVertical: true` and matching `--hb-text-align`/`--hb-text-align-v` style.variables, so both controls render AND take visual effect via SupportsStyle.

**Recommendation:** Add these two supports keys/controls to the doc's Typography row and correct the wired/inert status.

### [medium/docs-mismatch] Doc says Alignment segmented control is inert (no data-hb-control); it now writes supports.align

**Location:** `docs/inspector-composition.md:203`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 2: 'Alignment | 6-way self-align segmented | — | ❌ inert (no data-hb-control)'. resources/views/components/live/block/style/alignment.blade.php:29-31 renders `<x-ui.segmented ... data-hb-control="align" data-hb-control-kind="supports" data-hb-control-type="segmented" />`, with its own header comment noting 'Wired 2026-08-05 (TODO 7.1)'. It also now only offers left/center/right (not 6-way), derived from `$supports['align']`.

**Recommendation:** Update the Alignment row and note the 6-way→3-way redesign (vertical options removed since no container/innerBlocks exists yet).

### [medium/hardcoded] Danger/error color hardcoded instead of --hb-danger token

**Location:** `resources/views/components/live/panel-style-themes.blade.php`  |  dimension: hardcoded-theme

**Evidence:** Line 61: `.hb-token-saveform__error { font-family: var(--hb-font-sans, Rubik, sans-serif); font-size: var(--hb-fs-xs, 11px); color: #C0392B; }` — every other color in this component set is `var(--hb-*, fallback)`; this line uses a bare literal (#C0392B) that isn't even the same red as the design system's own danger token (--hb-danger: #D4191A, tokens.css:21), and never picks up the dark-theme remap (--hb-danger: #FF6B6B, 02-dark-theme.css:26).

**Recommendation:** Replace with `color: var(--hb-danger, #D4191A);`.

### [medium/docs-mismatch] inspector.blade.php's own "KNOWN GAP" comment misdiagnoses the dead-canvas controls, which will misdirect the next fix attempt

**Location:** `resources/views/components/live/inspector.blade.php:26`  |  dimension: inspector-dataflow

**Evidence:** inspector.blade.php:26-31 states Position/Appearance-opacity/Effects/per-side-Border writes go to "a `supports.*` path with no matching `contract.style.variable`". That is false for Position/Effects/Appearance-opacity: resources/blocks/paragraph/paragraph.json:413-442 and resources/blocks/heading/heading.json:450-479 DO declare matching `--hb-tx`/`--hb-ty`/`--hb-rotate`/`--hb-position-mode`/`--hb-shadow`/`--hb-opacity` variables sourced from exactly those supports paths, and block-runtime.blade.php's styleDeclarations() does read them back (contradicting the comment's second claim, "only `contract.style.variables`... are read back into the live canvas preview" — they ARE read back, just never consumed downstream). The real defect (see the two findings above) is one layer further down: the block's own .css file never turns the inlined custom property into an actual CSS declaration.

**Recommendation:** Correct the comment to point at the missing CSS consumer rules in resources/blocks/*/*.css rather than "no matching contract.style.variable", so a future engineer doesn't waste time re-declaring variables that already exist.

### [medium/dead-code] Advanced tab's "Animate on scroll" section is entirely decorative — writes orphan attributes nothing ever reads

**Location:** `resources/views/components/live/block/advanced.blade.php:18`  |  dimension: inspector-dataflow

**Evidence:** advanced.blade.php:18-37 renders Animation type / Duration / Delay controls with `data-hb-control="animate"/"animateDuration"/"animateDelay"` and `data-hb-control-kind="attributes"`, which do reach `window.hbEditor.setAttribute`. But neither resources/blocks/paragraph/paragraph.json nor resources/blocks/heading/heading.json declares an `animate`, `animateDuration`, or `animateDelay` attribute, so `newBlockModel()` (block-runtime.blade.php:84-93) never seeds them and no `render.template` in either contract references `attributes.animate*`. A repo-wide grep for these three identifiers turns up only advanced.blade.php itself and the two lang files — no CSS, no JS animation runtime, nothing consumes them. `setAttribute` just adds a stray key to the in-memory model that is invisible everywhere else. The "Play animation" button (advanced.blade.php:36) additionally has no `data-hb-control` and no click handler anywhere in the codebase.

**Recommendation:** Either wire a real scroll-animation attribute set into the contracts + a runtime that reads it, or remove the section until that exists, so it stops presenting as a working control.

### [medium/security] GET /editor/{post} and GET /editor/{post}/preview render any post's full content with no authorization check (IDOR)

**Location:** `src/Http/Controllers/EditorController.php:63`  |  dimension: php-http-security

**Evidence:** EditorController::show() (routed at routes/editor.php line 28, GET /editor/{post}) resolves the post directly via Post::query()->with(['blocks','categories','tags'])->findOrFail($post) and ships title/content/categories/tags into the editor shell view with no PostPolicy check at all -- the method's own docblock states this explicitly ('Deliberately does not run a PostPolicy authorization check'). PreviewController::showPost() (src/Http/Controllers/PreviewController.php lines 90-102, GET /editor/{post}/preview) does the same for the rendered HTML preview. Both routes sit under config('heisenberg.middleware.editor'), which defaults to just ['web'] (config/heisenberg.php lines 282-286) -- unauthenticated by default. Contrast this with PostController::show() (the JSON API, GET /editor/posts/{post}), which correctly calls Gate::forUser($actor)->authorize('view', $model) (src/Http/Controllers/PostController.php lines 52-60) before returning the same data. Because {post} is a plain auto-increment PK constrained only by whereNumber (routes/editor.php lines 28 and 87), any anonymous visitor can enumerate post IDs and load draft/pending_review/scheduled posts into the editor UI or the preview tab.

**Recommendation:** Run the same Gate::forUser($actor)->authorize('view', $model) check PostController::show() already performs before rendering the post into EditorController::show() and PreviewController::showPost(), or explicitly require that any production deployment widen config('heisenberg.middleware.editor') to include real auth before these two read paths are reachable.

### [medium/bug] BlockContractValidator never validates the shape of most `supports` groups — only checks the group name is known

**Location:** `src/Services/BlockContractValidator.php:202`  |  dimension: php-services

**Evidence:** validateSupports() (lines 195-239) special-cases `align` and `states` with real structural checks, but for every other group in SUPPORT_KEYS (`color`, `typography`, `spacing`, `border`, `dimensions`, `layout`, `size`, `animation`, `appearance`, `position`, `effects`) the entire body is just `if (! in_array($group, self::SUPPORT_KEYS, true)) { $errors[] = "unknown support group '{$group}'"; }` — the group's *value* (`$value`) is never inspected. A contract with e.g. `"supports": {"color": "yes"}` or `{"size": {"width": "please"}}` passes contract validation cleanly. BlockRegistryService's derive*Rows()/derive*Controls() methods defensively `is_array()`-guard against this (so it doesn't crash), but it means malformed supports declarations silently produce an empty/wrong panel instead of being caught at author time the way every other contract section is.

**Recommendation:** Add per-group structural validation (each leaf either true/false/absent, or for side-maps an object of top/right/bottom/left booleans, etc.) mirroring the shape BlockRegistryService's derive*() methods actually consume, so an authoring mistake fails contract validation instead of silently degrading in the inspector.

### [medium/state] BlockRegistryService / PostTemplateRegistryService: singleton scan cache never invalidates after a contract file changes on disk

**Location:** `src/Services/BlockRegistryService.php:131`  |  dimension: php-services

**Evidence:** `private ?array $scanCache = null;` is populated once by `scan()` (lines 129-190) and served for the object's whole lifetime — there is no filemtime check, no cache-clear method, and no invalidation hook anywhere in the codebase (confirmed by grepping every reference to BlockRegistryService/scanCache). `HeisenbergServiceProvider::registerEngine()` (src/HeisenbergServiceProvider.php:137-141) binds it as `$this->app->singleton(...)`. On classic PHP-FPM/apache-per-request bootstrapping this is harmless (a fresh container per request re-scans), but under any persistent-worker runtime (Octane, Swoole, a queue worker that resolves the container once) a block-contract JSON edit on disk will not be picked up until the worker process restarts — the exact 'stale registry after contract change' failure mode. PostTemplateRegistryService (src/Services/PostTemplateRegistryService.php:31, 129-190) has the identical pattern.

**Recommendation:** Either document the PHP-FPM-only assumption explicitly, or add a filemtime-based cache key / explicit forget() method the same way Laravel's own config/view caches invalidate, so a long-lived worker process reflects a contract edit without a restart.

### [medium/state] Five custom events are dispatched with zero listeners anywhere in the codebase — one of them silently breaks the image-picker affordance

**Location:** `resources/views/components/live/block-runtime.blade.php:829`  |  dimension: state-management

**Evidence:** `hb:pick-image` is dispatched at block-runtime.blade.php:829 when a user clicks an empty image block's placeholder; the file's own docblock (lines 25-26) says '(cancelable, fired by the image block's empty-state placeholder — no media dialog lives here, another component owns that)' — but grepping the whole live/ui tree for `addEventListener('hb:pick-image'` returns nothing: no component owns it, so the click silently does nothing. The same pattern repeats for `hb:featured-image-change` (dispatched inspector.blade.php:538, no listener anywhere — arguably intentional per its own comment about no persistence layer, but still a broadcast into the void), and for three toolbar events dispatched in toolbar/block-toolbar.blade.php: `hb:toolbar-popover` (lines 210, 255, 293), `hb:toolbar-action` (line 282), and `hb:format` (line 199) — all three are explicitly kept 'for any other listener already watching' (block-toolbar.blade.php:15-16) but none exists.

**Recommendation:** Either wire the media dialog to hb:pick-image (the feature this event exists for currently does nothing), or remove the dead dispatches/comments claiming a consumer exists so the gap isn't mistaken for working integration.

### [medium/state] block-runtime's own boot() uses a module-scope 'wired' flag instead of the element-flag idempotency pattern every other file uses, risking a permanent listener loss if the canvas wrap is ever replaced

**Location:** `resources/views/components/live/block-runtime.blade.php:807`  |  dimension: state-management

**Evidence:** `let wired = false;` (line 807) and `function boot() { const wrap = wrapEl(); if (!wrap || wired) return; wired = true; ... }` (lines 808-811) gate the mousedown-select / rich-text-input / image-picker-click listeners onto whichever `.hb-page__blocks` element exists the FIRST time boot() runs. Every other component in this tree (inspector.blade.php:187-188 `root.__hbInspector`, canvas.blade.php:35 `el.__hbTitle`, panel-navigator.blade.php:271-272 `root.__hbNav`, topbar.blade.php, etc.) instead stamps a flag directly ON the DOM node, so a replaced node naturally re-wires on the next hb:refresh. Because `wired` here is a plain closure variable, if `.hb-page__blocks` is ever swapped for a new element (e.g. a future 'switch document' flow that rebuilds canvas markup), `document.addEventListener('hb:refresh', boot)` (line 881) becomes a silent permanent no-op: the new wrap never gets its mousedown/input/click listeners.

**Recommendation:** Match the rest of the codebase's convention: check/set the wired flag on the wrap element itself (e.g. `if (wrap.__hbWired) return; wrap.__hbWired = true;`) instead of a module-scope boolean.

### [medium/state] Theme/token state uses a third, different ownership model (DOM-only, no in-memory object) than blocks or title

**Location:** `resources/views/components/live/panel-style-themes.blade.php:95`  |  dimension: state-management

**Evidence:** collectTheme() (line 95-120) re-reads every `[data-hb-token-row]` from the live DOM on demand to build the theme object it PUTs to the server; applyThemeVars() (line 150-166) does the exact same DOM re-scan again just to repaint the `--hb-t-*` custom properties. There is no JS object anywhere that represents 'the current theme' between calls — the DOM rows ARE the state. This is a third state-ownership strategy in the same app: blocks are JS-object-first with the DOM as a rendered view (block-runtime.blade.php doc), title is DOM-first with manual two-element sync (canvas.blade.php), and theme tokens are DOM-only with no model at all. None of the three share a pattern or a helper.

**Recommendation:** Pick one strategy (model-first with DOM as a view is what the block runtime already does well) and apply it consistently, or at minimum extract the DOM-scan into a shared helper so the three concerns don't each reinvent state ownership.

### [medium/state] window.__hbEditorDoc hands out the live, mutable block-model array with zero encapsulation, bypassing every write path

**Location:** `resources/views/components/live/block-runtime.blade.php:885`  |  dimension: state-management

**Evidence:** `window.__hbEditorDoc = doc;` (line 885) and `window.hbEditorInsertBlock = insertBlock;` (line 884) expose the runtime's private, authoritative `doc` object and its insert function directly on `window`, described in the file's own docblock (lines 18-22) as 'legacy globals... kept working unchanged'. Anything holding `window.__hbEditorDoc` could `doc.blocks.push(...)` / `.splice(...)` straight into the model, completely bypassing insertBlock/setAttribute/setSupport/removeBlock — the only paths that also call reRenderBlock() and fire hb:blocks-changed/hb:block-updated. A mutation via this door would leave the canvas showing stale DOM with no way for it to know it needs to react. Confirmed dead today (grep across live/ui finds no reader besides the assignment itself), but the surface remains live and load-bearing by name.

**Recommendation:** Remove the two legacy globals now that nothing reads them, or if back-compat is still required, expose a frozen/cloned snapshot instead of the live mutable array.

### [medium/dead-code] ui/var-menu-item is orphaned in production; the live variable-menu picker duplicates its markup/CSS under a different class scheme

**Location:** `resources/views/components/ui/var-menu-item.blade.php`  |  dimension: ui-components-pickers

**Evidence:** grep across resources/views shows <x-ui.var-menu-item> is used only in the component gallery (resources/views/editor/components.blade.php:222-223). The one real consumer, resources/views/components/live/pickers/variable-menu.blade.php:18-39, hand-rolls the identical row pattern (check icon + name + swatch-or-value trailing) with its own class names (.hb-vmi, .hb-vmi__l, .hb-vmi__check, .hb-vmi__name, .hb-vmi__sw, .hb-vmi__val) styled separately in resources/css/editor/32-pickers.css, instead of rendering <x-ui.var-menu-item>. The two implementations must be kept in visual sync by hand.

**Recommendation:** Either make variable-menu.blade.php render <x-ui.var-menu-item> per row, or delete ui/var-menu-item.blade.php and its gallery demo since it is not the real component in use.

### [medium/bug] Search fields render on four panels but have zero filtering logic wired, unlike the identical media-library search field

**Location:** `resources/views/components/live/panel-components-blocks.blade.php`  |  dimension: ui-components-pickers

**Evidence:** panel-components-blocks.blade.php:82 and 100 render the search field for the Components and Blocks tabs; panel-ai-tools.blade.php:169 and panel-style-themes.blade.php:701 do the same for their Tools/Themes tabs. None of these files contain any input-event listener or filter logic. By contrast, media-library.blade.php:34 and 105-109 wires the structurally identical input to a debounced fetch and filter. A user typing into any of these four search boxes sees no effect at all.

**Recommendation:** Either wire client-side filtering or a server round-trip for these four search fields, or hide the field until the feature is implemented so it does not present as broken.

### [medium/docs-mismatch] effect-editor's field labels and default title bypass the lang files entirely

**Location:** `resources/views/components/live/pickers/effect-editor.blade.php`  |  dimension: ui-components-pickers

**Evidence:** Lines 3, 24, 32, 36 hardcode 'Drop Shadow', 'Color', 'Blur', 'Offset' as literal English strings rather than __() lookups, unlike its sibling color-picker.blade.php which routes every visible string through __('heisenberg::editor.color_picker.*'). Both real call sites (resources/views/editor/components.blade.php:296 and resources/views/components/live/block/style-panel.blade.php:188) render the component with no title override, so the hardcoded English is what every non-English locale sees.

**Recommendation:** Add lang keys for title/Color/Blur/Offset and route them through __() the same way color-picker.blade.php does.

### [medium/docs-mismatch] Topbar's save/autosave error messages are hardcoded English and surface via the footer's otherwise fully-localized status pill

**Location:** `resources/views/components/live/topbar.blade.php`  |  dimension: ui-components-pickers

**Evidence:** Lines 255, 262, and 276 are literal English strings passed as detail.message on the hb:save-state CustomEvent. footer.blade.php:280-307 renders this message directly into the visible status pill alongside STATE_LABELS, which footer.blade.php builds via @json(__(...)) for every other state. Switching locale via the footer's own language switcher does not translate the conflict/error explanation text.

**Recommendation:** Move these literal strings into lang files and emit them via a server-rendered JSON strings block, the same pattern panel-navigator.blade.php already uses for its own JS-side strings.

### [medium/dead-code] gradient-stop-row.blade.php is never included anywhere in the app, not even the gallery; color-picker.blade.php reimplements the same row from an inline template

**Location:** `resources/views/components/live/pickers/gradient-stop-row.blade.php`  |  dimension: ui-components-pickers

**Evidence:** grep for gradient-stop-row across resources/views finds only this file's own docblock and color-picker.blade.php's data-attribute selectors ([data-cp-gradient-stop-row] etc). No include or x-live.pickers.gradient-stop-row call exists anywhere, including the gallery page. The live picker instead clones markup from its own template at color-picker.blade.php:101-115, duplicating the same hb-gsr__sel/hb-gsr__hexbox/hb-gsr__op structure this file defines, sharing only the CSS file per this file's own admission that both must be restyled together.

**Recommendation:** Remove the unused gradient-stop-row.blade.php, or wire it into the gallery page, and keep the template markup and this file's markup in lockstep if both are meant to stay.

### [low/comment-noise] BlockContractValidator.php — dated 'overhaul' changelog comments embedded in constant definitions

**Location:** `src/Services/BlockContractValidator.php:37`  |  dimension: comment-noise

**Evidence:** 45 comment lines/580 total (~8%). L37-47 is a long block: 'Overhaul 2026-07-18 — the .pen control kit writes validated raw values as well as tokens... Full-kit overhaul 2026-07-19 (Phase 1) — new raw-value + enum kinds for the supports capabilities SupportsStyle serves. Lockstep with BlockRenderer::cssValueValid(); never fall back to the permissive default case for any of these. See BlockRenderer for the regexes.' — mixes a genuine cross-file coupling warning ('lockstep with BlockRenderer::cssValueValid()') with dated changelog narration and a design-file reference ('.pen control kit'). L56 'Full-kit overhaul 2026-07-19 (Phase 1).' on its own line adds no information beyond the constant it labels.

**Recommendation:** Keep only the lockstep-with-BlockRenderer coupling warning, shortened to one line; delete the dated overhaul/phase narration and .pen references.

### [low/comment-noise] color-picker.blade.php — mostly legitimate short comments, but a few overlong justification blocks

**Location:** `resources/views/components/live/pickers/color-picker.blade.php:256`  |  dimension: comment-noise

**Evidence:** 46 comment lines/829 total (~5.5%, lowest ratio among the flagged blade files — most comments here are short and functional, e.g. L153-154 'Accepts 3, 4, 6 and 8 digit hex... callers decide the fallback rather than getting a silent black' which is concise and worth keeping). The worst offenders are L256-258 'Seeded, not null: a picker mounted straight into gradient mode never runs the fill→gradient park, so without this its first switch to Fill would inherit a stop colour instead of the value the component was given.' and L531-534, both multi-line why-narration that restate edge-case reasoning better suited to a one-liner.

**Recommendation:** Keep the short functional comments as-is; trim the two multi-line edge-case justifications (L256-258, L531-534) to single lines.

### [low/comment-noise] HeisenbergServiceProvider.php — dated overhaul comments and a genuinely load-bearing middleware-ordering note

**Location:** `src/HeisenbergServiceProvider.php:150`  |  dimension: comment-noise

**Evidence:** 37 comment lines/348 total (~11%). L150 '// Overhaul 2026-07-18 — user theme storage + public font catalog.' is a bare dated changelog note with no other content (category c) and should be deleted outright. L169-171 'Drift detection for the two on-disk contract systems. Both are console-only, and an Artisan command is unreachable by name until it is registered here — without this, php artisan templates:verify / blocks:verify simply don't exist.' restates what registration does (category d). Worth keeping, trimmed: L191-195 explains that this provider deliberately pushes middleware onto the global 'web' group rather than a package-scoped group so host apps see consistent locale — a real architectural decision with a non-obvious consequence.

**Recommendation:** Delete L150's bare changelog line and the L169-171 restate-comment; keep the middleware-scope rationale at L191-195, shortened to 2 lines.

### [low/comment-noise] panel-style-themes.blade.php — Pencil design-file references and layered scroll-shell narration

**Location:** `resources/views/components/live/panel-style-themes.blade.php:1`  |  dimension: comment-noise

**Evidence:** 58 comment-marker lines/748 total (~8%). L1 '{{-- live/panel-style-themes — from Pencil Styles (Q3h5D) + Themes (Qcba6). Middle panel, 240px (same...' (design-file reference, category a); L561 '{{-- Two-layer scroll shell (same reasoning as the Themes tab's own [data-hb-themes-scroll]...' (cross-file narration, category b); L725 '{{-- Deliberately AFTER the unconditional Presets loop above, not before it: content...' (restates ordering already visible from the code, category d, though the 'deliberately' framing suggests it guards against a future reordering bug — could be kept as one line if that risk is real, otherwise delete).

**Recommendation:** Delete the Pencil-source header and cross-file scroll-shell comment; keep the ordering note only if reordering would silently break something, reduced to one line.

### [low/comment-noise] BlockRegistryService.php — design-file references embedded in otherwise clean docblock-style file

**Location:** `src/Services/BlockRegistryService.php:464`  |  dimension: comment-noise

**Evidence:** 58 comment lines/1366 total (~4%, lowest density of the flagged files, but the largest file in src/). L464-468 '// Panel order mirrors the design's Block.style component (LtsDN): Alignment → Position → Flex Layout → ... (Color is ours — the design tucks color into Border only; the engine keeps text/background color as a first-class panel.)' is a direct design-file reference (category a) explaining a deliberate divergence from the .pen source. Most other comments in this file are legitimate short docblocks (e.g. L111 'Path-traversal guard: realpath-confine a candidate file to the block root.') which are concise and fine to keep as-is.

**Recommendation:** Delete the Pencil/design-component ('LtsDN') reference at L464-468; the actual panel order can stand alone as a plain list without the design-file citation. Leave the short functional docblocks untouched.

### [low/docs-mismatch] block-schema.md's control-type list is missing 5 of the validator's 16 recognized types and undercounts them as '11'

**Location:** `docs/block-schema.md:255`  |  dimension: docs-blueprint-structure

**Evidence:** Doc lines 234-235 and 255-256 list control types as 'text, textarea, rich-text, select, toggle, range, number, media, link, button-group, repeater' (11) and the override example says 'force a widget (any of the 11 control types)'. BlockContractValidator::CONTROL_TYPES (src/Services/BlockContractValidator.php:71-75) actually allows 16: the same 11 plus 'checkbox', 'chips', 'unit', 'color', 'font'. resources/blocks/heading/heading.json uses the undocumented 'chips' control type for its extraClasses attribute (line 95: control.type = 'chips').

**Recommendation:** Update the control-types list and count to match CONTROL_TYPES exactly, including checkbox/chips/unit/color/font.

### [low/docs-mismatch] docs/BLUEPRINT.md's DI singleton graph undercounts the contracts actually bound in HeisenbergServiceProvider

**Location:** `docs/BLUEPRINT.md:327`  |  dimension: docs-blueprint-structure

**Evidence:** §1.4 [TARGET] (line 327-332, and line 274's prose 'binds the five decoupling contracts') shows only MediaResolver, RoleGate, AuditSink, IconProvider (four, despite the '5' in the prose — HeisenbergUser is the uncounted fifth, never actually bound as a container singleton). The real HeisenbergServiceProvider::registerContracts() (src/HeisenbergServiceProvider.php:263-311) binds those same four plus four more added later: PostViewsProvider, PostCommentProvider, RelatedPostsProvider, PostSeoMetaProvider (lines 301-310), and registerMedia() (lines 112-123) separately binds VirusScanner + MediaLibraryService — none of which appear in the blueprint's bind-order listing at all.

**Recommendation:** Extend §1.4's TARGET code block to include the four post-template adapter contracts and the media (VirusScanner/MediaLibraryService) singletons, or note that §1.4 is intentionally scoped to only the original M0 contracts and point readers to the provider source for the current full list.

### [low/docs-mismatch] Doc says typography.letterSpacing is declared by neither contract; both now declare it with a working variable

**Location:** `docs/inspector-composition.md:208`  |  dimension: docs-inspector-toolbar

**Evidence:** Doc §4.2 row 3: 'Letter spacing | typography.letterSpacing | 🚫 gated off — declared by neither contract'. Both resources/blocks/heading/heading.json and paragraph.json now declare `supports.typography.letterSpacing: true` and a `--hb-letter-spacing` style.variable sourced from it; style/typography.blade.php:74-76 wires the field with `data-hb-control="typography.letterSpacing"`.

**Recommendation:** Move Letter spacing from 'gated off' to 'wired + renders' in §4.2.

### [low/hardcoded] Off-scale font-size literals (15px, 10px) with no corresponding --hb-fs token, beside otherwise-tokenized sibling rules

**Location:** `resources/views/components/live/inspector.blade.php`  |  dimension: hardcoded-theme

**Evidence:** Line 68: `.hb-inspector__name { ...font-size: 15px;... }` and line 129: `.hb-post-title__eyebrow { ...font-size: 10px;... }`. tokens.css's type scale is xs(11)/sm(12)/base(13)/md(14)/lg(16)/xl(20) — neither value exists, while sibling rules in the same blocks (lines 61, 74, 113-114, 136-137, 158-159) correctly use var(--hb-fs-*).

**Recommendation:** Snap 15px→var(--hb-fs-md,14px) or var(--hb-fs-lg,16px); 10px→var(--hb-fs-xs,11px); or add new scale steps if the .pen source is load-bearing on these exact sizes.

### [low/hardcoded] No box-shadow tokens exist; literal rgba(0,0,0,X) shadows repeat across popovers/menus/cards, including one exact duplicate

**Location:** `resources/css/editor/32-pickers.css`  |  dimension: hardcoded-theme

**Evidence:** tokens.css defines color/radius/spacing/type tokens but zero shadow tokens. Literal shadows recur: 32-pickers.css:8 `0 8px 28px rgba(0, 0, 0, .14)` (popover shell), :29 `0 1px 2px rgba(0, 0, 0, .33)`, :38 `0 1px 3px rgba(0, 0, 0, .25)`, :79 `0 1px 3px rgba(0, 0, 0, .33)`; 35-blocks.css:127 `0 6px 18px rgba(0, 0, 0, .18)`; topbar.blade.php:87 `0 8px 28px rgba(0, 0, 0, .14)` — an EXACT duplicate of 32-pickers.css:8's value; panel-style-themes.blade.php:67 `0 1px 3px rgba(0,0,0,.2)`.

**Recommendation:** Add --hb-shadow-sm/md/lg tokens; at minimum consolidate the exact duplicate between 32-pickers.css:8 and topbar.blade.php:87.

### [low/hardcoded] Unlabeled 6px corner radius used systematically with no matching design token

**Location:** `resources/css/editor/33-toolbar.css`  |  dimension: hardcoded-theme

**Evidence:** Literal `border-radius: 6px` (not wrapped in var()) at 33-toolbar.css:12 (.hb-tb__btn), :20 (.hb-tb__pill), :23 (.hb-tb__ai), :27 (.hb-tb__align), :44 (.hb-typemenu__item), and 32-pickers.css:31 (.hb-cp__slider), :75 (.hb-cp__gbar), :160 (.hb-vmi). tokens.css's radius scale is 2/3/5/8px (xs/sm/md/lg) with no 6px step.

**Recommendation:** Fold into --hb-radius-md (5px) if visually acceptable, or add a new --hb-radius token since 6px repeats deliberately across 7+ rules rather than being an accident.

### [low/hardcoded] Empty-state chrome text hardcodes colors that duplicate custom properties already defined in the same file

**Location:** `resources/views/preview.blade.php`  |  dimension: hardcoded-theme

**Evidence:** Lines 40/47 define `--ink: #0a0a0a` and `--muted:/--faint: #9a9a9a` in :root, but the "Nothing to preview yet" empty state re-hardcodes the same values as raw literals: line 85 `style="...color:#9a9a9a;font-size:14px;..."`, line 86 `style="...color:#0a0a0a;..."`. The sticky preview bar (lines 57, 64) similarly hardcodes `#0a0a0a`/`#fff` instead of the locally-declared custom properties.

**Recommendation:** Use var(--ink) and var(--muted)/var(--faint) — already declared in this file's own :root block a few dozen lines above — instead of re-typing the hex literals.

### [low/hardcoded] Radius literal 4px used for swatches/buttons with no exact token, inconsistent with tokenized 3px siblings

**Location:** `resources/css/editor/31-block-inspector.css`  |  dimension: hardcoded-theme

**Evidence:** Line 18: `.hb-itrail { ...border-radius: 4px; }`; also 32-pickers.css:167 `.hb-vmi__sw { ...border-radius: 4px; }` and 32-pickers.css:79 `.hb-cp__gstop { ...border-radius: 4px; }`. tokens.css has --hb-radius-sm (3px) and --hb-radius-md (5px) but no 4px step, while adjacent rules in the same files (31-block-inspector.css:130, :95) use var(--hb-radius-sm, 3px).

**Recommendation:** Snap to var(--hb-radius-sm, 3px) for consistency, or add a documented 4px token if it's a deliberate mid-scale .pen value.

### [low/docs-mismatch] Wrong fallback value for --hb-radius-md (6px) that doesn't match the actual token (5px)

**Location:** `resources/views/components/live/topbar.blade.php`  |  dimension: hardcoded-theme

**Evidence:** Line 87: `border-radius: var(--hb-radius-md, 6px);` — tokens.css:31 defines `--hb-radius-md: 5px`. Every other var(--hb-radius-md, ...) fallback in the codebase (32-pickers.css:7, :23, :54, :63, :69, :92, :95, :97; 35-blocks.css:126; inspector.blade.php:490, :601) correctly uses 5px. This is the only occurrence with a stale/incorrect 6px fallback.

**Recommendation:** Change to `var(--hb-radius-md, 5px)` to agree with tokens.css and every other usage.

### [low/hardcoded] Fractional font-size with no matching token (12.5px)

**Location:** `resources/css/editor/32-pickers.css`  |  dimension: hardcoded-theme

**Evidence:** Line 143: `.hb-shadow__lbl { width: 46px; flex: none; font-size: 12.5px; font-weight: 500; color: var(--hb-text-secondary, #5A5A5A); }` — 12.5px sits between --hb-fs-sm (12px) and --hb-fs-base (13px), matching no token.

**Recommendation:** Snap to var(--hb-fs-sm, 12px) or var(--hb-fs-base, 13px), or comment why it can't round to the scale if intentionally 1:1 with the .pen source.

### [low/hardcoded] Hardcoded font-size:12px used instead of the existing --hb-fs-sm token, repeated across inspector/toolbar CSS

**Location:** `resources/css/editor/31-block-inspector.css`  |  dimension: hardcoded-theme

**Evidence:** font-size: 12px hardcoded where --hb-fs-sm (tokens.css:48) already equals 12px and is used via var() by sibling rules in the same files: 31-block-inspector.css:64 (.hb-fxlayer__name), :73 (.hb-iradio__label), :80 (.hb-tglrow__l), :96 (.hb-colorlayer__hex); also topbar.blade.php:69 and :94.

**Recommendation:** Replace literal 12px with var(--hb-fs-sm, 12px), matching 31-block-inspector.css:17/:83/:89 which already do this correctly.

### [low/dead-code] Stroke/per-side Border-radius and Flex Layout sections are wired but permanently unreachable — no shipped contract satisfies their gate

**Location:** `resources/views/components/live/block/style-panel.blade.php:108`  |  dimension: inspector-dataflow

**Evidence:** style-panel.blade.php:108 `$showStroke = $has('border')` and :148 `$isContainer && $has('layout')` gate the Stroke (style/stroke.blade.php) and Flex Layout (style/flex-layout.blade.php) sections, and :111 `$showAppearance = $showStroke || $has('appearance')` gates the corner-radius half of Appearance (style/appearance.blade.php:18-33, `border.radius.*`). Neither resources/blocks/paragraph/paragraph.json nor resources/blocks/heading/heading.json declares a `supports.border` group (confirmed — the key is entirely absent from both files' `supports` object), and both set `innerBlocks.enabled: false`. With only these two contracts registered, `$has('border')` and `$isContainer` are always false, so Stroke, per-side Border-radius corners, and Flex Layout never mount in the running app at all — their internal wiring cannot currently be exercised or verified to work, whether or not it would.

**Recommendation:** No action required until a contract declares `supports.border` or a real container block ships; worth a comment noting these sections are currently unreachable so a report of them "not updating the canvas" isn't mistaken for a new bug.

### [low/security] Dev-only upload server passes an unvalidated wildcard path straight to the storage disk

**Location:** `src/Http/Controllers/EditorController.php:228`  |  dimension: php-http-security

**Evidence:** Route::get('/uploads/{path}', ...)->where('path', '.*') (routes/editor.php line 95) feeds directly into servedUpload(string $path), which calls $disk->exists($path) and $disk->get($path) (src/Http/Controllers/EditorController.php lines 228-240) with no explicit traversal or allow-list check of its own -- it relies entirely on League Flysystem's internal path normalizer to reject '..' segments. There is no defense-in-depth check at the controller layer the way font() right below it has (a strict filename regex, EditorController.php line 292).

**Recommendation:** Even though this route is documented as dev-only (testbench serve), add an explicit normalization/allow-list check on $path (reject any segment equal to '..' or containing a NUL byte) before calling into the disk, mirroring the guard already used in font(), so the route doesn't depend solely on the storage driver's own protections.

### [low/bug] Locale switcher's client-supplied return field is never captured into session, so the intended return-to-page redirect never round-trips

**Location:** `src/Http/Controllers/LocaleController.php:35`  |  dimension: php-http-security

**Evidence:** LocaleController::switch() reads $request->session()->pull('heisenberg.locale_return', $request->headers->get('referer') ?? '/editor') -- it only ever pulls a session key, and nothing in src/ ever writes to session('heisenberg.locale_return'). resources/views/components/live/footer.blade.php lines 235-259 build a hidden POST form with an explicit hidden input named return, populated client-side with window.location.href, whose surrounding comment says the route 'reads session(heisenberg.locale_return)' -- but the controller never reads $request->input('return') at all, so that value is submitted and silently discarded; the redirect always falls back to the Referer header (or the hardcoded '/editor').

**Recommendation:** In LocaleController::switch(), read $request->input('return') and use it as the redirect target, e.g. redirect()->to($request->input('return', $request->headers->get('referer') ?? '/editor')).

### [low/bug] scan()'s relative-path computation uses unanchored str_replace, which can corrupt the relative path if the root string recurs later in the file path

**Location:** `src/Services/BlockRegistryService.php:185`  |  dimension: php-services

**Evidence:** `'rel' => ltrim(str_replace($realRoot, '', $real), DIRECTORY_SEPARATOR)` replaces every occurrence of $realRoot inside $real, not just the leading prefix. If the block root's own path text repeats further down the path (e.g. a nested directory happens to share a name segment with a path component of the root — plausible on a dev machine whose project path is itself nested under a similarly-named directory), the computed _relativePath silently drops an unrelated chunk instead of only the root prefix. The identical pattern exists in PostTemplateRegistryService::scan() (src/Services/PostTemplateRegistryService.php:185).

**Recommendation:** Compute the relative path with substr($real, strlen($realRoot)) (anchored to the known prefix) instead of a global str_replace.

### [low/practice] isSafeAssetPath() and stringify() are duplicated verbatim between BlockContractValidator and PostTemplateContractValidator

**Location:** `src/Services/PostTemplateContractValidator.php:427`  |  dimension: php-services

**Evidence:** PostTemplateContractValidator::isSafeAssetPath() (lines 427-443) and ::stringify() (lines 445-454) are byte-for-byte identical to BlockContractValidator::isSafeAssetPath() (src/Services/BlockContractValidator.php:552-568) and ::stringify() (lines 570-579) — the class docblock even says 'Identical rule to {@see BlockContractValidator::isSafeAssetPath()}'. Any future hardening of the traversal check (e.g. adding a Windows-drive-letter check) has to be remembered in two places or they silently drift.

**Recommendation:** Extract both methods into a shared trait or a small ContractValidationHelpers utility class both validators use.

### [low/hardcoded] BlockViewData::blocksCss() re-reads every block's CSS file from disk on every call with no caching layer

**Location:** `src/Support/BlockViewData.php:100`  |  dimension: php-services

**Evidence:** Unlike the contract scan itself (cached in BlockRegistryService's $scanCache for the life of the singleton), blocksCss() calls file_get_contents($cssPath) (line 108) for every enabled block, every time the method runs — there is no equivalent cache. On every editor page load this re-reads and re-concatenates every shipped block's CSS from disk, an avoidable disk I/O cost that scales with the number of blocks × requests.

**Recommendation:** Cache the concatenated CSS string alongside (or inside) the registry's scan cache, keyed by the registry hash, so repeated calls within a request/process don't re-touch the filesystem.

### [low/bug] Locale-suffix stripping in payload validation assumes a strict lowercase 2-letter locale code

**Location:** `src/Services/BlocksPayloadService.php:165`  |  dimension: php-services

**Evidence:** `$base = preg_replace('/_(?:[a-z]{2})$/', '', (string) $key) ?? (string) $key;` in validateAttributes() only strips a trailing `_xx` where xx is exactly two lowercase letters. A region-qualified locale (en_US, pt_BR) or a non-2-letter locale code would not match, so `content_en_US` would be looked up verbatim in $declared and (being unknown) silently skipped from validation rather than mapped back to its base attribute `content`. BlockRenderer::localizedAttribute() (src/Services/BlockRenderer.php:414-426) and resolveClass's predicate check (line 299) key locale-suffixed attributes as `key . '_' . $locale` with no case/length constraint, so if the app's configured locale ever uses a region suffix, the renderer and the payload validator would disagree on which keys are 'known' attributes.

**Recommendation:** Loosen the regex to match the app's actual locale format (e.g. /_[A-Za-z]{2,5}(-[A-Za-z0-9]+)?$/) or derive the accepted suffix set from config('app.available_locales') instead of hardcoding a 2-letter lowercase assumption.

### [low/state] The title-sync reentrancy guard protects against a race that structurally cannot occur, masking that title has no real owner

**Location:** `resources/views/components/live/canvas.blade.php:27`  |  dimension: state-management

**Evidence:** `let syncing = false;` plus the check/set at lines 37 and 43 exist to stop the `input` handler from re-firing when it programmatically writes the OTHER title element's value/textContent. But `setVal()` (line 25, `el.value = v` or `el.textContent = v`) never dispatches a native `input` event in any browser, so the second title element's own `input` listener can never actually be triggered by that write — the guard defends against a scenario that cannot happen. It is evidence the author reasoned about it as a live race between two copies of state rather than treating title as one value with two views.

**Recommendation:** Not urgent on its own, but worth removing once title is unified into the doc model (see the first finding) — a single source of truth makes this guard unnecessary rather than merely inert.

### [low/state] panel-navigator reads the block list from the DOM instead of window.hbEditor.getDoc(), a second read path for the same data used elsewhere in the same file

**Location:** `resources/views/components/live/panel-navigator.blade.php:95`  |  dimension: state-management

**Evidence:** `const blocks = () => { const wrap = document.querySelector('.hb-page__blocks'); ... return Array.prototype.filter.call(wrap.children, (c) => c.nodeType === 1 && c.hasAttribute('data-block')); };` (lines 95-99) is how buildList()/buildOutline() get 'the current blocks', scanning the rendered DOM rather than calling `window.hbEditor.getDoc().blocks`. The SAME file uses the runtime API for indices a few lines down: `window.hbEditor.indexOf(id)` (line 226-227, 253) and `window.hbEditor.getDoc().blocks.length` (line 256) for drag/keyboard reordering. Two different sources for 'the block list' inside one component, correct only as long as block-runtime keeps DOM order and model order in lockstep.

**Recommendation:** Use window.hbEditor.getDoc().blocks consistently for building the Navigator's rows/outline, the same source the reorder logic in the same file already trusts.

### [low/dead-code] Toolbar AI and More (⋯) triggers have no popover container — clicking dispatches an event but shows nothing

**Location:** `resources/views/components/live/toolbar/groups/action.blade.php:5`  |  dimension: toolbar-canvas-dataflow

**Evidence:** The AI button (`data-tb-popover="ai"`) and More button (`data-tb-popover="more"`) go through block-toolbar.blade.php's generic `[data-tb-popover]` click handler (lines 285-294): `const pop = tb.querySelector('[data-tb-pop="' + name + '"]'); const willOpen = pop ? pop.hidden : false;` — since block-toolbar.blade.php only renders `[data-tb-pop]` containers for `type`, `align`, and `color` (lines 42-50), `pop` is null for `ai`/`more`, so `willOpen` is always false and nothing ever opens. This is called out in the file's own header comment as intentional ('ai, more have no host content yet') so it is a known/documented gap, but from a pure toolbar-affordance audit it is dead UI: the buttons are always visible and clickable, and nothing observably happens.

**Recommendation:** Either hide/disable the AI and More triggers until their popovers are built, or ship minimal popover containers so the click has a visible effect.

### [low/bug] Color popover doesn't sync its checked swatch to the selected block's current color (align-menu and type-menu both do this; color-menu doesn't)

**Location:** `resources/views/components/live/toolbar/color-menu.blade.php:27`  |  dimension: toolbar-canvas-dataflow

**Evidence:** align-menu.blade.php (lines 40-50) and type-menu.blade.php (lines 62-82) both register a `document.addEventListener('hb:block-selected', ...)` handler that repopulates which option shows as `--on` (checked) based on the newly-selected block's current model value. color-menu.blade.php's boot() (lines 29-43) has no such listener at all — the swatch that shows as checked simply reflects whichever option was last clicked in the browser session (or none), not the actual `supports.color.text` of whichever block is currently selected. The mutation itself (colorselect -> setSupport(id,'color.text',value)) still works correctly and visibly recolors the text — this is a cosmetic desync in the popover's own checkmark state only.

**Recommendation:** Add an hb:block-selected listener to color-menu.blade.php mirroring align-menu.blade.php's pattern: read e.detail.model.supports.color.text and toggle hb-vmi--on on the matching swatch.

### [low/dead-code] Save-as-block (floppy) button has no backing capability

**Location:** `resources/views/components/live/toolbar/groups/style.blade.php:8`  |  dimension: toolbar-canvas-dataflow

**Evidence:** gateToolbar() in block-runtime.blade.php (lines 425, 414-426) does gate `[data-tb-action="save"]` on `!!(c.innerBlocks && c.innerBlocks.enabled)` — both shipped contracts (heading, paragraph) declare `innerBlocks.enabled: false`, so in practice this button is hidden for every block that currently exists, which is correct/consistent. The click handler for `[data-tb-action]` (block-toolbar.blade.php lines 258-283) only implements real behavior for move-up/move-down/select-parent; `save` falls through to just the generic `hb:toolbar-action` dispatch with no `window.hbEditor` capability anywhere (confirmed: no `save`/`reusable` method exists on the window.hbEditor object literal, lines 889-915 of block-runtime.blade.php).

**Recommendation:** No action needed today (correctly gated off for both shipped contracts) — flag for when a container contract lands, per the code's own TODO 7.8/7.9 references.

### [low/bug] ui/custom-scrollbar's smooth=false prop can never actually disable inertial scrolling

**Location:** `resources/views/components/ui/custom-scrollbar.blade.php`  |  dimension: ui-components-pickers

**Evidence:** The smooth prop is written to data-smooth and read back with Number(bar.dataset.smooth || 0.06) around line 126, which always produces a number, never the boolean false. The subsequent gate on line 130, smooth !== false, can therefore never be defeated by passing smooth=false. No current caller passes it, so this is latent, but the documented off switch does not work.

**Recommendation:** Read the raw dataset string before coercing, treating the literal string 'false' as disabling smoothing, or drop the false-disables-smoothing contract from the docs.

### [low/hardcoded] ui/radio's default name prop is the unnamespaced literal 'radio', so two unrelated groups without an explicit name would silently merge

**Location:** `resources/views/components/ui/radio.blade.php`  |  dimension: ui-components-pickers

**Evidence:** Line 32-33 defaults name to the fixed literal 'radio', used to build the native name attribute on line 39. Every current caller happens to pass an explicit name, so the collision has not manifested, but the component offers no per-instance uniqueness in its default, so the next caller that omits name would join whatever other radio group also fell back to the same default.

**Recommendation:** Default name to a generated unique id instead of the fixed literal 'radio' so omitting the prop is safe.

### [low/dead-code] live/side-panel.blade.php is completely dead, nothing in the app renders it

**Location:** `resources/views/components/live/side-panel.blade.php`  |  dimension: ui-components-pickers

**Evidence:** grep for side-panel across resources/views returns only this file's own docblock and a comment reference from panel-components-blocks.blade.php:2. No x-live.side-panel or include call exists anywhere. The four real panels it claims to generalize (Components/Blocks, SEO/Social, Style/Themes, Ai/Tools) are each implemented as separate, fully-built files instead.

**Recommendation:** Delete side-panel.blade.php, or if it is meant as a scaffold for a future panel, mark it clearly and keep a usage so it does not silently rot.


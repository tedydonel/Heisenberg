# Heisenberg — Product Roadmap

> A lightweight, Laravel-native, **contract-driven block/page builder** — the maintained,
> non-React alternative to the abandoned Laraberg. Blocks are JSON contracts + CSS (+ a
> little vanilla JS). One generic editor reads any contract and auto-generates its toolbar
> and inspector. The published page is always rendered (and sanitized) server-side in PHP.
>
> A clean-room reconstruction of GTC's `Modules/Blog`, deliberately **lighter and better**:
> standalone package, leaner contract, no host coupling. Companion docs:
> [`BLUEPRINT.md`](BLUEPRINT.md) (engine spec) and [`block-schema.md`](block-schema.md) (the
> canonical block-contract definition). Reference (study only): the real GTC source and
> Gutenberg's block model.

## Architecture in one breath

| Layer | Job |
|---|---|
| **JSON contract** | *Defines* a block — attributes (the single source of truth), supports, render template. Drives both render and the auto-generated edit UI. |
| **CSS** | How a block looks (CSS-variable-driven, so most inspector edits are instant). |
| **PHP** | The only authoritative renderer: contract + values → safe HTML (sanitized, escaped). |
| **HTML** | What a block *is* — an independent fragment. |
| **Vanilla JS** | Thin layer that makes the editor interactive (select, RichText, insert, live-tweak, autosave). No framework, no build step. |

## Locked architectural decisions

- **Standalone Composer package**, decoupled from any host via the 5 contracts (MediaResolver,
  RoleGate, AuditSink, IconProvider, HeisenbergUser) — vs GTC welded into `Modules/Blog`.
- **JSON-only.** Blocks serialize as a JSON array of block objects (`{name, attributes,
  supports, innerBlocks}`). No Gutenberg comment-delimiters; no HTML-source parsing; no legacy
  `{type, content}` path.
- **Attributes are the single source of truth.** Inspector controls and most `style.variables`
  are *derived* from attributes + supports, not duplicated (the contract-redesign goal).
- **One `render.template` per block, two renderers** — walked by the PHP renderer (publish) and
  the JS canvas (edit). GTC's model.
- **Client editor = a lean vanilla-JS document model** (GTC-faithful), no framework/build.
- **Gutenberg is a study reference only** (how it models attributes/supports/inspector/toolbar),
  reimplemented our leaner way.

## Done

- **M0** — package skeleton (provider, config, 5 decoupling contracts + adapters, Testbench).
- **M1 — contract core** — `BlockType` enum, `BlockContractValidator`, `BlockRegistryService`,
  the 9 shipped contracts + EN lang labels.
- **Engine ring-1** — `HtmlSanitizationService` (HTMLPurifier configs), `BlockRenderer` (one
  generic contract-template walk → safe HTML), `BlocksPayloadService` (validates instances vs
  the live registry).
- **Builder (shell + chrome)** — served as a real Blade page (`GET /builder` → controller →
  `editor.blade.php`, assets `builder.css`/`builder.js` via routes). Chrome interactivity
  (sidebars, device **icon dropdown**, tabs, list/outline), contract-driven inspector
  (rendering + interactive sections/toggles/sliders), editable title. **Boots empty** (no
  sample data).
- **Persistence foundation** — `Post` + `Block` models + migrations on MySQL (JSON `content`,
  ordered scope, `content_version` lock).
- **98 tests green.**

## In progress — engine hardening + contract redefinition

Lock down a clean, lean contract and a solid engine *before* building the client editor:

- ✅ Redefined the contract (canonical [`block-schema.md`](block-schema.md)): dropped the
  vestigial HTML-source fields (`source`/`selector`/`attribute`), **controls derived from
  attributes + supports**, normalized `security`, slimmed `style.variables`.
- ✅ **GTC fidelity audit** of `BlockRenderer` + `HtmlSanitizationService` — stricter rich-text /
  colour / size-token sanitizers ported.
- ✅ **Inner-block rendering** in `BlockRenderer` — the `inner-blocks` node + depth-capped
  recursion (each child via its own contract).
- ✅ **Supports → inspector panels** — `BlockRegistryService` derives the color/typography/
  spacing/border panels (token registry in `config('heisenberg.tokens')`).
- ⏳ Wire the **final HTMLPurifier pass** over rendered output (the public-render job's backstop).
- ⏳ **Per-block CSS** (the `*.css` the contracts reference, missing today).
- ⏳ End-to-end render tests for every shipped block.

## Next

> Phase 1 engine work above is now done. The current working plan lives in
> [`../TODO.md`](../TODO.md); the inspector/toolbar capability catalogues are in
> [`inspector-composition.md`](inspector-composition.md) and
> [`toolbar-composition.md`](toolbar-composition.md).

- **Contract-driven client editor** (GTC-faithful): document model → client `render.template`
  renderer (mirrors PHP) → selection + RichText → inspector writeback → insertion → JSON
  serialization → undo/redo + drag-reorder. Retires the throwaway `.t-*` canvas.
- **Persistence write path** — `BlockService::persistBlocks` (transactional full-replace; the
  `_allow_raw` gate lands with the `html_raw` block) + **autosave JSON API** (optimistic
  `content_version` → 409).
- **Public render pipeline** — render job → `BlockRenderer` → purify → cached `rendered_html`,
  + the public Blade view.
- **Broaden** — more blocks incl. nesting (columns/cover) and `html_raw` (with the gate).

## Sequencing decision

**Engine-and-contract first, then editor-first vertical slice.** Harden the engine and lock the
contract so the editor isn't built twice; then drive one block (paragraph) all the way —
contract → render → editable in the builder → save — before broadening to every block.

---
name: pencil-extract
description: Use when extracting any Pencil design into Heisenberg Blade components. Enforces repo placement, token-only scoped CSS, prop-driven Blade, responsive output, and browser verification.
version: 1.0.0
author: Hermes Agent
license: MIT
platforms: [windows]
metadata:
  hermes:
    tags: [pencil, blade, laravel, css, extraction]
    related_skills: [hermes-agent-skill-authoring, architecture-requirements-audit]
---

# Heisenberg Pencil Extraction

## Purpose

Extract the active Pencil design into Heisenberg Blade without treating exported HTML as production code. The source of truth is Pencil MCP node data; HTML exports are structural references only.

## Placement

Read `docs/file-structure.md` before editing.

- Low-level reusable primitives go in `resources/views/components/ui/`.
- Editor pages, fixtures, layouts, and page composition go in `resources/views/editor/`.
- Editor CSS goes in `resources/css/editor/`.
- Editor JavaScript goes in `resources/js/editor/`.
- Never create an improvised extraction folder.
- `ui` primitives are leaves: no dependencies on Editor composition, backend services, or future `live` components.

## Pencil reading protocol

1. Call `mcp__pencil__get_editor_state({include_schema:true})` first.
2. Confirm the active file is the requested Heisenberg `.pen` file.
3. Locate frames with shallow `batch_get` searches.
4. Read exact reusable nodes and at least one instance at `readDepth: 2–3`.
5. Use `get_screenshot` for visual truth.
6. Never read `.pen` files through filesystem tools. `filePath` is only a hint and cannot switch the active document.

If the active document is wrong, stop and ask the user to open the correct file. Never reconstruct a design from memory.

## Token discipline

Heisenberg has no CSS build step. There is no Tailwind, no PostCSS, nothing to compile. Styling is plain, hand-written CSS.

Do not hardcode design colors, spacing, radii, font sizes, or borders in extracted output. Every design value must reference the matching custom property from `resources/css/tokens.css` via `var(--hb-x, fallback)`, where the fallback literal matches that token's current value in `tokens.css` (resilience if the stylesheet fails to load, not an excuse to skip the variable). For example: `border-radius: var(--hb-radius-md, 5px);`, `color: var(--hb-text-primary, #0A0A0A);`.

Never emit a bare literal (`border-radius: 5px;`, `color: #0A0A0A;`) for a value that has a matching token. If Pencil has a value without a matching token in `tokens.css`, stop and report it; do not invent a token or silently substitute a raw value.

Before reporting completion, search modified files for hex codes, `px` values, and other literals that appear outside a `var(--hb-...)` fallback position — anything found there is a token that was skipped.

## Blade contract

Every extracted component must:

- Start with a source-frame comment containing the Pencil frame name and ID.
- Declare `@props` with defaults matching the default design variant.
- Expose design copy as props or slots, never fixed production content.
- Use lookup maps for variants and guard invalid prop values.
- Merge caller attributes on the root element.
- Keep HTML, scoped CSS, and narrowly scoped JavaScript in the Blade component when the component is self-contained: a single `@once <style>...</style> @endonce` block, guarded so it emits once regardless of how many instances render on a page, using classes namespaced to the component (e.g. `hb-btn`, `hb-btn--primary`, following the pattern in `resources/views/components/ui/button.blade.php`).
- Reuse existing UI primitives rather than duplicating them.

## Responsiveness

Pencil artboards are fixed desktop canvases; do not emit artboard dimensions. Start mobile-first, use fluid widths, stack content by default, and add desktop composition with plain `@media (min-width: …)` breakpoints. Use `min-height: 100dvh`, `width: 100%`, `flex: 1 1 auto`, and CSS grid/flexbox for responsive layout — no utility-class framework. Verify horizontal overflow at the narrowest real browser viewport available.

## Extraction sequence

1. Establish the exact Pencil source and inspect tokens.
2. Inventory reusable atoms and identify duplicate variants.
3. Extract one vertical slice into `components/ui/`.
4. Build a preview route/page using fixture props.
5. Render in the browser and inspect screenshots; do not claim visual correctness from templates alone.
6. Run PHP syntax checks, relevant tests, and asset verification.
7. If the app uses Octane, reload after view/config changes. There is no asset build step — CSS/JS are served directly from disk (`EditorController` concatenates on request) — so a changed file is live on next request; just confirm the browser actually fetched it (asset responses are cached 300s).

## Verification checklist

- [ ] Active Pencil file confirmed.
- [ ] Exact source node and at least one instance inspected.
- [ ] Files follow `docs/file-structure.md`.
- [ ] Every component has source comment, props, guarded variants, and merged attributes.
- [ ] No hardcoded literal values outside a `var(--hb-...)` fallback position.
- [ ] Content is prop/slot-driven.
- [ ] Mobile-first responsive behavior is present.
- [ ] Preview route renders in a real browser.
- [ ] Screenshot was read directly.
- [ ] Existing Builder files remain untouched.
- [ ] PHP/tests/assets were verified.

## Common pitfalls

1. Treating `export_html` as production markup. Translate its structure into tokenized Blade.
2. Reading the wrong active `.pen` file because `filePath` does not switch documents.
3. Copying a component instance as the component definition. Inspect the reusable frame and an instance override separately.
4. Hardcoding values because a token mapping is inconvenient. Report the unmapped value instead.
5. Calling a desktop screenshot responsive. Verify a real narrow render and distinguish crop from viewport resize.
6. Putting a reusable primitive under Editor page composition. Keep UI primitives independent.
7. Declaring completion before screenshot verification.

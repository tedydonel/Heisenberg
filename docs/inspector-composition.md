# The Inspector — full composition and contract wiring

What the right-hand inspector can render, which block-contract key turns each control on, and
which path a control's value takes to reach the canvas. Written to be authored against: if you
are building a new block contract and want it to be genuinely editable, this is the catalogue of
affordances available to you.

Companion: [`toolbar-composition.md`](toolbar-composition.md). Schema reference:
[`block-schema.md`](block-schema.md).

> **Verified against the tree at 2026-08-04** (commit `3f5ceed`). Every "wired / not wired" claim
> below was checked against the actual `data-hb-control` attributes in
> `resources/views/components/live/block/**`, the `style.variables` in the two shipped contracts,
> and `BlockRenderer`'s read-back. Where the docblocks in the code disagree with the code, the
> code wins and the disagreement is flagged.

---

## 1. The model in one paragraph

A block instance is `{ id, name, attributes: {…}, supports: {…}, innerBlocks: [] }`. The inspector
never invents controls — it renders from the block's **contract**, and every control it renders
writes into one of those two bags:

- **`attributes`** — the block's own content and settings (`content`, `level`, `anchor`). Declared
  under the contract's `attributes` key. Written via `hbEditor.setAttribute(id, key, value)`.
- **`supports`** — the shared style system (`color.text`, `spacing.padding.top`). Declared under
  the contract's `supports` key. Written via `hbEditor.setSupport(id, dottedPath, value)`.

**A write only changes the canvas if something reads it back.** There are exactly three read-back
mechanisms, and this is the single most important thing to understand:

| # | Mechanism | Reads | Emits |
|---|---|---|---|
| 1 | `contract.style.variables` | `supports.*` / `attributes.*` | a CSS custom property in the block root's inline `style` |
| 2 | `contract.style.classNames` | `attributes.*` (predicate) | a class on the block root |
| 3 | `supports.align` (special-cased) | `supports.align` | `hb-align-<value>` on the block root |

That is the whole list. A control that writes to `supports.appearance.opacity` on a contract with
no `--hb-opacity` variable stores the value in the model, saves it to the database, reloads it
correctly — **and changes nothing visually, forever.** This is the cause of nearly every "the
inspector doesn't work" symptom.

---

## 2. Panel structure

```
aside.hb-editor__inspector
├── Tabs: [ Post | Block ]
│
├── POST TAB ─────────────────── document-level; not contract-driven
│   ├── Summary            (author/date/status meta rows — decorative, TODO 6.8)
│   ├── Featured image     → media dialog
│   ├── Categories         → checklist, POST/DELETE /editor/posts/{id}/categories/{cat}
│   ├── Tags               → checklist, POST/DELETE /editor/posts/{id}/tags/{tag}
│   ├── Discussion         → "Allow comments" toggle, PUT …/discussion
│   └── Page layout        → X/Y padding sliders, PUT …/layout
│
└── BLOCK TAB ────────────────── contract-driven; empty until a block is selected
    └── Sub-tabs: [ Content | Style | Advanced ]
```

**How the Block tab is built.** One instance of each panel is pre-rendered *per registered block
type* at page load (`@foreach ($registry …)`), all hidden. Selecting a block unhides the panel
matching `model.name` and syncs live values into it. Panels are **synced, never rebuilt** — so a
control keeps its DOM identity, and `ui/select`/`ui/combobox` instances aren't re-booted on every
selection.

**The write path.** One delegated `input`/`change` listener on the inspector root reads three
attributes off the changed element:

```html
data-hb-control="color.text"        <!-- the key or dotted path -->
data-hb-control-kind="supports"     <!-- "supports" | "attributes" -->
data-hb-control-type="text"         <!-- text | number | select | toggle | range -->
```

`kind` picks `setSupport` vs `setAttribute`. **A control with no `data-hb-control` is inert** — it
moves visually and writes nothing. Several shipped controls are in exactly that state; they are
marked below.

---

## 3. Content sub-tab

Renders from `contract.attributes[*].control`. An attribute appears here only if it declares a
`control` block with `section: "settings"`.

```json
"level": {
    "type": "integer",
    "default": 2,
    "enum": [1, 2, 3, 4, 5, 6],
    "control": {
        "type": "select",
        "section": "settings",
        "options": [{ "value": 1, "label": "H1" }, …]
    }
}
```

| Control type | Renders as | Notes |
|---|---|---|
| `text` | `ui/input` | |
| `textarea` | `ui/text-area` | what `content` uses |
| `select` | `ui/select` | needs `control.options` |
| `toggle` | `ui/toggle` | |
| `checkbox` | `ui/checkbox` | |
| `range` | `ui/slider` | |
| `number`, `media`, `link`, `button-group`, `repeater`, `chips`, `unit`, `color`, `font` | — | **valid in the contract, not yet rendered by `live/block/content`** — it renders `select`/`textarea`/`input` and falls through to `input` for everything else |

Values are coerced against the contract before writing: an `integer` attribute with an `enum`
(like `level`) is matched to the numeric enum rather than written as the string `"2"`.

### The General section

Below the settings section, `live/block/content` renders a **General** section with Id, Title and
Class, wired to the three attributes every contract declares under `control.section: "general"`:

| Field | Attribute | Control |
|---|---|---|
| Id | `anchor` | text → `id` on the block root (omitted when empty) |
| Title | `titleAttr` | text → `title` on the block root (omitted when empty) |
| Class | `extraClasses` | chips → appended to the root's class list |

`extraClasses` is one **space-separated string** in the model, presented as chips: Enter appends
(a pasted `"a b c"` becomes three chips, since space is the model's own separator), each chip's
close button removes. Chips are cloned from a **hidden real `x-ui.chip`** (`data-hb-chip-prototype`),
deliberately *not* a `<template>`: `x-ui.chip` carries its own `@once <style>/<script>`, and the
loop above is the only other chip render on the page — over a prop nothing passes, so it never
executes. A `<template>` here would be the *first* chip render on `/editor`, putting those `@once`
blocks inside `template.content`, where browsers apply neither the CSS nor the script — every chip
on the page would render unstyled. Same trap `ui/theme-preset-card` hit (TODO 6.7); the fix there
was ordering, here it's avoiding `<template>` altogether.

Unlike the settings section, this part is **not** built from the contract — the three keys are
fixed, because `render.template` references them by name. A contract that renames them loses the
section rather than getting a renamed one.

> Wired 2026-08-04. Previously these were three bare `<x-ui.input value="" />` with no control
> hooks: both contracts declared the attributes and consumed them in `render.template`, so the
> data path existed end to end and only the inputs were detached from it.

---

## 4. Style sub-tab

This is where the gap is widest, so read this section before designing a contract's `supports`.

### 4.1 The gating claim is false

`inspector.blade.php` mounts the Style panel like this, with a comment stating the component
gates each section on the contract's supports:

```blade
<x-live.block.style-panel :supports="$hbRegBlockContract['supports'] ?? []" />
```

`style-panel.blade.php` accepts `$supports` and **never reads it.** It mounts all ten sections
unconditionally and hardcodes its own typography map, including `letterSpacing`, which neither
shipped contract declares.

**Consequence:** the Style tab looks identical for every block type. A block that declares no
`supports` at all still gets Position, Flex Layout, Effects and the rest — all of them writing
into a model nothing reads. The toolbar does gate correctly (see the companion doc); the Style
panel is the surface that doesn't.

### 4.2 Full section inventory

| # | Section | Controls | Writes to | Status |
|---|---|---|---|---|
| 1 | **State** | Default / Hover / Active / Focus tabs | — | ❌ inert — see §6 |
| 2 | **Alignment** | 6-way self-align segmented | — | ❌ inert (no `data-hb-control`) |
| 3 | **Typography** | Font family | `typography.fontFamily` | ✅ wired + renders |
| | | Font weight | `typography.fontWeight` | ✅ wired + renders |
| | | Font size | `typography.fontSize` | ✅ wired + renders |
| | | Line height | `typography.lineHeight` | ⚠️ renders on **heading only** — paragraph declares no `--hb-*-lh` |
| | | Letter spacing | `typography.letterSpacing` | ⚠️ writes; no contract declares it |
| | | Horizontal / vertical align | — | ❌ inert |
| 4 | **Position** | X / Y / Rotation | `position.x` `.y` `.rotation` | ⚠️ writes; no contract declares it |
| | | Absolute Position | — | ❌ inert |
| 5 | **Flex Layout** | direction, 3×3 align grid, space-between/around | — | ❌ inert |
| | | Gap | `layout.gap` | ⚠️ writes; no contract declares it |
| 6 | **Spacing** | padding / margin, per side | `spacing.{group}.{side}` | ✅ wired + renders |
| | | padding / margin, "one value" + "H/V" modes | same four paths, fanned out | ✅ wired + renders — see below |
| 7 | **Dimensions** | W / H | `size.width` `size.height` | ✅ wired + renders |
| | | Fill / Hug / Clip checkboxes | — | ❌ inert |
| 8 | **Appearance** | Opacity | `appearance.opacity` | ⚠️ writes; no contract declares it |
| | | Corner radius ×4 | `border.radius.{corner}` | ✅ wired + renders |
| 9 | **Fill** | colour layer → colour picker | `color.text` | ✅ wired + renders |
| 10 | **Stroke** | colour layer | `border.color` | ✅ wired + renders |
| | | Weight (all) | `border.width` | ✅ wired + renders |
| | | Weight per side | `border.width.{side}` | ⚠️ writes; contract's `border.width` is a **scalar**, so the per-side write shadows it and renders nothing |
| | | Position / Join / Cap | — | ❌ inert |
| 11 | **Effects** | shadow stack + editor | — | ❌ inert |

Legend: ✅ writes and renders · ⚠️ writes to the model but nothing reads it back · ❌ no write at all

### 4.3 Spacing's three modes and one write

Spacing is the one section where the panel and the model disagree in shape, so it is worth
knowing how it resolves. The model has exactly four paths per group
(`spacing.padding.{top,right,bottom,left}`); the panel offers three presentations of them:

| Mode | Fields | Wiring |
|---|---|---|
| Top/Right/Bottom/Left (default) | 4 | each carries its own `data-hb-control` — 1:1 with the model |
| Horizontal/Vertical | 2 | aggregate view |
| One value for all sides | 1 | aggregate view |

The aggregate modes cannot use `data-hb-control` — one input owning two or four paths is not
something a single hook can express. Both already fan their value into the four side *inputs*,
so those inputs are the source of truth in all three modes, and `commitSpacingGroup()` writes the
whole `spacing.{group}` object in **one** `setSupport` call:

```js
window.hbEditor.setSupport(id, 'spacing.' + group, { top, right, bottom, left });
```

One object rather than four scalar writes is deliberate: `setSupport` re-renders the block on
every call, so four calls would rebuild the block's DOM four times per keystroke. The path walker
assigns whatever it is handed and `BlockRenderer` resolves
`supports.spacing.padding.top` through `dataGet`, so an object written at `spacing.padding` reads
back identically to four scalar writes.

Read-back needs the same care in reverse: the aggregate fields hold no model path, so
`syncControls` skips them. `syncSpacingAggregates()` re-derives them from the freshly synced side
inputs, or a re-selected block would show its real per-side values under a stale summary.

> Wired 2026-08-04. This is the pattern to copy for any future control whose UI shape doesn't
> match the model's shape.

### 4.4 Declared-but-unreachable supports

The mirror image of ⚠️ — things both shipped contracts fully declare, with working
`style.variables`, that **have no control anywhere in the inspector**:

- `color.background` — declared, variable exists; only `color.text` has an affordance
- `size.minWidth`, `size.minHeight`, `size.maxWidth`, `size.maxHeight` — declared, variables exist

---

## 5. The capability stylesheet — the part that changes everything

Before writing per-block CSS for opacity, shadows, flex or per-side borders: **it already exists.**

`src/Support/SupportsStyle.php` generates a stylesheet (served at
`/heisenberg-assets/editor-supports.css`) implementing every one of those capabilities against
generic `--hb-*` variables:

```css
[data-block-id].hb-supports {
    opacity: var(--hb-opacity, 1);
    letter-spacing: var(--hb-letter-spacing, normal);
    text-align: var(--hb-text-align, initial);
    align-self: var(--hb-text-align-v, auto);
    position: var(--hb-position-mode, static);
    transform: translate(var(--hb-tx, 0px), var(--hb-ty, 0px)) rotate(var(--hb-rotate, 0deg));
    box-shadow: var(--hb-shadow, none);
    overflow: var(--hb-overflow, visible);
    border-top-width: var(--hb-border-top-width, 0);      /* ×4 sides, width/style/colour */
    …
}
[data-block-id].hb-supports.hb-flex-layout { display: flex; flex-direction: var(--hb-flex-direction, row); … }
[data-block-id].hb-supports.hb-size-fill-w { width: 100%; }
[data-block-id].hb-supports.hb-size-hug-w  { width: fit-content; max-width: 100%; }
[data-block-id].hb-supports.hb-size-clip   { overflow: hidden; }
[data-block-id].hb-align-wide { width: min(100%, 1200px); margin-left: 50%; transform: translateX(-50%); }
[data-block-id].hb-align-full { width: 100vw; max-width: none; … }
```

It is gated behind an opt-in `.hb-supports` class **that no shipped contract carries**, so the
whole sheet is currently inert by design (it was built additively, so it could not disturb the
blocks that existed at the time).

**So the ⚠️ rows in §4.2 are not unimplemented — they are unclaimed.** To light up Position on a
new block you do not write CSS. You add `hb-supports` to the contract's `style.className` and
declare the variables the sheet already reads:

```json
"style": {
    "className": "hb-block-card hb-supports",
    "variables": {
        "--hb-opacity":  { "source": "supports.appearance.opacity", "default": "", "sanitize": "opacity" },
        "--hb-tx":       { "source": "supports.position.x",         "default": "", "sanitize": "length-signed" },
        "--hb-ty":       { "source": "supports.position.y",         "default": "", "sanitize": "length-signed" },
        "--hb-rotate":   { "source": "supports.position.rotation",  "default": "", "sanitize": "angle" },
        "--hb-shadow":   { "source": "supports.effects.shadow",     "default": "", "sanitize": "shadow" },
        "--hb-border-top-width": { "source": "supports.border.width.top", "default": "", "sanitize": "length-signed" }
    }
}
```

Those exact `supports.*` paths are the ones the Style panel's controls already write to. Declare
the variables and the existing controls start working — no new UI required for
opacity, position, per-side border weight, letter spacing or flex gap.

The structural capabilities (flex container, fill/hug/clip) are class-gated rather than
var-gated, because a CSS variable cannot safely express "leave `display` alone" — `display`'s
initial value is `inline`, not the tag's default. They need `style.classNames` predicate rules,
and the checkboxes that would drive them are currently inert.

---

## 6. Interaction states — the renderer supports them, the editor cannot author them

`BlockRenderer::INTERACTION_STATES` is real and tested:

```php
public const INTERACTION_STATES = [
    'hover'  => ':hover',
    'active' => ':active',
    'focus'  => ':focus-within',
];
```

`stateStylesCss()` reads `block.supports.states.<state>`, re-declares the contract's **same**
`style.variables` scoped to `[data-block-id]:hover` (plus a `.hb-state-preview-<state>` twin so an
editor can force the look), sanitizes every value through the variable's own kind, and appends
`!important` because the base values live in the root's inline style.

```json
"supports": {
    "states": {
        "hover": { "color": { "text": "#ff0000" }, "border": { "color": "#ff0000" } }
    }
}
```

**But:** `stateStylesCss()` is called by `PreviewController` only. Nothing in the editor writes
`supports.states`, and block-runtime's canvas renderer reads `contract.style.variables` for the
base values only — it has no state branch. The State tabs at the top of the Style panel
(Default / Hover / Active / Focus) carry no `data-hb-control` and no listener.

So, to answer the question directly: **states work end-to-end for anything that goes through
`BlockRenderer` — preview and public render — and are unauthorable in the editor.** A contract can
ship state overrides today by hand-writing them into a block's saved `supports.states`; the
editor will round-trip them untouched, render the base state on the canvas, and show the states
correctly in Preview.

Only style-bearing supports can be overridden per state — `stateDeclarations()` skips any
variable whose `source` doesn't start with `supports.`, so an `attributes.*`-sourced variable is
base-only by design.

---

## 7. Advanced sub-tab

Entirely hardcoded — it does not read the contract.

| Control | Writes to (attribute) | Status |
|---|---|---|
| Hide on XS/SM/MD/LG/XL/XXL | `hideXs` … `hideXxl` | ✅ both shipped contracts declare these + matching `style.classNames` → `hb-hide-*` |
| Animation type | `animate` | ⚠️ writes; **neither contract declares an `animate` attribute** |
| Duration | `animateDuration` | ⚠️ same |
| Delay | `animateDelay` | ⚠️ same |
| Play animation | — | ❌ inert |

`supports.animation: true` is declared by both contracts, but that is a supports flag with no
variable behind it; the Advanced panel writes to *attributes* named `animate*`. The catalogue that
would consume them (`src/Support/AnimationCatalog.php`, served at
`/heisenberg-assets/editor-animations.css`, with presets/easings/duration/delay) exists and is
unreferenced by either contract — the same "built, unclaimed" shape as `SupportsStyle`.

Because this panel is hardcoded, a new contract cannot add its own Advanced controls. Anything
bespoke goes in Content via `control.section: "settings"`.

---

## 8. Authoring checklist for a genuinely editable block

1. **Content controls** — declare each attribute with `control.section: "settings"` and a `type`
   from the rendered set (`text`, `textarea`, `select`). Give `select` its `options`.
2. **Style controls** — for every `supports.*` group you declare, add a matching entry under
   `style.variables` with a `source` pointing at it, or the control writes into the void.
   Cross-check the ✅/⚠️ table in §4.2 for which paths the panel actually writes.
3. **Opt into the capability sheet** — add `hb-supports` to `style.className` and use the generic
   `--hb-*` names from §5 if you want opacity / position / shadow / per-side border / flex.
4. **Alignment** — `supports.align` is special-cased and needs no variable. Values:
   `left`, `center`, `right`, `wide`, `full`. `wide`/`full` need the `.hb-supports` breakout rules.
5. **States** — add `supports.states` overrides for any variable sourced from `supports.*`.
   Preview-only until the State tabs are wired.
6. **Rich text** — a `render.template` node with `"type": "rich-text"` is what makes the toolbar's
   Format group appear. No node, no formatting.
7. **Verify** — `php artisan blocks:verify`. An invalid contract is silently excluded from the
   registry rather than crashing the editor, so this command is how you find out.

### Reference: what a contract may declare

```
supports groups     color · typography · spacing · border · dimensions · layout · size
                    animation · appearance · position · effects        (+ align, special-cased)

align values        left · center · right · wide · full

sanitize kinds      text · rich-text-inline · rich-text-block · url · html-safe · boolean · integer
                    color-token · color-token-or-transparent · size-token · border-style · font-token
                    size-value · color-value · font-family · font-weight
                    opacity · angle · length-signed · shadow
                    text-align · align-3 · position-mode · flex-direction · flex-justify
                    flex-align · overflow

attribute types     string · boolean · integer · number · rich-text · array · object · media · url · token

control types       text · textarea · rich-text · select · toggle · checkbox · range · number
                    media · link · button-group · repeater · chips · unit · color · font
```

`sanitize` kinds are enforced twice, deliberately in lockstep: `BlockContractValidator::SANITIZERS`
accepts the token at contract-load time, and `BlockRenderer::cssValueValid()` re-validates every
value at render time. A value failing its kind falls back to the variable's `default`, then to
omitting the declaration — it never reaches the page.

---

## 9. Known defects, in priority order

1. **`style-panel` ignores `$supports`** — every block shows every section. The prop is accepted
   and dropped; the inspector's comment claims otherwise. This is now the largest one.
2. **Per-side border width shadows the scalar** — panel writes `border.width.{side}` over a
   contract whose `border.width` is a scalar; neither shape then renders.
3. **State tabs inert** — the renderer's state system has no editor front-end (§6).
4. **Advanced animation writes undeclared attributes** — `animate*` is written by the panel and
   declared by no contract; `AnimationCatalog` is built and unreferenced.
5. **`color.background`, `size.min*`/`max*` unreachable** — declared with variables, no control.
6. **Inert decorative controls** — Alignment, Flex Layout, Effects, stroke join/cap, Fill/Hug/Clip,
   typography's horizontal/vertical align. Most are unclaimed rather than unimplemented: the CSS
   behind them already exists in `SupportsStyle` (§5).

### Fixed

- ~~Spacing writes nothing~~ — wired 2026-08-04 (§4.3). All eight paths, all three modes.
- ~~General section unwired~~ — wired 2026-08-04 (§3). `anchor`, `titleAttr`, `extraClasses`.

Regression coverage for both: `tests/Editor/InspectorWiringTest.php`.

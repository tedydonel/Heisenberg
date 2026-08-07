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
│   ├── Revisions          → history dialog, GET /editor/posts/{id}/revisions[/{rev}]
│   ├── Categories         → checklist, POST/DELETE /editor/posts/{id}/categories/{cat}
│   ├── Tags               → checklist, POST/DELETE /editor/posts/{id}/tags/{tag}
│   ├── Discussion         → "Allow comments" toggle, PUT …/discussion
│   └── Page layout        → X/Y padding sliders, PUT …/layout
│
└── BLOCK TAB ────────────────── contract-driven; empty until a block is selected
    └── Sub-tabs: [ Content | Style | Advanced ]
```

**The Revisions row** (`[data-hb-revisions-open]`, added 2026-08-06) is the Post tab's one
non-form affordance: it opens `live/revisions-dialog.blade.php`, which lists the post's snapshots
(written by `PostController::captureRevision()` on every update — a rolling `auto_save` row plus
every manual save, trimmed to `config('heisenberg.revisions.keep')`, `null` = unbounded).
Restoring fetches that revision's
client-shaped blocks and applies them with `hbEditor.replaceDoc()`, so a restore is an **ordinary
document swap and is itself undoable** — it is not a separate history mechanism, and nothing is
written back to the post until the next save. The row carries the URL template and the current
post id (learned from `hb:post-id` after a new document's first save, same contract as the
taxonomy bodies); with no id yet the dialog still opens and says so, since a never-saved document
has no history to show.

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

### 4.1 How gating works

`inspector.blade.php` mounts the Style panel with the selected block's contract supports:

```blade
<x-live.block.style-panel :supports="$hbRegBlockContract['supports'] ?? []" />
```

Each section renders only when the contract declares its group. The rule is deliberately
identical to the toolbar's, so the two surfaces cannot drift — a group counts as supported when
the key is **present and not `false`**; an empty array or object still counts, since a contract
that declares a group with no members has opted in.

```php
$has = fn (string $key): bool => Arr::get($supports, $key, null) !== null
    && Arr::get($supports, $key) !== false;
```

Gating here is **render-time only**, and that is sufficient. The inspector pre-renders one Style
panel per registered block type and shows/hides by name on selection, so each panel is already
built against exactly one contract. The toolbar needs a second JS layer only because a single
toolbar element is shared across every block.

Two mappings are **not** what their section titles suggest:

- **Dimensions gates on `size`, not `dimensions`.** Both are legal `SUPPORT_KEYS`, but the
  section's controls write `size.width`/`size.height` and no shipped contract declares a
  `dimensions` group at all. Gating on the title's key would hide a section both blocks fully
  support.
- **Appearance spans two groups.** Opacity writes `appearance.opacity`; the four corner fields
  write `border.radius.*`. Neither contract declares `appearance`, both fully declare
  `border.radius`. So the section shows when *either* group is present, and the opacity field
  gates independently on `appearance`.

Typography gates per control as well as per section — `lineHeight` is declared by heading only,
`letterSpacing` by neither.

For the two shipped contracts this means **Position, Flex Layout and Effects do not render at
all**, and neither does Appearance's opacity field or Typography's letter-spacing field.

> Wired 2026-08-05. Previously `style-panel.blade.php` accepted `$supports` and never read it,
> mounting all ten sections for every block and hardcoding an all-true typography map. This
> reversed `EditorRendersTest::test_style_panel_keeps_the_complete_pencil_section_stack_mounted`,
> which had pinned the opposite rule ("mounting a selected block must not remove designed
> sections") — that treated the `.pen` composition as a per-block contract when it is really the
> design's full vocabulary. Regression coverage: `tests/Editor/StylePanelGatingTest.php`.

### 4.2 Full section inventory

> Inventory updated 2026-08-05, after the TODO 7.x wiring wave and the review remediation
> (unit normalization, `hb-supports` reaching the canvas, `hb-align-*` CSS restored).

| # | Section | Controls | Writes to | Status |
|---|---|---|---|---|
| 1 | **State** | Default / Hover / Active / Focus tabs | retargets every supports control to `supports.states.<state>.<path>` | ✅ wired — `hbStatePath()` + canvas `previewState()` (§6) |
| 2 | **Alignment** | 3-way segmented (left/center/right) | `align` | 🚫 gated off for text contracts — `align` is block PLACEMENT (margin rules in `SupportsStyle::alignBreakoutRules()`); text alignment is Typography's textAlign/textAlignVertical |
| 3 | **Typography** | Font family | `typography.fontFamily` | ✅ wired + renders |
| | | Font weight | `typography.fontWeight` | ✅ wired + renders |
| | | Font size | `typography.fontSize` | ✅ wired + renders (bare numbers normalize to `px`) |
| | | Line height | `typography.lineHeight` | ✅ **heading only** — gated off for paragraph |
| | | Letter spacing | `typography.letterSpacing` | ✅ wired + renders (both contracts declare it) |
| | | Text horizontal / vertical | `typography.textAlign` / `.textAlignVertical` | ✅ wired + renders via SupportsStyle |
| 4 | **Position** | X / Y / Rotation / mode | `position.x` `.y` `.rotation` `.mode` | ✅ wired + renders (both contracts declare `position`) |
| 5 | **Flex Layout** | mode segmented (wrap/column/row), 3×3 align grid, space-between/around radios, Gap | `layout.*` | ✅ mounts for the container contracts (group/columns/column, 2026-08-06) — the EXTRACTED composition, wired: the mode segmented writes direction+wrap as a pair, one grid dot writes justify×align, the radios own justify's distribution values; per-feature gating via the `:layout` prop |
| 6 | **Spacing** | padding / margin, per side | `spacing.{group}.{side}` | ✅ wired + renders (bare numbers normalize to `px`) |
| | | padding / margin, "one value" + "H/V" modes | same four paths, fanned out | ✅ wired + renders — see below |
| 7 | **Dimensions** | W / H | `size.width` `size.height` | ✅ wired + renders |
| | | Fill / Hug / Clip checkboxes | `fillWidth`/`fillHeight`/`hugWidth`/`hugHeight`/`clipContent` attributes | ✅ wired + renders via `style.classNames` → `hb-size-*` |
| 8 | **Appearance** | Opacity | `appearance.opacity` | ✅ wired + renders |
| | | Corner radius ×4 | `border.radius.{corner}` | 🚫 gated off — both contracts dropped `border` (2026-08-05, TODO 7.2) |
| 9 | **Fill** | colour layer → colour picker | `color.text` | ✅ wired + renders |
| 10 | **Stroke** | colour layer, weights, position/join/cap | `border.*` | 🚫 **whole section gated off** — no contract declares `border` |
| 11 | **Effects** | shadow stack + editor | `effects.shadow` (one composed `box-shadow` string) | ✅ wired + renders |

Legend: ✅ writes and renders · 🚫 not rendered for either shipped contract (§4.1)

Net for the two shipped contracts: **only Flex Layout, Stroke, and Appearance's corner radius do
not render** — all three because no shipped contract declares the gating support (`layout` +
container, `border`). Every section that renders also paints the canvas live.

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

It is gated behind an opt-in `.hb-supports` class carried in a contract's `style.className` —
**both shipped contracts (heading, paragraph) carry it**, and since 2026-08-05 the canvas
runtime applies `style.className` to the block root too, so the sheet is live in the editor
canvas as well as in preview/publish.

To light up these capabilities on a new block you do not write CSS. You add `hb-supports` to
the contract's `style.className` and declare the variables the sheet already reads:

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
initial value is `inline`, not the tag's default. They ride on `style.classNames` predicate
rules; both shipped contracts declare the `hb-size-*` bindings and the Dimensions checkboxes
drive them.

---

## 6. Interaction states — supported by the renderer AND authorable in the editor

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

The editor front-end was wired 2026-08-05 (TODO 7.3): selecting a non-default State tab makes
`hbStatePath()` retarget every supports-keyed control to `supports.states.<state>.<path>`, and
`window.hbEditor.previewState(id, state)` forces the canvas to render with that state's
overrides merged over the base (`styleDeclarations()` reads `previewStates`), plus a
`.hb-state-preview-<state>` class for any contract CSS keyed off it. Both shipped contracts
declare `supports.states: {hover, active, focus}`.

So states now work end-to-end: authored in the inspector under a State tab, previewed live on
the canvas, and rendered as real `:hover`/`:active`/`:focus-within` CSS by
`stateStylesCss()` in preview/publish.

Only style-bearing supports can be overridden per state — `stateDeclarations()` skips any
variable whose `source` doesn't start with `supports.`, so an `attributes.*`-sourced variable is
base-only by design.

---

## 7. Advanced sub-tab

Catalog-driven since Phase G (2026-08-06): the Animate section renders straight from
`AnimationCatalog` (options, easings, ranges, defaults), so it cannot drift from the engine.

| Control | Writes to (attribute) | Status |
|---|---|---|
| Hide on XS/SM/MD/LG/XL/XXL | `hideXs` … `hideXxl` | ✅ both shipped contracts declare these + matching `style.classNames` → `hb-hide-*` |
| Animation type | `animate` | ✅ options = the full `AnimationCatalog::options()` list — a **searchable static `ui/combobox`** (`data-hb-control-type="combobox"`), not a `ui/select`: the catalog is ~40 presets, and static mode filters the Blade-rendered options itself, so no consumer wiring is needed |
| Duration / Delay | `animateDuration` / `animateDelay` | ✅ real ms ranges (100–3000 / 0–3000, step 50) |
| Easing | `animateEasing` | ✅ `AnimationCatalog::easingOptions()` |
| Play once | `animateOnce` | ✅ toggle |
| Play animation | — | ✅ re-triggers `.hb-anim-play` on the selected block's canvas root |

The `animate*` attributes are expanded into every contract that declares
`supports.animation: true` by `BlockRegistryService::applyCapabilities()` (attributes, the
`hb-anim-*`/`hb-ease-*` classNames, and the `--hb-anim-dur`/`--hb-anim-delay` variables).
The editor canvas loads the catalog stylesheet through `BlockViewData::blocksCss()` (the same
channel as `SupportsStyle`); the published page links `/heisenberg-assets/editor-animations.css`.
Detail rows gate on `data-hb-showwhen` (visible only while a preset is picked).

Because this panel is shared, a new contract cannot add its own Advanced controls. Anything
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

1. ~~Advanced animation writes undeclared attributes~~ — resolved 2026-08-06 (Phase G): the
   section is `AnimationCatalog`-driven end-to-end; see §7.
2. **`color.background`, `size.min*`/`max*` unreachable** — declared with variables, no control.
3. **Stroke / corner radius currently unreachable** — both contracts dropped `border`
   (2026-08-05, TODO 7.2), so the Stroke section and Appearance's corner fields never mount.
   The old "per-side width shadows the scalar" defect is moot until a contract re-declares
   `border`; decide the object-vs-scalar `border.width` shape when that happens.

### Fixed

- ~~`style-panel` ignores `$supports`~~ — gated 2026-08-05 (§4.1). Sections, and Typography's
  individual fields, now render only when the contract declares them.
- ~~Spacing writes nothing~~ — wired 2026-08-04 (§4.3). All eight paths, all three modes.
- ~~General section unwired~~ — wired 2026-08-04 (§3). `anchor`, `titleAttr`, `extraClasses`.
- ~~State tabs inert~~ — wired 2026-08-05 (TODO 7.3, §6): state-scoped writes + canvas preview.
- ~~Alignment / Position / Effects / Opacity / Fill-Hug-Clip / Typography H-V + letter-spacing
  inert or gated~~ — wired 2026-08-05 (TODO 7.1): both contracts declared the groups,
  variables, and `hb-supports`.
- ~~Style edits update the model but not the pixels~~ — 2026-08-05 review remediation: the
  canvas runtime now applies `contract.style.className` (so `hb-supports` reaches canvas block
  roots), the `hb-align-left/center/right` rules were restored in
  `SupportsStyle::alignBreakoutRules()`, bare numeric field values are unit-normalized
  (`px`/`deg`) at render time by both engines, and the JS `cssValueValid()` was brought to
  full sanitizer parity with `BlockRenderer` (see `CODE_REVIEW.md`).

Regression coverage: `tests/Editor/StylePanelGatingTest.php` and
`tests/Editor/InspectorWiringTest.php`.

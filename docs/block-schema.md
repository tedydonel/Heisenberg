# Heisenberg Block Contract Schema

The canonical definition of a Heisenberg block contract — the JSON file that *defines*
a block. One generic engine reads any contract to (a) render safe HTML server-side
(`BlockRenderer`) and (b) auto-generate the editor's inspector. There is **no per-block
PHP**.

Contracts live at `resources/blocks/<slug>/<slug>.json` (overridable via
`config('heisenberg.block_root')`). Each is validated by `BlockContractValidator`; invalid
contracts are excluded from the registry and reported.

> **Route protection.** The opt-in builder routes (`routes/web.php`, loaded when
> `config('heisenberg.builder.routes')` is true) — including `PUT /builder/theme` and
> `POST /builder/preview` — run under `config('heisenberg.middleware.builder')`
> (default `['web']`, unauthenticated, so the dev/testbench harness and this
> package's own tests work out of the box). A host that exposes these routes for
> real should widen that config value to include its own `auth`/role middleware
> (or leave `builder.routes` off and mount its own authenticated routes against
> the same controllers) before treating the surface as production-safe.

> **Design stance (vs GTC / Gutenberg).** Heisenberg is **JSON-serialized**: a block is
> stored as `{name, attributes, supports, innerBlocks}` and `BlockRenderer` reads attribute
> *values* by key. We therefore **do not** carry Gutenberg's HTML-parse fields
> (`source`/`selector`/`attribute`) — they're meaningless without an HTML round-trip. And
> we **derive** the inspector controls from attributes rather than duplicating them. The
> result is roughly half the size of the equivalent GTC contract.

## Top-level keys (16, all required)

| Key | Type | Purpose |
|---|---|---|
| `$schema` | string | Points to this file (`../../../docs/block-schema.md`). |
| `apiVersion` | int | Must be `1`. |
| `name` | string | `<prefix>/<slug>`, lowercase (e.g. `heisenberg/paragraph`). Prefix is configurable. |
| `title` | string | Display title; a `heisenberg::`-namespaced lang key is localized. |
| `category` | string | Inserter grouping (`text`, `media`, `design`, …). |
| `icon` | string | Lucide icon slug. |
| `description` | string | Short description (lang key allowed). |
| `keywords` | string[] | Inserter search terms. |
| `version` | string | Semver (e.g. `1.0.0`). |
| `attributes` | object | The block's data — **the single source of truth**. See below. |
| `supports` | object | Opt-in style-system features. See below. |
| `style` | object | `css`, `className`, and CSS-variable bindings. See below. |
| `render` | object | The `template` node tree + `publicPartial` + optional `script`. See below. |
| `innerBlocks` | object | `{ enabled: bool, allowedBlocks?: string[] }`. |
| `serialization` | object | `mode` must be `"json"`. |
| `security` | object | Per-block security posture; `allowCustomCss` must be `false`. |

Inspector **controls are not a top-level key** — they are derived from `attributes` and `supports`.

## `attributes`

A map of `attributeName -> definition`, in declaration order (which is also the inspector
order). Each definition:

```jsonc
"content": {
  "type": "rich-text",          // required; see types below
  "default": "",                // value used on insert and as a fallback
  "enum": ["a", "b"],           // optional; constrains the value (and makes the control a select)
  "sanitize": "rich-text-block",// required for rich-text/url/token
  "items": { ... },             // required when type = array
  "properties": { ... },        // required when type = object | media
  "control": { ... } | false    // optional inspector-control override (see "Controls")
}
```

- **Types:** `string`, `boolean`, `integer`, `number`, `rich-text`, `array`, `object`,
  `media`, `url`, `token`.
- **Sanitizers:** `text`, `rich-text-inline`, `rich-text-block`, `url`, `color-token`,
  `color-token-or-transparent`, `size-token`, `integer`, `boolean`, `html-safe`,
  `border-style`, `font-token`, `size-value`, `color-value`, `font-family`, `font-weight`,
  plus the full-kit overhaul (2026-07-19, Phase 1) additions below.

### Full-kit overhaul (Phase 1) sanitizer kinds

Added to `BlockContractValidator::SANITIZERS` **and** (lockstep, never via the permissive
fallback) `BlockRenderer::cssValueValid()`. Each is a strict allow-list/regex — invalid
input is dropped, falling back to the style variable's own `default`.

| kind | accepts | regex / rule |
|---|---|---|
| `opacity` | `0`, `1`, `0.NN`, `.NN`, or `0–100%` | `^(0\|1\|0?\.\d{1,3}\|(100\|[1-9]?\d)%)$` |
| `angle` | signed degrees, ≤3 integer digits | `^-?\d{1,3}(\.\d+)?deg$` |
| `length-signed` | signed length or bare `0` | `^(0\|-?\d+(\.\d+)?(px\|rem\|em\|%\|vw\|vh))$` |
| `shadow` | the keyword `none`, or 1+ comma-separated box-shadow layers | each layer: optional `inset`, 2–4 `length-signed` lengths, exactly one colour validated via the existing `isSafeColorValue()`. Comma/paren-aware splitting (not one mega-regex) keeps `rgba()`/`hsla()` layers intact. |
| `text-align` (enum) | `left\|center\|right\|justify` | |
| `align-3` (enum) | `start\|center\|end` | horizontal/vertical box alignment + block self-align (maps to `flex`/`text-align`/`align-self`) |
| `position-mode` (enum) | `static\|relative\|absolute` | |
| `flex-direction` (enum) | `row\|column\|row-reverse\|column-reverse` | |
| `flex-justify` (enum) | `start\|center\|end\|space-between\|space-around` | |
| `flex-align` (enum) | `start\|center\|end\|stretch` | |
| `overflow` (enum) | `visible\|hidden\|clip` | Clip Content |

Unsigned lengths (gap, width/height, layout padding) keep reusing the existing `size-value`;
fills keep reusing `color-value`.

## `supports`

Opt-in features the style system reads. Recognized groups: `color`, `typography`,
`spacing`, `border`, `dimensions`, `layout`, `size`, `animation`, `appearance`, `position`,
`effects`, plus `align` and `states` (both special-cased). `states` accepts only the
`hover`/`active`/`focus` keys and feeds `BlockRenderer::stateStylesCss()` plus the
inspector's State tabs.

```jsonc
"supports": {
  "align": ["left", "center", "right", "wide", "full"],
  "color": { "text": true, "background": true, "custom": false },
  "spacing": { "margin": true, "padding": true }
}
```

`align` values are constrained to `left|center|right|wide|full` and mean block PLACEMENT:
an instance's pick renders as class `hb-align-<value>` (`BlockRenderer::resolveClass()`
server-side, the canvas runtime client-side), styled by `SupportsStyle::alignBreakoutRules()`
— left/center/right place the block box via margins, wide/full are width breakouts. Text
alignment is NOT `align`; it is `supports.typography.textAlign`/`textAlignVertical`, which is
why the text contracts (heading, paragraph) declare no `align` at all. `custom` color is not
permitted (`false`).

The validator stays **shape-tolerant** for group internals (it only checks the group name
is recognized); the shapes below are the *documented convention* every contract should
follow so the generic renderer / panel-deriver understand them consistently.

### Full-kit overhaul (Phase 1) supports additions

```jsonc
"supports": {
  "typography": {
    "letterSpacing": true,                 // length-signed
    "textAlign": true,                     // enum text-align
    "textAlignVertical": true              // enum align-3 (-> align-self)
  },
  "size": {
    "fill": { "width": true, "height": true },   // boolean per axis -> hb-size-fill-w/h
    "hug": { "width": true, "height": true },    // boolean per axis -> hb-size-hug-w/h
    "clip": true                                 // instance value is the `overflow` enum (visible|hidden|clip)
  },
  "border": {
    // existing single-value form keeps working unchanged:
    "style": true, "width": true, "color": true,
    // NEW per-side side-map form (same pattern as spacing.margin), surfaced as the
    // "Stroke" panel; the single-value and side-map forms may coexist since they key
    // off different instance paths (supports.border.width vs supports.border.width.top):
    "width": { "top": true, "right": true, "bottom": true, "left": true },   // length-signed per side
    "color": { "top": true, "right": true, "bottom": true, "left": true },   // color-value per side
    "style": { "top": true, "right": true, "bottom": true, "left": true }    // border-style per side
  },
  "appearance": { "opacity": true },                          // opacity, 0-1 range
  "position": { "x": true, "y": true, "rotation": true, "mode": true },  // length-signed/length-signed/angle/position-mode
  "layout": { "direction": true, "justify": true, "align": true, "gap": true, "padding": true }, // flex container
  "effects": { "shadow": true }                                // shadow
}
```

- **`typography.letterSpacing`/`textAlign`/`textAlignVertical`** — extend the existing
  `typography` group; `textAlignVertical` maps to `align-self` (a no-op unless the block's
  parent is itself a flex/grid container).
- **`size.fill`/`hug`/`clip`** — `fill`/`hug` are booleans (no magnitude), rendered as
  toggles and consumed by `SupportsStyle` as utility classes (`hb-size-fill-w/h`,
  `hb-size-hug-w/h`), not vars. `clip`'s *instance* value is the `overflow` enum string
  itself (`visible|hidden|clip`), consumed via `--hb-overflow`.
- **`border.width`/`color`/`style`** — the existing single-value `true` form is unchanged;
  a side-map (`{"top": true, …}`) additionally derives per-side "Stroke" panel rows bound to
  `supports.border.<feature>.<side>`, distinct `--hb-border-<side>-width/style/color` vars.
- **`appearance.opacity`** — a 0–1 (or `%`) opacity control.
- **`position.x`/`y`/`rotation`/`mode`** — `x`/`y` translate the block (`--hb-tx`/`--hb-ty`,
  `length-signed`), `rotation` rotates it (`--hb-rotate`, `angle`), `mode` is the CSS
  `position` property (`--hb-position-mode`, `position-mode`). `x`/`y`/`rotation` compose
  into one `transform`, so a block using position offsets *and* `hb-align-wide/full`
  (which also sets `transform`) should pick one — not both.
- **`layout.direction`/`justify`/`align`/`gap`/`padding`** — the previously-dead `layout`
  group, now implemented: turns the block root into a flex container. Because flipping
  `display` can't be expressed safely as `var(--x, <fallback>)` (CSS's initial value for
  `display` is `inline`, not "whatever the tag already renders as"), this is gated behind
  its own `hb-flex-layout` class rather than riding on `.hb-supports` alone.
- **`effects.shadow`** — a `box-shadow` builder (`--hb-shadow`, `shadow`).

### The `SupportsStyle` capability sheet — activation contract

`src/Support/SupportsStyle.php` (mirrors `AnimationCatalog`) serves a generated stylesheet
at `/heisenberg-assets/supports.css`, linked in both `editor.blade.php` and
`preview.blade.php`. It is scoped to `[data-block-id]` — the same hook
`BlockRenderer::stateStylesCss()` and the editor's client JS (`resources/js/builder.js`)
already use — **plus** an explicit `hb-supports` marker class a contract must add to its
`style.className`/`style.classNames` to opt in:

```jsonc
"style": {
  "className": "hb-block-mynewblock hb-supports",
  "variables": {
    "--hb-opacity": { "source": "supports.appearance.opacity", "default": "1", "sanitize": "opacity" }
  }
}
```

The extra marker (rather than a bare `[data-block-id]` rule) is deliberate: two of the 8
already-shipped blocks (`pullquote`, `code`) set `text-align`/`border-top-width`/
`border-bottom-width`/`overflow-x` directly on their OWN root class, at the *same* CSS
specificity as a bare attribute selector — a bare `[data-block-id]` rule would make the
outcome depend on `<link>`/`<style>` load order. Gating behind `hb-supports` (a contract
opts in via `style.className` — heading and paragraph both do) keeps the sheet a no-op for
everything else regardless of order. Structural capabilities layer further class gates on
top: `hb-flex-layout` (flex container), `hb-size-fill-w/h`, `hb-size-hug-w/h`,
`hb-size-clip`. The `hb-align-*` classes (left/center/right/wide/full) are the exception —
their rules live in `SupportsStyle::alignBreakoutRules()` and are NOT gated behind
`hb-supports`.

Phase 1 does not migrate the 8 working blocks onto this sheet — that is Phase 4.

## Controls (derived)

The inspector is generated from `attributes` **and** `supports` (see the next section).
Attribute controls come first — **one control per attribute, in order** — the widget chosen
from the attribute:

| Attribute | Derived control |
|---|---|
| has `enum` | `select` (options from the enum) |
| `rich-text` | `rich-text` (edited inline on the canvas) |
| `boolean` | `toggle` |
| `integer` / `number` | `number` |
| `url` | `link` |
| `media` | `media` |
| `string` / `token` | `text` |
| `array` / `object` | *(none — needs an explicit override)* |

**Labels** resolve by convention from lang: `heisenberg::blocks.<slug>.controls.<snake_attr>`,
falling back to a humanized attribute name. **Select option labels** resolve from
`heisenberg::blocks.<slug>.options.<snake_attr>.<option_key>`, where `<option_key>` is the
value normalized (`_self` → `self`, `wide-line` → `wide_line`).

**Overrides.** When derivation can't express what's needed, add a `control` object to the
attribute (or `false` to hide it from the inspector):

```jsonc
"control": {
  "type": "media",            // force a widget (any of the 16 control types)
  "section": "hover",         // inspector panel grouping (default "settings")
  "min": 1, "max": 100, "step": 1,
  "showWhen": { "attribute": "level", "in": [2, 3] },
  "disableWhen": { "attribute": "dropCap", "equals": false },
  "forceWhen": { "attribute": "autoplay", "equals": true },
  "options": [                // curated value->label list the enum can't express
    { "label": "heisenberg::blocks.button.options.color.accent_1", "value": "var(--accent-1)" }
  ]
}
```

`showWhen`, `disableWhen`, and `forceWhen` are optional conditional-control predicates
(`R-ENG-COND`). Each predicate must name a declared attribute of the same block and contain
exactly one comparison: `{ "attribute": "name", "equals": <scalar> }` or
`{ "attribute": "name", "in": [<scalar>, ...] }`. `showWhen` controls row visibility,
`disableWhen` leaves the row visible but disables it, and `forceWhen` reserves a forced-value
condition for controls that require dependent state. The inspector honors these predicates so
it is built once, not twice.

Control types: `text`, `textarea`, `rich-text`, `select`, `toggle`, `range`, `number`,
`media`, `link`, `button-group`, `repeater`, `checkbox`, `chips`, `unit`, `color`, `font`
(the full `BlockContractValidator::CONTROL_TYPES` list — e.g. heading's `extraClasses`
uses `chips`).

### Panels derived from `supports`

Style panels are derived from `supports` too — so a block whose styling is entirely opt-in
style features (e.g. `heading`, `quote`) still gets a full inspector. Each enabled support
sub-feature yields one control, grouped into a panel by `section`:

| Support | Panel (`section`) | Control |
|---|---|---|
| `color.text`, `color.background` | `color` | token `select` |
| `typography.fontSize` | `typography` | token `select` |
| `typography.lineHeight` | `typography` | `number` |
| `spacing.margin` / `padding` / `blockGap` | `spacing` | token `select` |
| `border.radius` | `border` | token `select` |

A supports control carries `source: "supports"` and a `binding` (the path under
`block.supports`, e.g. `color.text`) instead of an `attribute`. Token selects carry a
`tokenKind` (`color` / `fontSize` / `space`) and draw their options from the configurable
**token registry** (`config('heisenberg.tokens')`). Attribute controls render first; the
style panels follow, in canonical order (color, typography, spacing, border).

### Nested style panels (`contract.panels`, `BlockRegistryService::derivePanels()`)

A SEPARATE, richer model from the flat `deriveSupportControls()` list above: a `key`/
`title`/`controls` (and optional `menu`) object per panel, mirroring the `heisenberg.pen`
Block.style component (`LtsDN`). Each row's `source` addresses the value directly
(`supports.<group>.<feature>[.<side>]`), not via a separate `binding`. Only panels with at
least one enabled feature are emitted; panel order is fixed regardless of contract
declaration order:

| Panel key | Title | Emitted when |
|---|---|---|
| `align` | Alignment | `supports.align` is a non-empty list |
| `position` | Position | `supports.position.{x,y,rotation,mode}` |
| `layout` | Flex Layout | `supports.layout.{direction,justify,align,gap,padding}` |
| `appearance` | Appearance | `supports.appearance.opacity` |
| `typography` | Typography | `supports.typography.*` (fontFamily/fontWeight/fontSize/lineHeight/letterSpacing/textAlign/textAlignVertical) |
| `size` | Size | `supports.size.*` (width/height/min*/max*/fill/hug/clip) |
| `color` | Color | `supports.color.{text,background}` |
| `margin` | Margin | `supports.spacing.margin` |
| `padding` | Padding | `supports.spacing.padding` |
| `border` | Border | `supports.border.{style,width,color,radius}` (single-value form) |
| `borderStroke` | Stroke | `supports.border.{width,color,style}` declared as a per-side map |
| `radius` | Border radius | `supports.border.radius` |
| `effects` | Effects | `supports.effects.shadow` |

`align`, `position`, `layout`, `appearance`, `borderStroke`, and `effects` are the Phase 1
full-kit overhaul additions (2026-07-19) — emitted only for contracts that declare the
matching new `supports.*` group, so pre-overhaul contracts see an unchanged panel list
*unless* they already declared `supports.align` (`paragraph`/`heading`/`pullquote`/`embed`
do — they now additionally get the Alignment panel; this is new inspector metadata, not a
change to rendered HTML).

**New control types** introduced by these panels — reusing `unit`/`select`/`toggle`/`color`/
`range` where possible, but a few need a widget Phase 2 hasn't built yet:

| Type | Used by | Shape |
|---|---|---|
| `segmented` | Alignment, Typography (textAlign/textAlignVertical), Flex Layout (direction) | `options: [{value, label}]`, single-select button row |
| `xy-pair` | Position (x/y offset) | `xSource`/`ySource` instead of `source` — one linked 2D control |
| `align-grid` | Flex Layout (justify/align) | `options: [{value, label}]`, 2D/3x3-style alignment picker |
| `shadow` | Effects | `source` only — a shadow-layer builder producing one `shadow`-sanitized string |

## `style`

```jsonc
"style": {
  "css": "./paragraph.css",        // safe relative .css path
  "className": "hb-block-paragraph hb-supports",
  "classNames": [                  // conditional classes — same predicate grammar as showWhen
    { "class": "hb-size-fill-w", "when": { "attribute": "fillWidth", "equals": true } }
  ],
  "variables": {
    "--hb-paragraph-color": {
      "source": "supports.color.text",   // must reference attributes.* or supports.*
      "default": "var(--accent-1)",      // a design token — raw hex (#…) is rejected
      "sanitize": "color-token"
    }
  }
}
```

`style.variables` are materialized by `BlockRenderer` into an inline `style="…"` on the
block root, each value validated by its `sanitize` token. `style.classNames` is an array of
`{class, when}` bindings validated by `BlockContractValidator::validateStyle()`: the class is
added to the block root when the predicate (same `equals`/`in` grammar as
`showWhen`/`disableWhen`/`forceWhen`) matches, both server-side (`resolveClass()`) and in the
canvas runtime. heading.json uses these for its `hb-hide-*` and `hb-size-*` gates.

## `render.template`

A recursive node tree compiled to safe HTML by one generic walk. Node shapes:

```jsonc
// element (default when no `type`)
{ "tag": "p", "class": "hb-block hb-block-paragraph",
  "attributes": { "data-block-id": "{{id}}" }, "children": [ … ] }

// rich-text (sanitized inline; bound to an attribute)
{ "type": "rich-text", "attribute": "content", "class": "hb-block-paragraph__text" }

// text (escaped plain text)
{ "type": "text", "content": "{{attributes.label}}" }

// inner-blocks (container slot — expands the block's `innerBlocks` children in place, each
// rendered through its OWN contract; depth-capped. Works as the sole child or as a sibling.)
{ "type": "inner-blocks" }

// text-lines (the generic list primitive, 2026-08-08: one element per non-empty line of a
// PLAIN attribute — escaped text, no rich-text tier. `tag` is static (never interpolated),
// constrained to the tag-name charset, default `li`. The list block's ul/ol use this to get
// a real <li> per item.)
{ "type": "text-lines", "attribute": "content", "tag": "li", "class": "hb-block-list__item" }
```

- **Tokens** (in `tag`, `class`, `attributes`, text `content`): `{{id}}`, `{{name}}`,
  `{{attributes.X}}` (locale-aware), `{{supports.X}}`.
- **URLs** (`src`/`href`/`srcset`/`poster`) are scheme-allow-listed; empty `src`/`srcset`
  are dropped.
- **Conditional attributes** — an attribute value may be an object: `{ "boolean": "{{attributes.open}}" }`
  renders a bare presence attribute (`<details open>`) only when truthy; `{ "value": "{{attributes.start}}",
  "omitWhenEmpty": true }` renders only when its resolved value is non-empty.
- **Dynamic tags** — a `tag` interpolated from `{{attributes.X}}` accepts X only when its raw
  value is strictly present in X's contract `enum`; otherwise X resolves to the enum's first
  value. The resolved tag must still match `[a-z][a-z0-9-]*` and the safe dynamic-tag allow-list
  (sectioning / flow / list / heading elements), otherwise it falls back to `div`. Static,
  author-written tags are constrained only by the tag-name charset.
- **Void elements** render without a closing tag.
- **`inner-blocks` node** expands the block's `innerBlocks` children in place — each child
  rendered through its own contract (its own template + sanitizers), recursively and
  depth-capped. Each nested block is its own security boundary.
- **Editor-only nodes** (class containing `__picker`, or `data-image-picker`) are dropped
  from the published HTML.
- `publicPartial` names a Blade partial for the public view; `script` is `null` or a safe
  relative `.js` path.

## `email` (optional — the email surface, docs/email-system.md §4)

```jsonc
"email": {
  "template": { /* a render.template node tree, table-based markup, SAME rules */ }
}
```

Presence of a top-level `email` key opts a block into the email palette (and
`BlockRegistryService::contractsFor('email')`'s result); absence means it never appears
there — there is no separate allow/deny list to keep in sync. `email.template` is a
`render.template` node tree, validated by the **exact same** node rules
(`BlockContractValidator::validateTemplateNode()` — one method, shared) — same node types,
same token grammar, same URL/tag/attribute rules. There is no looser email-specific shape.

`BlockRenderer::renderBlock($block, $locale, 'email')` (the `$surface` parameter, default
`'render'`) walks `email.template` instead of `render.template`; a block whose contract has
no `email` section (or whose section has no `template`) simply renders empty for that
surface — never an error, exactly how an unknown block name already behaves. The generic
walk is otherwise identical: `{{...}}` tokens, `rich-text`/`text-lines`/`inner-blocks`
nodes, URL scheme allow-listing, and the automatic root `style="…"` from `style.variables`
(§`style` above) all apply unchanged — an email template gets the SAME sanitization
guarantees as `render.template`, just through table-based markup instead of the web
DOM.

Ten blocks ship an `email` section (heading, paragraph, image, button, separator, group,
columns, column, list, quote); `embed` and `icon` deliberately do not (external
webfont/iframe dependency, revisit later) — see each JSON file under `resources/blocks/`
for the concrete table markup. `EmailRenderer` (beside `BlockRenderer`, never replacing it)
is what actually turns these into a self-contained email — token resolution, image `cid:`
embedding, the canonical shell — see its own class docblock and docs/email-system.md §5.

## `innerBlocks` / `serialization` / `security`

```jsonc
// leaf
"innerBlocks": { "enabled": false },
// or a container:
"innerBlocks": {
  "enabled": true,
  "allowedBlocks": ["heisenberg/column"],                      // a list of names, or "*" for any block
  "orientation": "horizontal",                                 // vertical | horizontal | auto
  "template": [["heisenberg/column"], ["heisenberg/column"]],  // seed children on insert: [name, attrs?]
  "appender": "button",                                        // default | none | button | false
  "parent": ["heisenberg/columns"]                             // (on a child block) restrict its insertion parent
},
"serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
"security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
```

`innerBlocks.allowedBlocks` is `"*"` or a list of names; `orientation` ∈ `vertical|horizontal|auto`;
`appender` ∈ `default|none|button|false`; `parent` and `template` are arrays. `serialization.mode`
must be `"json"`; `security.allowCustomCss` must be `false`. `security.richText` selects the inline
tier enforced at render: `none` | `inline-basic` | `inline-basic-no-link`.

## Complete example (`paragraph`)

```jsonc
{
  "$schema": "../../../docs/block-schema.md",
  "apiVersion": 1,
  "name": "heisenberg/paragraph",
  "title": "heisenberg::blocks.paragraph.title",
  "category": "text",
  "icon": "pilcrow",
  "description": "heisenberg::blocks.paragraph.description",
  "keywords": ["paragraph", "text", "body"],
  "version": "1.0.0",
  "attributes": {
    "content": { "type": "rich-text", "default": "", "sanitize": "rich-text-block" }
  },
  "supports": {
    "align": ["left", "center", "right"],
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontSize": true },
    "spacing": { "margin": true, "padding": true }
  },
  "style": {
    "css": "./paragraph.css",
    "className": "hb-block-paragraph",
    "variables": {
      "--hb-paragraph-color": { "source": "supports.color.text", "default": "var(--accent-1)", "sanitize": "color-token" }
    }
  },
  "render": {
    "template": {
      "tag": "p",
      "class": "hb-block hb-block-paragraph",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [ { "type": "rich-text", "attribute": "content", "class": "hb-block-paragraph__text" } ]
    },
    "publicPartial": "blocks.paragraph",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```

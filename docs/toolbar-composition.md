# The Block Toolbar — full composition and contract wiring

What the docked block toolbar can render, which block-contract key turns each group on, and where
each button's value goes. Companion to [`inspector-composition.md`](inspector-composition.md);
schema reference in [`block-schema.md`](block-schema.md).

> **Verified against the tree at 2026-08-04** (commit `3f5ceed`), against
> `resources/views/components/live/toolbar/**` and `block-runtime.blade.php`'s `gateToolbar()`.

---

## 1. What it is

One toolbar element exists for the whole document. It is **docked into the selected block** —
`dockToolbar()` inserts it as that block's first child — and stowed back into a holder on
deselect. It is not per-block markup, so it carries no block state of its own; every handler
resolves the current block through the runtime API:

```js
const id    = window.hbEditor.getSelectedId();
const model = window.hbEditor.getModel(id);
const contract = window.hbEditor.getContract(model.name);
```

Never via DOM ancestry — while stowed it has no block ancestor at all.

**Unlike the Style panel, the toolbar gates correctly.** `gateToolbar(tb, model)` runs on every
selection and shows/hides groups from the newly selected block's contract. This is the model the
inspector's Style tab was supposed to follow.

---

## 2. Composition

```
div.hb-tb  [data-hb-toolbar]  role="toolbar"
│
├── GROUP: handle ──────────────────── always present
│   ├── ⠿  drag              [data-tb-action="drag"]
│   ├── ⌐  select parent     [data-tb-action="select-parent"]
│   ├── ↑  move up           [data-tb-action="move-up"]
│   ├── ↓  move down         [data-tb-action="move-down"]
│   └── T▾ block-type pill   [data-tb-popover="type"]
│
├── GROUP: format ─────────────────── gated: render.template contains a rich-text node
│   ├── B / I / U / S        [data-tb-format="bold|italic|underline|strikethrough"]
│   ├── 🔗 link              [data-tb-popover="link"]
│   └── <> code              [data-tb-format="code"]
│
├── GROUP: style
│   ├── A  text colour       [data-tb-popover="color"]   gated: supports.color.text|background
│   ├── 💾 save as block     [data-tb-action="save"]     always present
│   └── ≡▾ align             [data-tb-popover="align"]   gated: supports.align non-empty
│
├── GROUP: action
│   └── ⋯ more               [data-tb-popover="more"]    always present
│       (the AI trigger was removed 2026-08-06 — it shipped visible with no backing
│        popover/runtime; it returns with the AI feature)
│
└── POPOVERS
    ├── [data-tb-pop="type"]   → toolbar/type-menu
    ├── [data-tb-pop="align"]  → toolbar/align-menu
    └── [data-tb-pop="color"]  → toolbar/color-menu
```

---

## 3. Gating — the exact rules

Two layers, and they must agree. The Blade layer decides what is *rendered* into the DOM; the
runtime layer decides what is *visible* for the current selection.

**Blade** (`block-toolbar.blade.php`), from the `supports` prop:

```php
$has = fn ($key) => Arr::get($supports, $key, null) !== null
    && Arr::get($supports, $key) !== false;
```

**Runtime** (`gateToolbar()` in `block-runtime.blade.php`), re-run on every `hb:block-selected`:

| Group / button | Shown when | Contract key |
|---|---|---|
| Format group | `templateHasRichText(contract.template)` | a `render.template` node with `"type": "rich-text"` |
| Text colour | `supports.color.text \|\| supports.color.background` | `supports.color` |
| Align | `Array.isArray(supports.align) && supports.align.length > 0` | `supports.align` |
| handle, save, more | always | — |

Note the asymmetry worth knowing when authoring: the Format group keys off the **template**, not
off `supports`. A block with no rich-text node in its render template gets no formatting buttons
no matter what it declares — which is correct, since `execCommand` needs a `contenteditable`
target.

---

## 4. Where each control writes

| Control | Effect | API call |
|---|---|---|
| Bold / Italic / Underline / Strikethrough | `document.execCommand` on the live selection inside `.hb-ce` | — (mutates the editable; `input` fires natively, runtime writes it back to `attributes.content`) |
| Code | manual `<code>` wrap/unwrap + synthetic `input` | — (no `execCommand` exists for it) |
| Link | dynamic popover → `execCommand('createLink')` | — |
| Align | `hbEditor.setSupport(id, 'align', value)` — block PLACEMENT (margins), hidden for text contracts (they declare no `align`; text alignment is Typography's) | ✅ |
| Text colour | `hbEditor.setSupport(id, 'color.text', value)` | ✅ |
| Block-type pill | `hbEditor.setAttribute(id, 'level', n)` | ✅ |
| Move up / down | `hbEditor.moveBlock(i, j)` | ✅ |
| Drag | owned by `wireCanvasBlockDrag`'s `pointerdown` | ✅ |
| Select parent | — | hidden until a block actually nests (`parentIdOf`) |
| Save as block | — | ❌ inert — no reusable-block capability |
| More (⋯) | Duplicate (`insertBlock` + copy attributes/supports) and Delete (`removeBlock`) via `[data-tb-pop="more"]` | ✅ |

The remaining inert ones do nothing when clicked — the listener-less `hb:format` /
`hb:toolbar-action` / `hb:toolbar-popover` broadcast events were removed (2026-08-05 review) so
a dead dispatch can no longer be mistaken for working integration. The popovers' own local
events (`blocktype`, `alignselect`, `colorselect`) remain, each consumed inside the toolbar.

---

## 5. The three popovers

### Type menu — `[data-tb-pop="type"]`

Offers heading levels and dispatches `blocktype` with a `level`. The toolbar's listener calls
`setAttribute(id, 'level', …)`.

**This is a level switcher, not a block-type switcher.** A genuine "turn a paragraph into a
heading" is a cross-contract transform — different attribute sets, different sanitize tiers,
different render templates — and the runtime has no such operation. Switching among H1–H6 works
because they are one contract with one attribute.

### Align menu — `[data-tb-pop="align"]`

Rebuilt on every selection from the block's **own** `supports.align`, sharing
`alignmentValuesFor(name)` with the runtime so the offered set and the renderable set cannot
drift. Options render `hidden` and are unhidden per selection.

`justify` is deliberately not offered: no shipped contract declares it, and an option that can
never apply is worse than no option. Declaring `["left","center","right","wide","full"]` in a new
contract will surface left/center/right here — `wide`/`full` have no menu entry yet, though
`BlockRenderer::resolveClass()` and the `SupportsStyle` breakout rules both handle them.

Selection writes `supports.align`, which is special-cased by the renderer into an
`hb-align-<value>` class — **no `style.variables` entry needed.**

### Colour menu — `[data-tb-pop="color"]`

Swatches come from `config('heisenberg.tokens.color')` — the same token registry the
`color-value` sanitizer allows. Writes `supports.color.text` only.

`supports.color.background` has **no toolbar affordance and no inspector affordance**, despite
both shipped contracts declaring it with a working `--hb-*-bg` variable. It is reachable only by
writing the model directly.

The trigger's underline bar previews the selected block's own `supports.color.text` on every
`hb:block-selected`.

> The Style/Themes panel's saved theme is **not** merged into these swatches — the editor has no
> such merge wiring, so this is the raw config default. A real palette here is a follow-up.

---

## 6. Selection and formatting mechanics

Three details that matter if you extend this file:

**Selection survival.** Clicking any button outside the editable would shift focus and let the
browser collapse the text selection before the click handler runs. The toolbar blocks it at the
source:

```js
tb.addEventListener('mousedown', (e) => {
    if (e.target.closest('[data-tb-format], [data-tb-popover="link"]')) e.preventDefault();
});
```

**Never cache the editable.** `setAttribute`/`setSupport` re-render the block's DOM on every
write, so any held reference goes stale immediately. `editableInBlock()` re-resolves each time.

**Pressed state tracks the caret, not the click.** A debounced `selectionchange` listener runs
`syncFormatStates()`, so Bold reads "on" the moment the caret lands inside bold text — via
`queryCommandState` for the exec-backed formats, and `inlineAncestor('CODE')` for code.

---

## 7. Authoring checklist

To get the full toolbar on a new block:

1. **Format + AI** → put a `{"type": "rich-text", "attribute": "content"}` node in
   `render.template`.
2. **Text colour** → declare `supports.color.text: true`, and add a `style.variables` entry
   sourced from `supports.color.text` or the write won't render.
3. **Align** → declare `supports.align` as a non-empty array from
   `left · center · right · wide · full`. No variable needed. For `wide`/`full`, add `hb-supports`
   to `style.className` so the breakout rules apply.
4. **Type pill** → only meaningful if the contract has a level-like enum attribute.
5. The handle, save and more groups need nothing.

A block declaring none of the above still gets a usable toolbar: drag, move up/down, save, more.

---

## 8. Known gaps

1. **`select-parent` waits on real nesting UI** — the canvas now renders `innerBlocks`
   children (read-only, depth 20, same cap as `BlockRenderer`), but there is no way to
   CREATE nesting in the editor yet; the button stays hidden until `parentIdOf` finds one.
2. **`save` (save as block) is inert** — no reusable-block/pattern capability in `hbEditor`.
   `heisenberg_patterns` is reserved in config with no model behind it.
3. ~~AI has no popover content~~ — the trigger was removed 2026-08-06 rather than shipped
   dead; it returns with the AI feature. (More gained its Duplicate/Delete menu the same day.)
4. **`supports.color.background` has no affordance anywhere.**
5. **Align menu has no `wide`/`full` entries**, though renderer and stylesheet both support them.
6. **Colour swatches ignore the saved theme.**
7. **No cross-contract "turn into"** — the type menu switches heading levels only.

# The Code view — Heisenberg's shortcode dialect

The editor has two surfaces over one document model: **Visual** (the canvas) and **Code**
(`live/code-editor.blade.php`), toggled by the footer's *Code Editor* chip. Code is not raw
HTML — it is a **shortcode dialect of the block contracts**, so everything expressible from
the inspector or toolbar is expressible as text, and nothing else is. Both surfaces
round-trip through `window.hbEditor`'s `doc.blocks`; the server-side pipeline (validation,
sanitization, rendering) is unchanged and unaware the code view exists.

This dialect is also the intended **machine-authoring surface**: an AI (or script) that can
emit these tags can build a page, because the parser validates against the same registry
the canvas uses and reports line-numbered errors.

## Grammar

```
[tag attr=value long-attr="value with spaces"]body or children[/tag]
[tag /]                                     ← self-closing (no body, no children)
```

- **Tags** — the contract slug (`heading`, `paragraph`), or its HTML-familiar alias:
  `p` for paragraph, `h1`…`h6` for heading (the level rides the tag, so `[h3]` means
  heading + `level=3`). Real slugs always win over aliases.
- **Plain attribute names** are contract attributes (`anchor`, `hideXs`, `animate`…).
  Types coerce per the contract: booleans from `true`/`1`, integers/numbers via `Number`,
  `object`/`media`/`array` attributes take JSON strings. Enum violations are errors.
- **Style names** are CSS-familiar aliases over the supports paths the inspector writes —
  the full dotted path (`typography.fontSize`) is always accepted too:

  | Short | Path | Short | Path |
  |---|---|---|---|
  | `color` | color.text | `bg` | color.background |
  | `font` / `weight` | typography.fontFamily / fontWeight | `font-size` / `line-height` / `letter-spacing` | typography.\* |
  | `text-align` / `text-valign` | typography.textAlign / textAlignVertical | `w` `h` `min-w` `min-h` `max-w` `max-h` | size.\* |
  | `padding` `margin` `radius` | CSS box shorthands (see below) | `padding-top` … `margin-left`, `radius-tl` … | per side/corner |
  | `border-width/color/style` | border.\* | `gap` `direction` `wrap` `justify` `align-items` | layout.\* |
  | `position` `x` `y` `rotate` | position.\* | `opacity` / `shadow` / `clip` | appearance / effects / size |

- **Box shorthands** use CSS value semantics: `padding=12px` (all sides),
  `padding="4px 8px"` (vertical horizontal), `padding="1px 2px 3px 4px"` (TRBL); same for
  `margin` and `radius` (TL TR BR BL).
- **State prefixes** — `hover:`, `active:`, `focus:` target `supports.states`:
  `hover:color=#123456` ≡ `states.hover.color.text="#123456"` (Tailwind-familiar).
- **Values** are unquoted when simple (`40px`, `#fff`, `var(--hb-t-c-1)`, `space-between`);
  anything with spaces, slashes, or quotes takes `"…"` with `\"`/`\\` escapes.
- **Body** — the value of the contract's `rich-text` attribute (inline HTML allowed; the
  server sanitizes at save/render as always). Blocks whose `innerBlocks.enabled` is true
  take nested block tags as body instead.
- Only **non-default** values are serialized, `layers` keys never appear (inspector editing
  state, re-synthesized from the scalar on selection).

### Formatting

Serialization pretty-prints. A tag whose inline form fits in **80 columns** stays on one
line; a longer one breaks **one attribute per line** (Prettier-style), closing bracket back
at the tag's indent. Supports serialize in a **canonical group order** mirroring the
inspector's panels — `align → position → layout → appearance → typography → size → color →
spacing → border → effects → animation → states` — so the code reads like the panel and
state overrides always come last. The parser accepts any whitespace inside a tag, so
hand-written formatting is never an error; re-serializing normalizes it. Errors inside a
broken tag point at the attribute's own line.

**Bodies pretty-print too**: adjacent block-level siblings (`</div><div>`, `<br>`…) split
onto their own lines and long prose word-wraps near 90 columns. HTML collapses the inserted
newlines back to whitespace, so wrapping never changes what renders, and the wrap is
idempotent — the round trip stays byte-stable.

```
[h3
  rotate=3deg
  font-size=40px
  w=520px
  padding="4px 8px"
  hover:color=#123456
]
  Hello <em>world</em>
[/h3]
```

### Example

```
[h3 font-size=40px padding=12px hover:color=#0000ff]
  Hello <em>world</em>
[/h3]

[p color=var(--hb-t-c-1) padding-top=var(--hb-t-sp-2)]
  Body text with a <strong>theme-token</strong> binding.
[/p]
```

The same document in fully-long form (`[heading level=3 typography.fontSize="40px" …]`)
parses identically — the short dialect is additive, so verbose AI output and old documents
always remain valid, and serialization normalizes to the short form.

## Editing semantics

- Typing re-validates on a 500 ms debounce. A **clean parse replaces the whole doc**
  (`hbEditor.replaceDoc`) — models get fresh ids, defaults merged, canvas rebuilt,
  `hb:blocks-changed` fired (so dirty-state/autosave see code edits like any other edit).
- **Errors never touch the doc.** They light the gutter line numbers and the status strip
  (each entry jumps the caret to its line), and they **block the switch back to Visual**
  until fixed or *Revert to canvas* is pressed.
- A doc change made elsewhere while Code is open and pristine re-serializes the text;
  once the user has typed, their text wins on the next clean apply.

## Error catalog (all line-numbered)

| Error | Meaning |
|---|---|
| Unknown block ":slug" | No registered contract matches the slug |
| Unknown attribute or support path | Not a contract attribute, and the first path segment is not a declared supports group — including `states.<x>` where `x` is not hover/active/focus (the runtime's `setSupport` refuses those writes too, from every surface) |
| Invalid value | Type coercion failed or an enum was violated |
| [:slug] cannot contain other blocks | Nested tag inside a non-container contract |
| [:slug] does not accept text content | Body text on a contract with no rich-text attribute |
| Unexpected closing tag / never closed | Tag balance problems |
| Content outside of any block | Top-level text that belongs to no tag |

Known v1 constraint: a literal `[word]` inside body text that *happens* to scan as a tag is
parsed as one — unknown slugs then error rather than silently becoming text, which is the
right bias for machine-written documents.

## Runtime API

`window.hbEditor.replaceDoc(blocks)` — whole-document swap taking models in the save shape
(`{name, attributes, supports, innerBlocks}`), with or without ids. Unknown block names are
dropped (same rule as `insertBlock`), attributes merge over contract defaults, nested
`innerBlocks` are normalized recursively and render read-only on the canvas. This is also
the `/editor/{post}` hydration path.

## Chrome

Syntax colouring runs off the **same tokenizer as the parser** (so the highlight can never claim
a shape the parser rejects), against a GitHub-style `--hb-code-*` palette with a full light and
dark set. Attribute values colour **by type** — numbers with units, `var()` token references,
strings, and hex colours, a hex being underlined with *itself* as an inline swatch that adds no
box metrics the transparent-textarea overlay would have to match — while state prefixes
(`hover:`) colour apart from the path they qualify and inline HTML in a body reads as markup
rather than prose. The caret's line is tracked by a **current-line band** under the highlight
mirror with the matching gutter number brightened, so a long document never loses your place.

The code surface scrolls through **two `ui/custom-scrollbar` instances** (the primitive
gained an `axis="x"` variant for this — same thumb, bottom edge, `scrollLeft`), with the
textarea's native scrollbars hidden from first paint. Smoothing is off on both axes:
precise scrolling is right for a text surface, and caret-driven native scrolls must never
fight an easing loop. The highlight overlay and gutter stay in sync under custom scrolling,
and the bars re-measure on every content change (content growth fires no resize event they
could otherwise see).

## Verification

`tests/js/code-editor-matrix.mjs` (Playwright, real Chromium) covers: toggle + serialize,
author-in-code → apply → computed-style paint in Visual, round-trip stability, error
gutter/strip, blocked switch, and Revert. `tests/Editor/EditorRendersTest.php` asserts the
panel and the footer toggle ship.

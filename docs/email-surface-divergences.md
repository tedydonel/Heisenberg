# Email surface divergences — measurement report

Status: **as-measured, 2026-09-20**, against the harness in `tests/Email/ThreeWaySurfaceDiffTest.php`,
`tests/Email/Support/{EmailDiffFixtures,SurfaceNormalizer}.php`, and the fixture corpus in
`tests/Fixtures/email-diff/*.json`. This document is the output of that harness plus one
additional, one-off offline capture of the real editor canvas (jsdom, not pinned in any test —
see §5). It supersedes guesswork about "emails don't render properly" with an enumerated,
evidenced list of what actually differs between the three surfaces, and, more importantly, what
does **not**.

The one-line answer the owner needs first:

> **The `EmailRenderer` preview and the actual sent MIME payload do not meaningfully differ.**
> Every fixture in the corpus renders byte-identical HTML, byte-identical plain text, and a
> byte-identical subject between `EmailRenderer::render($post, $locale, preview: true)` and
> `HeisenbergMailable`'s real send payload, once the one documented, expected difference — a live
> image URL vs. a `cid:` reference — is normalized away. **Whatever "doesn't render properly"
> refers to, it is not explained by the preview lying about what gets sent.** The divergence, where
> it exists at all, is entirely between the editor CANVAS and everything else.

---

## 1. The fixture corpus

`tests/Fixtures/email-diff/*.json` — four small email documents, loaded into real `Post`+`Block`
rows by `Heisenberg\Tests\Email\Support\EmailDiffFixtures::load()`:

| Fixture | Exercises |
|---|---|
| `core-text-and-variables.json` | heading, paragraph, quote, list, separator, button — 6 of the 10 email-safe blocks; a `{{ variable }}` token in the post title, a heading, and a button URL; bilingual `_fr` attribute variants throughout |
| `media-image-sources.json` | image via a media-library path (a real `PublicFile` row is created for it, so it resolves and gets embedded) **and** image via an external URL (no `PublicFile` match — left as a live remote link); bilingual caption |
| `nested-layout.json` | `group` > `columns` (4 columns, to exercise the 3-column cap) > `column` > `paragraph`/`heading`; plus top-level `embed` and `icon` blocks — the two contracts with **no** `email` section |
| `degradations.json` | a `group` with a `linear-gradient` background **and** `align: center` together (the two documented email-safe degradations), plus a second block (`image`) with its own `align: center` |

Together the corpus covers all 10 blocks with an `email` contract section (`heading`, `paragraph`,
`quote`, `list`, `separator`, `button`, `image`, `group`, `columns`, `column`) and both blocks
without one (`embed`, `icon` — confirmed via `php -r` against every `resources/blocks/*/*.json`
before writing the fixtures).

The loader (`tests/Email/Support/EmailDiffFixtures.php`) reuses the exact patterns already
established in `tests/Email/EmailRendererTest.php` (`addBlock`'s shape, `makeImageFile`'s tiny-GIF
+ variants trick) rather than inventing a parallel convention — it just reads them from JSON
instead of hand-writing `Block::create()` calls per test.

## 2. The normalizer, and its self-diff proof

`tests/Email/Support/SurfaceNormalizer.php` — before any two surfaces are diffed, both are:

1. token-normalized (`cid:XXXX@heisenberg` → `cid:NORMALIZED`; ISO-8601 timestamps, defensively —
   none of the current fixtures emit one),
2. parsed with `DOMDocument` (full documents parsed as-is; fragments wrapped in a throwaway
   `<div>` first — done as two branches specifically because wrapping a **full** document like
   `EmailRenderer`'s own output in an extra `<div>` makes the HTML5 parser foster-parent the
   nested `<html>/<head>/<body>` out from under the wrapper, silently dropping the `<head>`/
   `<style>` block from the comparison — confirmed by hand before relying on it),
3. walked to strip volatile attributes (`nonce`, `data-block-id`), sort every element's remaining
   attributes alphabetically (DOMDocument does not promise stable output order), and — only when
   asked (`stripImageSrc: true`) — replace every `<img src="…">` value with a constant placeholder,
4. re-serialized and whitespace-collapsed.

**Proof it is not vacuous** (`ThreeWaySurfaceDiffTest::test_normalizer_self_diff_is_empty` and
`test_normalizer_proof_is_not_vacuous_it_actually_absorbs_random_cid_tokens`, both passing):

- The same HTML, normalized twice, is byte-identical (trivial idempotency).
- **Two independently-generated renders of the same post** (`EmailRenderer::render()` called
  twice) normalize to byte-identical output — this is the real proof: it is not comparing a
  string to itself, it is comparing two separate render passes.
- Two strings that are deliberately different only in their random `cid:` token
  (`cid:aaaa...@heisenberg` vs `cid:bbbb...@heisenberg`) — confirmed byte-different as raw
  strings — normalize to the byte-identical result. This proves the normalizer is doing real
  absorption work, not just comparing already-equal input.

All three pass. The normalizer is trusted for everything below.

## 3. Method: how each surface was captured

- **Surface 2 (preview)**: `EmailRenderer::render($post, 'en', preview: true)->html` — the exact
  code path behind the `/emails/{slug}` route.
- **Surface 3 (real sent mail)**: `new HeisenbergMailable($post->id, 'en')->result` — html, text,
  subject, and the embeds manifest; the embeds were additionally attached to a real
  `Symfony\Component\Mime\Email` via the Mailable's own callbacks (the same technique
  `HeisenbergMailableTest` uses) and the resulting `DataPart`s inspected for a matching
  Content-ID, confirming the manifest is not just a paper claim.
- **Surface 1 (canvas)**: **two** methods, one pinned in the test suite and one a one-off capture,
  reported honestly as two different things:
  - *Pinned proxy* (`ThreeWaySurfaceDiffTest::test_the_render_surface_and_email_surface_diverge_exactly_where_documented`
    and its neighbours): `BlockRenderer::renderBlocks($blocks, 'en', 'render')` — the SERVER's web
    (`render.template`) surface, called directly, with **no** browser and **no** JS involved. This
    is a proxy, not the canvas itself — the task's own ground rules permit this framing when a
    real browser capture is impractical. It is used here for the divergences that are genuinely
    about `render.template` vs `email.template` (align, gradients, editor-only classes,
    embed/icon exclusion, the column cap): those are 100% determined by
    `BlockTreeRenderer`/`EmailRenderer`, and are the same whether or not a browser is involved.
  - *Real capture, one-off* (§5 below): the ACTUAL client-side JS
    (`resources/views/components/live/block-runtime/*.blade.php`) was executed against the real
    dumped `/editor/email/{post}` page for all four fixtures, via `jsdom` — the same technique this
    codebase's own `tests/js/canvas-pipeline-harness.mjs` already uses (real inline `<script>`
    execution against a real dumped page, not a hand-rolled reimplementation of the renderer).
    This is real canvas JS, not a proxy, captured without a browser process. It is **not** wired
    into the pinned test suite (see the "why not pinned" note in §5) — it was run once, by hand,
    for this report, and its output is quoted here as evidence, not asserted as a repeatable test.

## 4. Answer: does the preview differ from the real sent mail?

**No, not beyond image embedding.** Confirmed by `ThreeWaySurfaceDiffTest`, all passing:

- `test_subject_is_byte_identical_between_preview_and_real_send` — 4/4 fixtures.
- `test_plain_text_alternative_is_byte_identical_between_preview_and_real_send` — 4/4 fixtures
  (the text alternative is generated from the block tree directly by `EmailRenderer::textFor()`,
  never from the rendered HTML, and never depends on the `$preview` flag — src/Services/EmailRenderer.php:606-689).
- `test_preview_and_real_send_html_are_identical_once_image_embedding_is_normalized_away` — 4/4
  fixtures, including the two that carry no image at all (byte-identical with **zero**
  normalization needed) and the one with a media-library image (byte-identical only after
  normalizing the `src`).
- `test_the_only_raw_difference_for_a_fixture_with_a_media_library_image_is_the_src_scheme` pins
  the raw (non-normalized) diff explicitly for `media-image-sources`: the preview has
  `src="/uploads/media/2026/07/photo.jpg"` and no `cid:` anywhere; the real send has `cid:` and no
  `/uploads/` `src`; the external (non-media-library) image's URL,
  `https://cdn.example.com/hero-external.jpg`, is untouched on **both** surfaces.
- `test_every_fixtures_embeds_attach_with_the_exact_referenced_cid` confirms the embeds manifest
  is real: every `cid:` the HTML references has a matching Symfony `DataPart` with that exact
  Content-ID, `inline` disposition, and the correct mime type.

**This is the single most useful negative result this harness produced**: it rules out an entire
class of hypothesis ("what the author previews isn't what gets sent") and narrows the "emails
don't render properly" complaint down to canvas-vs-everything-else.

## 5. Full divergence inventory

Each entry: what was measured, the classification, the citation, and the evidence.

### 5.1 BUG — columns break the HTML table structure in the live editor canvas

**Severity for this report's "rank by recipient visibility" ask: highest severity found, but
*zero* recipient visibility** — this only affects what the AUTHOR sees while editing an email with
a `columns` block. The actual sent mail (and the preview) never contain this defect; see the
table-structure snippets from `EmailRenderer` output in §5.2 below, which are clean.

**What was measured.** The `column` contract's `email.template` root tag is `td`
(`resources/blocks/column/column.json`):

```jsonc
"email": { "template": { "tag": "td", "class": "hb-email-col", "attributes": { ... }, "children": [...] } }
```

The client-side renderer wraps **every** rendered block root — regardless of what tag it is — in
an unconditional `<div class="hb-blk">` selection wrapper:

```js
// resources/views/components/live/block-runtime/05-render-tree.blade.php:259-264
const wrap = document.createElement('div');
wrap.className = (depth || 0) > 0 ? 'hb-blk hb-blk--nested' : 'hb-blk';
wrap.setAttribute('data-block', model.id);
wrap.setAttribute('data-block-name', model.name);
...
wrap.appendChild(root);
```

For the web (`render`) surface, `column`'s root tag is `div` (`resources/blocks/column/column.json`
`render.template.tag`), so `<div class="hb-blk"><div>…</div></div>` is perfectly valid. For the
**email** surface, the same code wraps the column's `<td>` root in a `<div>` and that `<div>` is
then appended as a direct child of the `columns` block's `<tr>` — a structurally invalid DOM tree
(`<tr>` may only directly contain `<td>`/`<th>` per the HTML content model). Because this tree is
built via `appendChild()` calls, not by re-parsing an HTML string, the browser does **not**
retroactively foster-parent it the way it would a parsed string — it renders the invalid tree
exactly as built, which per CSS table layout rules means the `<div>` gets an anonymously-generated
table-cell box wrapped around it, with the REAL `.hb-email-col` `<td>` nested one level deeper
inside that anonymous cell. Practical effect: column width/`valign` styling on the real `<td>`
still mostly works, but the layout is fragile, non-standard, and specifically NOT what any real
mail client would ever receive.

**Captured evidence** (`nested-layout` fixture, real canvas via jsdom — see §5.6 for the capture
method):

```html
<table ...><tr>
  <div class="hb-blk hb-blk--nested" data-block="hb2" data-block-name="heisenberg/column">
    <td class="hb-email-col" ... valign="top" width="33%">...</td>
  </div>
  <div class="hb-blk hb-blk--nested" data-block="hb4" data-block-name="heisenberg/column">
    <td class="hb-email-col" ... valign="top" width="33%">...</td>
  </div>
  <div class="hb-blk hb-blk--nested" data-block="hb6" data-block-name="heisenberg/column">
    <td class="hb-email-col" ... valign="top" width="34%">...</td>
  </div>
</tr></table>
```

**Citation for "not what a mail client receives"**: `EmailRenderer`'s own output for the identical
fixture (`nested-layout.preview.html`, confirmed clean):

```html
<table role="presentation" width="100%" ...><tr>
<td class="hb-email-col" valign="top" width="33%">...</td>
<td class="hb-email-col" valign="top" width="33%">...</td>
<td class="hb-email-col" valign="top" width="34%">...</td>
</tr></table>
```

**Suggested fix** (not applied — out of scope for this harness): `renderBlockEl()`
(`resources/views/components/live/block-runtime/05-render-tree.blade.php:248-268`) should skip the
`<div class="hb-blk">` wrapper — or use a non-`div` wrapper, or apply the `data-block`/
`data-block-name`/selection affordance directly to the rendered root node — whenever `surface ===
'email'` and the rendered root's tag is `td` (equivalently: whenever the parent context is a
`<tr>`). The web surface's `wrapEl()`/selection code already has to know which nodes are
block-selectable; teaching it "don't add an extra element between a `<tr>` and a `<td>`" is a
narrow, local fix.

**Why this is real and not a jsdom artifact**: the DOM was built by the actual page's own inline
`<script>` (not reimplemented), the same technique this repo's own
`tests/js/canvas-pipeline-harness.mjs` already relies on for canvas-fidelity testing; jsdom and
real browsers implement the same DOM mutation API without content-model enforcement on
`appendChild()`, so this is not a quirk of the test harness.

### 5.2 INTENDED — `align` is dropped entirely on the email surface

**Recipient visibility: none** (there is no email-safe equivalent to fall back to — the block is
simply left-aligned/full-width like every other block, identically on preview and real send).

**Citation**: `src/Rendering/BlockTreeRenderer.php:333-341`:

> `$surface === 'email'` skips the contract-level className/classNames/align injection entirely
> (docs/email-system.md §4 defect 5) — `hb-supports`, `hb-ease-*`, `hb-flex-layout`, `hb-align-*`
> all name web-only CSS ... that has no counterpart in an inbox.

Mirrored deliberately in the client JS with an explicit cross-reference:
`resources/views/components/live/block-runtime/05-render-tree.blade.php:175-181` — "BlockTreeRenderer::resolveClass(): `surface === 'email'` skips the contract-level ...".

**Evidence** (`degradations` fixture, `group` block with `supports.align = "center"`):

- Web (`render`) surface: `class="hb-block hb-block-group ... hb-align-center"` (confirmed present).
- Email surface (both `EmailRenderer` preview *and* the real jsdom-captured canvas): no
  `hb-align-*` class anywhere in the output for that block (confirmed absent in both).

**Parity note**: this is one of the few places canvas and `EmailRenderer` are in full agreement —
verified directly against the real jsdom capture, not just the `render`-surface proxy.

### 5.3 INTENDED — a gradient background degrades to its first colour stop

**Recipient visibility: low-medium** — a recipient sees a flat colour where the author picked a
gradient. Outlook (Word rendering engine) cannot render `linear-gradient()`/`radial-gradient()` at
all, so some degradation is unavoidable; "first stop wins" is a reasonable, deliberate choice, not
an accident.

**Citation**: `tests/Email/EmailRendererTest.php:214-222` ("Bug A email degrade (2026-08-13)");
mirrored in JS with an explicit cross-reference at
`resources/views/components/live/block-runtime/05-render-tree.blade.php:191-193`: "only the
gradient-degrade rule inside it differs per surface."

**Evidence** (`degradations` fixture, `group.supports.color.background =
"linear-gradient(45deg, #ff0000 0%, #0000ff 100%)"`):

- Web (`render`) surface: `background-color: linear-gradient(45deg, #ff0000 0%, #0000ff 100%)` (or
  equivalent — literal gradient function present).
- Email surface (`EmailRenderer` preview): `background-color: #ff0000` — no `linear-gradient`, no
  `var(` anywhere in the output.
- Real jsdom-captured canvas: the block-root custom-property DECLARATION is already degraded too —
  `--hb-group-bg: #ff0000;` (not the gradient) — so the two agree on the degraded VALUE. The canvas
  still *uses* it via `background-color: var(--hb-group-bg, transparent)` rather than inlining the
  literal (see §5.4) — visually identical in a real browser (the custom property is declared, so
  the fallback is never reached), but the raw markup differs.

### 5.4 STRUCTURAL (architecture, not a bug) — the canvas keeps CSS custom properties live; `EmailRenderer` resolves everything to literals

**Recipient visibility: none directly** — the recipient only ever sees `EmailRenderer` output,
which never contains `var(` (pinned by `EmailRendererTest::test_no_var_survives_anywhere_in_the_output`).
**Author visibility: none in normal operation**, since the editor page defines the same theme
tokens (`--fs-md`, `--ink`, etc.) at a page-level `:root`/ancestor scope, so the custom-property
chain resolves correctly in a real browser.

This is the primary reason canvas HTML and `EmailRenderer` HTML are never going to be
byte-comparable even where they agree completely in substance — worth stating plainly so nobody
mistakes "the raw HTML differs" for "the block renders differently".

**Evidence**: every block in the real jsdom capture carries un-resolved custom-property
declarations and usages that `EmailRenderer` always strips, e.g. (`core-text-and-variables`,
paragraph block):

- Canvas: `style="--hb-paragraph-color: var(--ink); ...; --hb-paragraph-fs: var(--fs-md); ..."`
  then `font-size: var(--hb-paragraph-fs, 15px); ... color: var(--hb-paragraph-color, #0a0a0a);`
- `EmailRenderer` preview for the identical paragraph: `font-size: 14px; ... color: #0a0a0a` — no
  `var(`, no custom-property declarations at all (`EmailRenderer::resolveTokens()` /
  `stripCustomPropertyDeclarations()`, `src/Services/EmailRenderer.php:424-493`).

**One theoretical (UNKNOWN, unconfirmed) risk worth flagging**: the hardcoded template FALLBACK
literal (e.g. `15px` in the snippet above) does not match the CURRENT theme's actual resolved
value (`14px`, confirmed via the preview). In a real browser this is harmless as long as
`--hb-paragraph-fs`/`--fs-md` really are declared somewhere in the cascade the canvas inherits from
— which they should be, since that is the entire point of the editor's live-style system — but this
harness did not do real pixel-level browser rendering (jsdom does not apply CSS layout), so this
is reported as **UNKNOWN**, not confirmed, and would need an actual Playwright screenshot
comparison to settle either way (see §6).

Editor-only DOM chrome that is never present on any `EmailRenderer` surface and never reaches a
recipient (all confirmed absent from both the preview and the real-sent HTML):
`class="hb-blk"`/`hb-blk--nested`, `data-block`/`data-block-name`/`data-level`, and the
`<span class="hb-ce" contenteditable="true" data-hb-rt="…">` rich-text wrapper.

### 5.5 INTENDED — `embed`/`icon` render fully on the web surface, vanish entirely on email; columns cap at 3

**Recipient visibility: none** (author never sees these on the email surface either — confirmed
below, so there's no "the editor showed something the recipient never got" gap here).

**Citation**: docs/email-system.md §4 — "Excluded: embed, icon (webfont/SVG dependency)"; §4 —
"columns/column (rendered as table cells, capped at 2-3 columns)"; implementation:
`src/Services/EmailRenderer.php` (`render()`'s "no email section on this contract, skip" branch,
line 156-158; `capColumns()`/`assignColumnWidths()`, lines 194-258); JS mirror:
`resources/views/components/live/block-runtime/05-render-tree.blade.php:38-40` (`MAX_EMAIL_COLUMNS
= 3`) and `:62-` (`emailInnerBlocksFor()`).

**Evidence, all three surfaces agree** (`nested-layout` fixture — `columns` with 4 authored
column children, plus top-level `embed` and `icon` blocks):

| | web (`render`) surface | `EmailRenderer` preview | real jsdom canvas |
|---|---|---|---|
| Column 4 ("Column four text") | present | **absent** | **absent** |
| `embed` (youtube URL) | present (`<iframe>`) | **absent** (no `youtube` anywhere) | **absent** (confirmed via direct grep on the captured DOM) |
| `icon` (`feather/star`) | present | **absent** | **absent** |
| Column widths | n/a (flexbox) | `33%`, `33%`, `34%` | `33%`, `33%`, `34%` (identical) |

This is a genuine, previously-unverified claim now confirmed with real evidence on all three
surfaces at once — not just "the code says it should work", but an actual matching measurement.

### 5.6 INTENDED — `{{ variable }}` tokens render as literal text everywhere EXCEPT the editor's own rich-text chip

**Recipient visibility: none** — the recipient (via the real send) always sees the literal token,
per docs/email-system.md §6.1 ("Heisenberg does not substitute them ... passes every `{{ ... }}`
token through verbatim").

**Citation**: `resources/views/components/live/block-runtime/01-bootstrap-and-email-variables.blade.php`'s
own docblock (lines 5-15): "the EMAIL_VARIABLES lookup and its token helpers (emailVariableToken,
decorateEmailVariables, serializedEmailValue) used to render/round-trip `{{ variable }}` chips
inside rich-text fields on email documents."

**Evidence** (`core-text-and-variables` fixture, heading `content = "Hello {{ user.first_name }}"`):

- `EmailRenderer` preview / real send / plain text / subject: literal `Hello {{ user.first_name
  }}` everywhere (pinned by `ThreeWaySurfaceDiffTest` and already covered for other fixtures by
  `EmailRendererTest::test_variable_placeholders_are_passed_through_verbatim`).
- Real jsdom-captured canvas (rich-text field only):
  ```html
  <span class="hb-ce" contenteditable="true" data-hb-rt="content" data-ph="Write something…">Hello
    <span class="hb-email-variable-token" data-hb-email-variable="user.first_name"
          title="The recipient's first name">First name</span>​
  </span>
  ```
  — the VISIBLE text is the host-configured LABEL ("First name"), not the raw token, while a
  non-rich-text field carrying the same kind of token (the button's `url` attribute,
  `https://example.com/{{ tracking_id }}`) is left completely untouched, verbatim, in the canvas
  too — `decorateEmailVariables()` only touches contenteditable rich-text nodes.

This is a deliberate editor-only UX affordance (documented), and `serializedEmailValue()`
round-trips it back to the literal token when the model is written from the DOM — this harness did
not independently re-verify that round-trip end-to-end (that would require driving real user
input, not just reading a hydrated page), so treat "the stored model is always correct" as carried
over from the existing docblock's claim, not independently re-proven here.

### 5.7 INTENDED, but worth the owner's attention — external (non-media-library) images are never embedded

**Recipient visibility: medium** — this is the one finding in this report with genuine,
non-hypothetical recipient-facing risk.

**Citation**: `src/Services/EmailRenderer.php:266-271` (`rewriteImages()`'s docblock): "`src` values
that don't resolve to a `PublicFile` ... are left exactly as authored: no network fetch happens
here, and a broken/absent embed would be worse than a live URL."

**Evidence** (`media-image-sources` fixture): the external image
(`https://cdn.example.com/hero-external.jpg`) is untouched — not embedded, not rewritten — on
**both** the preview and the real sent mail (confirmed identical on both surfaces, so at least this
part is consistent).

**Why it's worth flagging anyway**: docs/email-system.md §2 states the built email "**embeds
everything — no URL paths for assets**" as an owner decision, and frames it as an absolute
("Images ride as CID MIME attachments ... never remote URLs"). That promise silently does not hold
for any image an author pastes as an external URL rather than uploading to the media library — a
recipient whose client blocks remote images by default (a common default) sees a broken image for
exactly that block, with no warning anywhere in the authoring flow that this particular image
won't be self-contained. This is not a bug in the sense of "the code does something other than
intended" — the code does exactly what its own docblock says — but it is a real gap between the
documented promise ("embeds everything") and what actually happens, worth either tightening the
doc's wording or adding an author-facing warning when a non-library image URL is used in an email
document.

## 6. What could not be measured

- **Real pixel/layout rendering.** Everything in §5 about the canvas is DOM-structure-level
  (via jsdom) or server-HTML-level (the `render`-surface proxy) — never an actual painted screen.
  jsdom does not implement CSS layout at all, so claims like "the columns bug produces a visibly
  broken layout" are inferred from HTML/CSS table-layout semantics, not observed as pixels. A
  Playwright screenshot diff (canvas vs. `/emails/{slug}` preview, side by side) would settle this
  definitively and was not attempted — `node_modules/playwright` is present and this repo already
  has a working screenshot script (`workbench/scripts/screenshots.mjs`) as a starting point, but
  driving it against these specific fixtures needs a live server + a seeded database, which this
  harness deliberately avoided (see below) to stay fast and non-flaky.
- **The rich-text variable-chip round-trip.** §5.6 quotes the DOCUMENTED claim that
  `serializedEmailValue()` converts the chip back to the literal token on save; this harness read
  a hydrated page but never drove real keyboard/DOM-mutation input through the contenteditable
  field the way `tests/js/canvas-pipeline-harness.mjs` drives Style controls, so the round-trip
  itself is not independently re-verified here.
- **Any interaction with the OTHER concurrent canvas work.** Per this task's own framing, the
  canvas is being actively changed elsewhere, and this turned out to be directly observable: at
  the start of this session `git status` was clean, but by the end of it the working tree carries
  **uncommitted** changes to `resources/views/components/live/block-runtime/{01-bootstrap-and-email-variables,03-style-sanitizers,04-render-support,05-render-tree,06-selection-support}.blade.php`,
  `resources/views/components/live/{panel-components-blocks,quick-inserter}.blade.php`,
  `resources/views/editor/index.blade.php`, `src/Http/Controllers/EditorController.php`,
  `src/Mcp/Support/PostAccess.php`, `src/Support/BlockViewData.php`, and the language files — plus
  three brand-new, untracked files that are not part of this harness:
  `src/Services/EmailBlockCoverageService.php`, `tests/Email/EmailBlockCoverageServiceTest.php`,
  `tests/Email/EmailCoverageEditorTest.php`, `tests/Email/EmailCoverageMcpTest.php` (this is
  presumably the "email-coverage warnings" work the task's ground rules mentioned — a different,
  parallel deliverable to this harness; this report does not use or duplicate it). **Everything
  this report quotes about the canvas — the RENDER_SURFACE/DOCUMENT_TYPE split, `emailInnerBlocksFor()`,
  the gradient/align parity, and the columns table-nesting bug in §5.1 — is the CURRENT, UNCOMMITTED
  state of that in-flight work, not master.** `git diff` confirms the surface split did not exist
  at all in the last commit that touched `05-render-tree.blade.php` (`renderNode()`/`renderBlockEl()`
  took no `surface` parameter whatsoever on the committed side of that diff) — so the premise "the
  canvas currently always renders the web template" was accurate for `master`, and is being fixed,
  live, by the very work this measurement happened to capture mid-flight. The columns-table-nesting
  bug in §5.1 is therefore itself likely a byproduct of that unfinished work rather than a
  long-standing defect — worth relaying to whoever owns it before it lands, not filing as a
  separate ticket against old code. Re-run this harness (`vendor/bin/phpunit tests/Email` plus the
  jsdom capture script described below) once that work is committed, since this snapshot will not
  stay accurate.
- **A live-server/Playwright capture** was deliberately not used for the routine, repeatable part
  of this harness — the in-process Laravel test client (`$this->get(...)`) plus a `jsdom` capture
  of the resulting dumped page reaches the exact same real, executing client-side JS without the
  flakiness/setup cost of a live PHP server + browser process on Windows. This is a considered
  trade-off, not a shortcut: see §3 for exactly what was captured this way and how.

### Reproducing the one-off canvas capture

Not part of the pinned suite (deliberately — see the header for why). To redo it:

```
# 1. Dump editor/preview/mime HTML for every fixture (fast, in-process, no server):
HB_EMAIL_DIFF_DUMP_DIR=/some/dir vendor/bin/phpunit --filter test_dump_surfaces_for_offline_canvas_comparison tests/Email/ThreeWaySurfaceDiffTest.php

# 2. From the PROJECT ROOT (jsdom resolves from the cwd), execute the real canvas JS
#    against each dumped page and extract the rendered block markup:
node <path-to-capture-script>/capture-canvas.mjs /some/dir /some/out/dir
```

The capture script used for this report is a throwaway (per this task's ground rules it was not
committed to the repo); it is ~90 lines, follows `tests/js/canvas-pipeline-harness.mjs`'s own
jsdom-setup pattern exactly (same `beforeParse` shims), and simply reads
`document.querySelector('.hb-page__blocks').innerHTML` after a 400ms settle once
`window.hbEditor` exists. Anyone re-running this measurement can reconstruct it from that
description in a few minutes; it is not sophisticated.

## 7. Test results

```
vendor/bin/phpunit tests/Email/ThreeWaySurfaceDiffTest.php
  21 tests, 55 assertions — OK (1 skipped: the opt-in dump test, by design)

vendor/bin/phpunit tests/Email/ThreeWaySurfaceDiffTest.php tests/Email
  151 tests, 447 assertions — OK (2 skipped, both by-design opt-in dump tests — this file's own
  and the concurrently-added EmailBlockCoverageEditorTest's equivalent)
```

Run at the very end of this session, after the concurrent canvas/coverage work (see §6) had
already landed uncommitted in the working tree — this harness's tests pass against that combined
state, alongside every other `tests/Email` test including the three new
`EmailCoverage*`/`EmailBlockCoverageServiceTest` files that are not part of this deliverable.

No existing test was modified. No file under `src/` was modified BY THIS HARNESS (see §6 for the
separate, concurrent `src/` changes this session observed but did not make or touch). The only
files this harness added are this document, `tests/Email/ThreeWaySurfaceDiffTest.php`,
`tests/Email/Support/EmailDiffFixtures.php`, `tests/Email/Support/SurfaceNormalizer.php`, and
`tests/Fixtures/email-diff/*.json`.

## 8. Summary table

| # | Divergence | Classification | Recipient-visible? |
|---|---|---|---|
| 1 | Columns break `<tr>`/`<td>` table structure in the live editor canvas for email documents | **BUG** | No — editor-only |
| 2 | External (non-media-library) images are never embedded, contradicting docs' "embeds everything" | INTENDED (by code), doc/expectation gap | **Yes — medium** |
| 3 | Gradient background degrades to its first colour stop on the email surface | INTENDED, documented | Yes — low/medium |
| 4 | `align` is dropped entirely on the email surface | INTENDED, documented | No |
| 5 | `embed`/`icon` blocks vanish entirely on the email surface; `columns` caps at 3 | INTENDED, documented, verified on all 3 surfaces | No |
| 6 | `{{ variable }}` tokens show as a friendly label "chip" in the canvas rich-text editor only | INTENDED, documented | No |
| 7 | Canvas keeps `var(--hb-*)` custom properties live; `EmailRenderer` resolves to literals everywhere | Structural/architectural | No (browser-resolved) |
| 8 | A template's hardcoded `var(x, fallback)` literal could theoretically drift from the live theme's resolved value | **UNKNOWN** — plausible, not confirmed | Unconfirmed |
| 9 | Preview HTML/text/subject vs. real sent MIME payload | **No divergence** beyond image src (cid vs URL) — confirmed, pinned | N/A |

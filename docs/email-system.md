# Email system - design & build plan

Status: **as-built** (2026-08-12: the editor + renderer waves landed and verified; 2026-09-17: the host-integration layer was removed, see §6). Companion docs: `docs/block-schema.md` (contracts grow an email surface), `docs/content-translation.md` (emails translate like posts).

## 1. The decision: one builder, two render targets

Emails are authored in THE SAME editor with the same block engine - a separate email builder would duplicate the inspector, media library, AI assistant, revisions, translations and Code view for no authoring benefit. What differs is the OUTPUT: email clients (Outlook renders with Word's engine) allow no flexbox/grid, no CSS custom properties, no external stylesheets, no animations - table-based markup with inline styles at ~600px is the only reliable target. So the system is: a restricted, email-safe block palette feeding a dedicated `EmailRenderer`, beside the existing web pipeline.

## 2. Self-contained output (owner decision)

A built email **embeds everything - no URL paths for assets**:

- **Images ride as CID MIME attachments** (`cid:` references), never remote URLs and never base64 data-URIs (Gmail/Outlook strip those). The render result carries an embeds manifest; the bundled Mailable attaches each part. Embedded images display even with remote-image blocking on and make no callback to the host.
- The renderer embeds the email-appropriate **variant** of each image (the widest ≤600px variant, falling back to the original only when no variant exists) - originals would bloat the message and Gmail clips large mails.
- **Fonts cannot be attached**: theme font tokens resolve to email-safe stacks (`Arial, Helvetica, sans-serif` class of fallbacks derived from the theme's families).
- **All CSS is inlined**; the only `<style>` block is a small head section for client hacks/dark-mode hints that inlining cannot express.
- **Hyperlinks are not assets**: buttons/anchors keep their `href`s. Only loaded resources are embedded.


### 2.1 Theme fonts in email (2026-09-23)

A theme font becomes a stack that LEADS with the author's real family and falls back to web-safe
names (`'Space Grotesk', Arial, Helvetica, sans-serif`), and the message links the theme's faces,
so Apple Mail and iOS Mail render the chosen font while Gmail and Outlook use the fallback they
always got.

Until 2026-09-23 the family never reached the message at all, and the fallback was chosen by
keyword-matching the TOKEN's name (`serif` -> Georgia) rather than the font's own catalog
category. A token named `font-serif` holding a sans family therefore shipped a serif: picking any
theme font but the first visibly changed the email into something unrelated to the canvas. Pinned
by `tests/Email/EmailFontStackTest.php`.

## 3. Email documents

An email is a post row with `type = 'email'` (new `type` string column on the posts table, default `'post'`). That buys revisions, autosave, locking, translations (split-row, shared slug) and AI authoring for free. Consequences, enforced in code:

- Emails NEVER appear in: the sitemap, the public translations API's guest surface only-if-published logic still applies but hosts querying posts for blogs must scope - the package adds `scopePosts($q)` / `scopeEmails($q)` and uses `posts()` itself everywhere IT lists content (sitemap, MCP `list_posts` gains a `type` arg defaulting to 'post').
- Lifecycle: same statuses; "published" for an email simply means "ready to send" - sending is the host's act, not a Heisenberg state.
- Comments/TOC/SEO panels are meaningless for emails; the editor hides them for `type = 'email'` (wave E3).

## 4. Contract surface: `email` render section

A block opts into the email palette by declaring an `email` section in its contract:

```jsonc
"email": {
  "template": { /* table-based render tree, same substitution engine as render.template */ }
}
```

- Presence of `email` = the block appears in the email palette; absence = it does not. Initial email-safe set: heading, paragraph, image, button, separator, group (as a full-width table section), columns/column (rendered as table cells, capped at 2-3 columns), list, quote. `icon` joined that set once its glyph could ship as a raster image (§4.2). Excluded: embed (a webfont/iframe player has no email equivalent), and every animation/hover capability (ignored by the email renderer even if authored).
- `BlockContractValidator` validates the section (template shape identical to `render.template` rules); `BlockRegistryService` exposes surface filtering (`contractsFor('email')`).

### 4.2 An icon ships as a rasterized PNG (2026-09-23)

No mail client renders SVG, which is why `icon` was excluded from the palette outright. It now
ships as a PNG instead, and the conversion happens in the BROWSER:

- **Why not in PHP.** Rasterizing SVG server-side needs Imagick with an SVG delegate; GD cannot do
  it at all. Neither is present on every host, and neither is a dependency a package can force on
  one. The editor already has the glyph, its colour and its pixel size on screen.
- **The editor** (`inspector/script-email-icons.blade.php`, email documents only) serializes the
  glyph with `currentColor` replaced by the resolved colour, draws it to a canvas at 2x, and posts
  the bytes to `POST /editor/email-icon`.
- **The endpoint** (`EmailIconImageController`) distrusts all of it: the icon must be a
  manifest-listed `<set>/<slug>`, the colour a plain hex, the size 8-512px, and the payload must
  decode to a real PNG of exactly 2x those dimensions, under a byte cap. The stored name is
  DERIVED from those validated parts - `email-icons/<set>-<slug>-<hex>-<size>.png` - so the same
  icon at the same colour and size is written once and reused by every block, document and send.
- **These are generated artifacts, not uploads**, so they have no media-library row: nothing
  generated appears in the author's Media panel. `EmailRenderer::rewriteImages()` embeds them
  anyway, through the one extra branch that recognizes that directory (and only that directory,
  by exact name shape, on the media disk).
- **The block** keeps the URL in a hidden `emailImage` attribute; its `email.template` renders a
  plain `<img>` that the renderer turns into the same inline `cid:` part an uploaded image gets.
  An icon with no PNG yet renders NOTHING rather than a broken image, and
  `EmailBlockCoverageService` flags it (`icon-not-rasterized`) so the author hears about it
  before the send rather than after.

Pinned by `tests/Email/EmailIconTest.php` (render + embed + the directory guard) and
`tests/Editor/EmailIconImageControllerTest.php` (what the endpoint refuses).

### 4.1 One canvas; the email is an EXPORT (2026-09-21)

**An email document is edited on exactly the same canvas as a post** - the same `render.template`, the same block CSS, the same DOM, the same inspector. `block-runtime`'s `RENDER_SURFACE` is always `'render'`. Two things make a document an email, and neither is the canvas:

- **the palette** - a contract with no `email` section (embed) is not offered;
- **the export** - `EmailRenderer` walks each block's `email.template` in PHP and produces table markup with literal inline styles.

The canvas used to draw `email.template` itself, which made the editor a second, table-based renderer under the same chrome. Every email-only editing defect came from that DOM being different: nested outlines, a zero-size toolbar anchor, inspector controls with nothing to bind to, drops into a container landing at index 0. `tests/js/email-canvas-parity.mjs` now loads the same blocks as an email and as a post and requires the two canvases and the two Style tabs to be identical.

The accepted cost: the canvas is not a pixel preview of the mail. What a mail client cannot render (shadow, opacity, interaction states, animation) shows while editing and is dropped from the send; the preview tab shows the real output, and `EmailBlockCoverageService` flags documents that use such features.

**How the export honours the inspector.** An email template reads the block's values through `var(--hb-<block>-<prop>, <fallback>)` slots written into its `style` attributes. Mail clients have no custom properties, so on this surface a `var()` is a substitution slot, not CSS:

- **Filled per block** by `BlockStyleCompiler::resolveEmailVars()`: a slot naming one of the contract's own `style.variables` takes that block instance's sanitized value, or the slot's fallback when the instance sets nothing (fallbacks may nest). A slot naming anything else - a design token such as `var(--ink)` - is left for `EmailRenderer::resolveTokens()`. No custom property is declared on this surface. Resolving once per rendered fragment, as it used to, let the last block in a container overwrite its siblings (two paragraphs in a group both shipped the second one's colour).
- **Layout becomes table cells.** A container's template lays its children out with an `inner-blocks` node carrying `"flow"` (`"blocks"` for group/column, `"cells"` for columns, whose children already are `<td>`s). `BlockTreeRenderer::renderEmailFlow()` turns direction into stacked children vs one cell per child (stacked columns become one full-width row each), gap into a spacer row or cell, and space-between/around into a full-width row. Justify/align become the container cell's `align`/`valign` with flexbox's own axis swap (`BlockStyleCompiler::emailLayout()`), handed to the template as the render-pass-only `_emailAlign`/`_emailValign` attributes because a template cannot branch on direction. Text blocks use `text-align: inherit` and an unaligned button emits no `align`, so a container's alignment reaches its children; the shell's content cell anchors the default to left.
- **Template shape.** Blocks share a frame: an outer *margin cell* (margins ship as cell padding; clients drop margins on tables) around an inner *box cell* that owns background, padding and border, so a background never bleeds into the block's own margin.
- **A centred column shrinks its children to their content, like flexbox.** A child with no explicit width, inside a column whose `layout.align` is set, sits at that edge instead of stretching across the row - a "pill" badge (a nested group with a background and no width) is pill-sized and centred rather than a full-width bar. The child's own `align` wins over the container's, mirroring a flex item's own override. Images, separators, columns and column keep their full-width structure (an email image is sized to its column; columns/column own their split) and are only positioned, never shrunk. `_emailHug` is the render-pass-only hint carrying this.
- **A nested block's own "vertical rhythm" is suppressed, not stacked.** A top-level text block (paragraph, heading, list, quote, button, image, separator) bakes a fixed bottom-spacing default into its own margin cell, because email has no reliable CSS margin for a document-root block to lean on. That default is for a block with nothing else spacing it from its neighbour - nested inside a container, the container's own `gap` is that mechanism instead (exactly mirroring the web canvas, where a flex `gap` spaces siblings and a nested block's margin defaults to 0), so the default is zeroed via `_emailNested` for every block a container lays out. An explicit margin the author actually sets still resolves and wins regardless of nesting; only the invisible fallback is suppressed.
- `Heisenberg\Support\EmailSupports::for($contract)` answers "which supports does this block's email template honour" by reading the template (it is derived, never declared); `EmailBlockCoverageService` uses it to decide what counts as degraded.
- **A hand-written inline style is degraded too.** `RichTextSanitizer` keeps only `color`/`background-color` on a rich-text `<span>` - fail-closed, by design - so a `<span style="padding:...;border-radius:...">` pasted into a paragraph's text loses everything but colour on every real render, web or email. The canvas never sanitizes at all (it writes stored HTML straight into the DOM), so this only ever surfaces as "it looked right while editing." `EmailBlockCoverageService` flags it (`'inline-style'` reason) by regex-scanning a rich-text attribute for a `<span style>` declaring anything outside those two properties, and the AI prompt says to use a styled block instead.

Pinned by `tests/Email/EmailInspectorParityTest.php` (export, incl. the shrink and rhythm-suppression cases), `tests/Email/EmailBlockCoverageServiceTest.php` (the inline-style warning) and `tests/js/email-canvas-parity.mjs` (canvas).

## 5. `EmailRenderer` (beside `BlockRenderer`, never replacing it)

`render(Post $email, string $locale): EmailRenderResult` where the result is `{html, text, subject, embeds: [{cid, path, mime}], sizeBytes}`:

1. Renders each block's `email.template` through the SAME substitution/sanitization engine, filling every per-block style slot with a literal as it goes (§4.1).
2. Resolves every theme token to its literal value (no `var()` in output); fonts to stacks (§2).
3. Wraps content in the canonical shell: 100%-width background table -> centered 600px content table, theme background/text colors applied literally.
4. Rewrites every image source to a `cid:` reference and records the embed (variant selection per §2).
5. Inlines all styles; leaves only the minimal head `<style>` (§2).
6. Generates the plain-text alternative from the block tree (headings, paragraphs, list markers, button label + URL in parentheses).
7. `subject` = the email's `title($locale)`.

## 6. Host seam (Heisenberg renders; the HOST sends and substitutes)

The author writes an email document exactly like a post - blocks, media, translations, revisions, publish lifecycle. `EmailRenderer::render()` produces a self-contained MIME-shaped payload (`{html, text, subject, embeds, sizeBytes}`) ready to hand to any mailer. The bundled `HeisenbergMailable` is the convenience seam for Laravel's mailer; a host that ships its own mailer consumes `EmailRenderResult` directly.

### 6.1 Placeholders are the author's job, the host's job, never Heisenberg's

The author writes `{{ variable_name }}` placeholders directly into the email's text - in headings, paragraphs, button labels, button URLs, alt text, anywhere a block accepts text. **Heisenberg does not substitute them.** The renderer passes every `{{ ... }}` token through verbatim into `html`, `text`, and `subject`. The host's own newsletter / mailing-list integration reads the rendered text and substitutes real values at send time, the same way it would for any templated outbound mail.

This is the entire author-facing model:

- Author types `Hi {{ user.first_name }}` in a heading block.
- `EmailRenderer::render()` produces HTML containing the literal `Hi {{ user.first_name }}` - the `EmailRenderResult` is byte-for-byte identical to what the author typed, with every other block-level transformation (image -> cid, theme tokens inlined, shell wrapping, plain-text generation) applied normally.
- The host reads that rendered text, runs its own substitution (`str_replace`, regex, Blade, whatever it already uses for templated mail), and ships the result through its own transport - Laravel mail, an ESP API, a queue worker, a third-party service.

Heisenberg does not own variable values, formatters, users, or substitution. A host may provide metadata in `config('heisenberg.email.variables')` so the builder can recognize and label tokens. Each entry has a `key` plus optional `label`, `description`, and `group`, for example:

```php
'email' => [
    'variables' => [
        ['key' => 'user.first_name', 'label' => 'First name', 'group' => 'User'],
        ['key' => 'unsubscribe_url', 'label' => 'Unsubscribe URL', 'group' => 'Links'],
    ],
],
```

This metadata is editor-only. Heisenberg stores and exports the literal `{{ user.first_name }}` text unchanged; the host platform decides what the token means and how to substitute it at send time. Unknown or malformed metadata is ignored, and an email builder visually marks only tokens listed by the host.

### 6.2 Why the renderer never URL-encodes or otherwise mangles the tokens

PHP's `DOMDocument::saveHTML()` normalizes `href` and `src` attribute values through `rawurlencode` semantics - turning `{{ unsubscribe_url }}` into `{{%20unsubscribe_url%20}}` silently. That would mangle any placeholder the author put inside a URL field (a button's `url` attribute is the most common case) and make the host's substitution step impossible.

`EmailRenderer::inlineStyles()` therefore short-circuits the DOMDocument round-trip entirely when the rendered HTML contains any `{{` token. The email shell's only `<style>` rule is the columns-block mobile `@media` query, which `CssToInlineStyles` never actually inlines anyway (its own `doCleanup()` strips media queries from the inlined output and leaves them in the retained `<style>` tag) - so the only thing the round-trip was doing for placeholder-bearing emails was the URL-mangling damage. When placeholders are present, the renderer skips the round-trip and just appends the Outlook/iOS client-hack CSS to the shell's `<style>` tag by regex, identical to what the library would do after `convert()`.

The behaviour for token-free emails is unchanged - they still go through the full `CssToInlineStyles::convert()` round-trip exactly as before.

### 6.3 What the host owns

Same posture as users/comments-before-native: no subscriber lists, no campaign scheduling, no SMTP config in Heisenberg. No `RoleGate` tier for batch generation - the route does not exist. No `PostPolicy::generateEmailBatch` ability. No admin POST endpoint that produces a zip of N personalized files. No `examples/EmailVariables/` starter pack. None of those concerns are Heisenberg's, and adding them back later means adding them on top of this surface, not under it.

A host that needs to send to N recipients wires that into its own application - reads the email post (`Post::query()->emails()->where('type', 'email')->where('status', 'published')`), calls `EmailRenderer::render($post, $locale)` once per recipient with whatever substitution logic it owns, hands the resulting `EmailRenderResult` to whatever mailer it already uses. A host that wants a CSV-driven batch export writes that as a Laravel artisan command in its own codebase; the command reads the email, iterates its recipient list, and ships.

## 7. Out of scope (recorded) & cross-references

Subscriber management, campaign **sending**/scheduling/tracking, SMTP configuration inside Heisenberg, open/click analytics, MJML interop, per-client conditional comments beyond the minimal Outlook shims the shell needs, and variable values, formatters, recipient contexts, substitution, and batch sending. The optional variable metadata list and editor-only visual markers are the only Heisenberg-side variable surface; all runtime meaning and delivery remain on the host side of the seam.

For the bundled Mailable / preview / single-document HTML+EML export, see `src/Mail/HeisenbergMailable.php` and `src/Http/Controllers/EmailPreviewController.php`.
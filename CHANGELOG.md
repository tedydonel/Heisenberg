# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) for tagged releases.

## [Unreleased]

### Changed

- **The AI now always uses the theme's fonts.** The system prompt told it to set only what the user asked for, and nobody asks for a font, so blocks rendered in the editor's default face. When the theme defines fonts, the prompt now requires one on every heading, paragraph, list, quote and button (one for headings, one for body).
- **The font variable popup no longer offers "Default" when the theme defines fonts**, and the theme-variable popups no longer carry a search field.

### Fixed

- **A title set by the AI still showed "Untitled post".** The title write reached the canvas heading without the input event that clears its placeholder and syncs the inspector field and tab title.
- **The theme-variable color popup showed blank swatches.** Colors went through the same length unit-stripper as fonts (`#0a0a0a` became empty).

- **Editing a duplicated (or newly added) block changed a different block, and the selection border landed on the wrong one.** Loading a document kept its stored block ids but never advanced the id counter past them, so the next duplicate, insert or pattern was handed an id already on the page, and every write that resolves a block by id hit the original. Incoming ids are now kept only while unique, the counter moves past them, and a stored document that already carries a duplicate id is repaired on load.

- **Theme fonts were never applied on the editor canvas**, whether set from the inspector or by the AI. The block's `font-family` resolved to the theme family correctly, but the canvas font loader skipped every `var(--hb-t-…)` value, so the face was never downloaded and the text painted in the fallback font. The loader (and the font-weight list) now reads the family back off the live theme variable, and reloads after a theme edit.
- **Picking a theme font in the inspector changed the label but never wrote the block.** The token pick only repainted the combobox; it now fires the change that reaches the block model.
- **A font or font size bound to a theme token showed its raw `var(--hb-t-…)` text in the inspector** instead of the family name or the size. Font families were run through the length unit-stripper (which returns nothing for a name), and font-size and radius tokens were missing from the panel's resolved-value map. Binding a font size also offered the spacing scale; it now has its own popup with the theme's font sizes.

## [0.0.8] - 2026-09-22

See [`UPGRADING.md`](UPGRADING.md) for what these mean for an existing install. Three of them are
breaking for some hosts: **email HTML has a new structure**, **MCP / AI writes that contain markdown are now
rejected**, and **posts saved with the old six device-visibility toggles no longer hide on those devices**.

**Highlights.** Emails are edited on the same canvas as posts, and the mail that is sent now honours what the
inspector sets; the AI assistant stops stacking a "Thought for 1s" block per block and stops writing markdown and
`\n` into your text; the inbound MCP server speaks the real Streamable HTTP transport; and a security pass closes
several open endpoints.

### Security

- **Livewire media library authorization.** The `media-library` component now authorizes `media.viewAny` before listing or selecting files, matching `MediaLibraryController`; previously only upload and delete were gated.
- **Saved-pattern endpoints now require authorization.** `/editor/patterns` (list / save / delete) had no role check at all, so with the default `['web']` middleware an anonymous visitor could write arbitrary JSON and delete every pattern. All three actions now require the `authors` tier (with the usual local-dev bypass), and a pattern's `blocks` payload is capped at 512 KB.
- **SSRF guard for outbound AI/MCP requests.** New `OutboundUrlGuard` vets MCP server URLs and custom provider base URLs on save and again before every request. Private networks are allowed automatically only in `local` (`heisenberg.ai.outbound.*`), link-local / cloud-metadata ranges are always blocked, disguised host forms (bracketed IPv6, decimal/hex/short IPv4, `*.localhost`) are normalized or refused, and these requests no longer follow redirects.
- **SVG upload guard.** `.svg`/`.svgz` uploads are refused unless a `SvgSanitizer` is bound (`heisenberg.media.svg_sanitizer`); `.svgz` is inflated incrementally under a hard size cap.
- **Anonymous-bypass warning.** A throttled log warning fires while the local-dev anonymous bypass is active, escalated when `APP_DEBUG` is false.

### Added

- **Email documents are edited on the same canvas as posts.** The canvas, its CSS, selection, drag-and-drop and the inspector are identical; only the palette (`embed` and `icon` are not offered) and the export differ. The canvas no longer draws `email.template` itself, which was a second, table-based renderer under the same editor chrome and the root of every email-only editing defect (missing nested outlines, a zero-size toolbar anchor, inert inspector controls, drops landing at the top of a container).
- **The sent email honours the inspector.** Every email-safe style (colour, background, typography, padding, margin, border, radius, width, alignment) now reaches the markup that is sent. Before, the email templates read four variables while the Style tab wrote about thirty, so most edits changed the canvas and nothing else. Blocks share a frame — an outer margin cell around an inner box cell — so a background never bleeds into the block's own margin.
- **Layout becomes table cells in email.** Direction, gap, the alignment grid and space-between/around on a group, columns or column are translated to what a mail client has: stacked children or one cell each, spacer rows and cells, and `align`/`valign` with flexbox's own axis swap. A centred column also now shrinks children with no explicit width to their content and places them, as flexbox does — a "pill" group is pill-sized, a button sits in the middle — where it used to stretch them across the row.
- **`EmailBlockCoverageService`** warns in the editor when a block will not survive the send (`embed` and `icon` have no email template; a gradient collapses to its first colour; `wide` / `full` alignment is dropped). Derived from the live contracts, so it cannot rot when a block is added.
- **`EmailBlockCoverageService`** now also warns when a rich-text field contains a hand-written `<span style="...">` beyond `color`/`background-color` (padding, border-radius, font-size, letter-spacing, ...) — `RichTextSanitizer` strips it on every real render, web or email, but the editor canvas writes stored HTML straight into the DOM without sanitizing it, so a hand-built "pill" using inline CSS looks right while editing and is silently flattened to plain coloured text everywhere else. The AI prompt now says so directly, and points at a styled block instead.
- **MCP Streamable HTTP transport** (2025-06-18) for the inbound server: `initialize` negotiates the protocol version, and it can now be registered in an assistant that speaks the spec. The tool catalogue is unchanged.
- **Externally-authored changes show live.** When an MCP client edits a post that is open in the editor, the tab picks it up (`GET /editor/posts/{post}/live-status`, polled) instead of showing nothing until a manual reload.
- **AI assistant.** Reasoning streams into per-burst "Thought for Ns" sections; the composer's model picker now actually switches models; chat history is a real table; the model is taught heading structure and translated titles; and `search_web` returns real, dated results (and fails loudly instead of silently).
- **`heisenberg.site_url`** (`HEISENBERG_SITE_URL`) names the public site. Heisenberg is often mounted in an admin dashboard on its own subdomain, so the request host says `admin.example.com` while readers are on `example.com`; SEO URLs no longer guess, and the editor topbar's home button — a bare `<button>` with no `href`, no handler and no effect at all — now actually links to it (inert, as before, only when neither `site_url` nor `app.url` is set).
- **Demo host app** under `workbench/` with an end-to-end adopter-path test (`tests/Demo`), screenshots, and `docs/demo.md`.
- `UPGRADING.md`, `docs/ARCHITECTURE.md`, and `docs/STATUS.md`; the frozen `TODO.md` / `CODE_REVIEW.md` moved to `docs/archive/`.
- CI: explicit Laravel 11 / 12 / 13 matrix, Larastan (baselined), Pint, `composer audit`, Dependabot, and the jsdom + Playwright JS harnesses. `composer test:parallel` runs the suite under paratest.
- Editor stylesheet caching: versioned URLs with `immutable` caching, ETag / `304` revalidation otherwise (was `no-store`).
- Autosave skips saves whose content is unchanged and backs off for very large documents.

### Changed

- **Internal structure, no behavior change.** `McpToolRegistry`, `BlockRegistryService` and `BlockRenderer` are now thin façades with unchanged public APIs over `src/Mcp/`, `src/Blocks/` and `src/Rendering/`; `block-runtime.blade.php` is a table of contents over 13 ordered partials emitting a byte-identical page. Pinned by a tool-catalogue snapshot test and a renderer golden-output corpus. Hosts that extended these classes by subclassing and overriding private/protected methods should re-check their overrides.
- **Visibility toggles are three cumulative device bands** — Mobile (<768px), Tablet (768–1023px), Desktop (≥1024px) — instead of six exclusive Bootstrap bands, and they now work in the editor's device preview (the old rules were viewport media queries, which the device switcher cannot trigger). The editing-language control moved onto the canvas badge.
- The AI panel's reasoning sections follow one rule: a block landing on the canvas cuts the thought, a thought becomes its own section only once that burst has itself run 5s, and anything shorter folds into the section before it. Saved conversations now keep their "Thought for Ns" time.
- **AI and MCP writes are checked for markdown.** Markdown lists, `#` headings, `**bold**` and code fences in a text field are rejected with a line-numbered message naming the fix (a `list` block, a heading tag, `<strong>`), and nothing reaches the canvas until the model resends real blocks. Hand-typed Code view is unaffected.
- Icon blocks no longer re-read the icon manifest from disk on every render.
- Code style is now enforced by Pint (config tuned to the existing conventions) and static analysis by Larastan with a baseline.
- `heisenberg_posts.locale` is a plain string column instead of a database ENUM; the supported locales are an application concern (`heisenberg.locales`).
- With the bundled public route enabled and no `seo.url_template`, sitemap / canonical / hreflang URLs now use the public route instead of the editor preview.
- `orchestra/testbench` dev constraint widened to `^11.0`. The package advertised Laravel 13 support that could not previously be installed, so the suite had never run on it; it now does, on 13.32, and CI runs a real 11 / 12 / 13 matrix in parallel (~5 min per lane instead of ~33).
- Test suite hygiene: CSRF is disabled through one version-agnostic helper (Laravel 13 renamed the middleware, which silently turned 30 files' `withoutMiddleware()` calls into no-ops), and the file-backed theme / saved-theme / AI settings / AI credential stores get a fresh temp directory per test instead of sharing the Testbench skeleton's `storage/` with `testbench serve`.

### Fixed

- **A model's `\n` is a line break.** Models write `\n` inside quoted shortcode values and tag bodies because every JSON-shaped language spells a line break that way; the dialect never defined it, so a whole list arrived as one item with visible backslash-n characters. `\n`, `\r\n` and `\t` are now read as whitespace, in both the server and browser parsers; a real backslash is written `\\` and is unchanged.
- The assistant panel no longer stacks a "Thought for 1s" section per block. Reasoning that a model inlines as `<think>` tags used to be re-read whole on every text chunk, opening a new section holding a copy each time; and a clock based on time since the last block counted the wait between tool-loop rounds as thinking.
- **Drag-and-drop into a container** always landed at the top of email containers, and now finds the container's children at any depth and orders side-by-side children by x.
- Nested-block hover and selection outlines: a comment closed early in `35-blocks.css` made the rule after it parse as garbage.
- The quoted-font stack in email (`'Times New Roman'`) was torn apart at its escaped quote and shipped as `' Times New Roman'`, a family no client matches.
- Email export: sibling blocks in a container no longer overwrite each other's values (two paragraphs in one group both shipped the second one's colour).
- **A nested block's own bottom-spacing default no longer stacks on its container's padding.** Every text block bakes a fallback "vertical rhythm" (12px heading, 16px paragraph, etc.) into its own margin cell, needed because email has no reliable CSS margin — but it fired even when the block was inside a container that already owned spacing via padding and gap, adding invisible extra space with no equivalent on the web canvas (where a nested block's margin defaults to 0). A block nested in a container now relies on the container's gap instead; an explicit margin the author sets still applies regardless of nesting.
- Translating no longer overwrites the source locale; headings load in the locale being edited; the post title no longer overflows a phone screen.
- **Bundled public route and single-row bilingual posts.** `/posts/{locale}/{slug}` now serves the same row at every locale it has content in, renders in the URL's locale, and 404s for untranslated locales — previously the page's own hreflang alternates pointed at URLs that 404'd.
- The editor "Preview" bar no longer renders on the public post page.
- The editor save request validates `locale` against `heisenberg.locales` instead of a hardcoded pair.
- Removed a `heisenberg-assets` publish entry for a `resources/js` directory that does not exist.

## [0.0.7] - 2026-09-19

### Added

- **Internet Search Tool (`search_web`).** AI assistant and MCP clients can now search the web for up-to-date information, news articles, and direct image links with dimensions and attribution across DuckDuckGo, Openverse, Wikimedia Commons, and Wikipedia.
- **Image URL input in Inspector.** Added an external image link input field on the image block (`heisenberg/image`) Content tab, fully synchronized with block attributes and the Media Library dialog.

### Changed

- **AI prompt compression.** Compressed the core editor system prompt by ~27% (~3,800 characters) to optimize response latency and token usage without losing layout discipline or translation rules.

### Fixed

- Fixed dynamic scheduled date handling in timezone roundtrip test suite.
- Namespaced email document summary metric to `email_blocks` to avoid collisions with legacy post summary rows.

## [0.0.6] - 2026-09-19

### Added

- **SEO & Social Live Previews.** Wired end-to-end SEO and Social panel with live Facebook and X preview cards and rounded circular SEO score progress bar.

### Changed

- Updated AI layout guidance to default to vertical stacking for container blocks (group/column).

### Fixed

- Restored standard `x` close icons across the application layout.
- Cleaned up documentation, updated email system integration instructions, and removed em-dash usage.

## [0.0.5] - 2026-08-27

### Fixed

- **CSP nonce helper called wrong Vite method.** `heisenberg_csp_nonce()` was calling
  `Vite::useCspNonce()` which generates a fresh random nonce on every call, causing a
  mismatch with the host's CSP header. Changed to `Vite::cspNonce()` which reads the
  nonce the host already stored.

## [0.0.4] - 2026-08-27

### Added

- **CSP nonce support for inline styles and scripts.** Heisenberg now reads the CSP nonce from
  `Vite::useCspNonce()` (when available) and adds `nonce="..."` to every inline `<style>`,
  `<script>`, and `<link rel="stylesheet">` tag. This allows Heisenberg to work correctly in
  apps that enforce nonce-based Content Security Policy without requiring `'unsafe-inline'`.
- README now includes CSP nonce setup instructions for Laravel apps using Vite.
- README now includes a complete email system quick-start guide with code examples for
  registering variables, sending via the mailable, rendering directly, and using the admin
  batch ZIP export.

### Changed

- All Blade templates updated to emit `nonce="{{ heisenberg_csp_nonce() }}"` on inline
  `<style>`, `<script>`, and `<link rel="stylesheet">` tags.

## [0.0.3] - 2026-08-27

### Fixed

- **Blade component namespace collision.** Heisenberg's anonymous components are now registered
  under the `heisenberg::` namespace prefix, preventing host application components (e.g.,
  generic `ui.tabs`, `ui.button`) from shadowing the editor's own components. All templates
  updated to use `<x-heisenberg::ui.*>` and `<x-heisenberg::live.*>`.
- Hard-coded `/heisenberg-assets/` URLs in the editor layout replaced with named route helpers
  (`route('heisenberg.editor.asset.css')`, `route('heisenberg.editor.asset.logo')`) so the
  editor works correctly when deployed behind a URL prefix.

## [0.0.2] - 2026-08-27

### Added

- **Host-defined email personalization (E5).** Host applications can register dotted variable
  keys, typed formatter contracts, safe non-secret samples, and arbitrary runtime value objects.
  Six built-in types ship (`text`, `url`, `email`, `number`, `boolean`, `date`).
- Strict context-aware email interpolation before rich-text sanitization / URL filtering, with
  value-free aggregated errors for unknown tokens, missing values, formatter failures, and target
  mismatches.
- Per-recipient `EmailRenderer` and `HeisenbergMailable` seams covering MIME subject, HTML, plain
  text, size accounting, and CID embeds while preserving legacy token-free calls.
- Sample-only public/editor preview, size, and single HTML/EML export. Runtime maps are never read
  from author-facing GET query strings, request bodies, or headers.
- Email-only authoring picker for subject, rich text, compatible text settings, and URL settings;
  literal `{{ dotted.key }}` insertion uses text nodes / `setRangeText`, never `innerHTML`.
- Admin-only, all-or-nothing batch ZIP export (`email.generate`, `admin` by default) producing
  exactly N recipients × requested locales as HTML or EML. Recipients are explicit value maps;
  Heisenberg still does not own SMTP, subscribers, campaigns, or recipient discovery.
- Email personalization usage documentation and compile-checked host examples under
  `docs/email-personalization.md` and `examples/EmailVariables/`.
- Email documents on their own editor and public slug surfaces, including ESP-ready HTML and
  self-contained EML export.
- Native threaded comments, moderation, a public thread API, and shared-surface route wiring.
- SEO persistence, scoring, sitemap, hreflang, and a host opt-in public post route.
- Single-row multilingual content: one post carries every configured locale instead of separate
  translation rows.
- User-saved block patterns, quick-inserter browse-all flow, nested/per-state font loading,
  gradient values, number-stepper UI, and expanded column/inspector controls.
- `heisenberg:warm` to precompile shipped Blade views.
- Deep published-config merging plus `heisenberg:config-diff` for host drift inspection.

### Changed

- Composer now declares the mail/MIME/CSS-inlining packages the email system imports.
- The editor inspector is split below Livewire's regex ceiling and shared component styles are
  emitted once through the editor CSS bundle.
- Theme variables support explicit update/create flows and live inspector refresh.
- Published post routing, translation preview/export, sitemap, AI translation, and Code view now
  follow the single-row locale model.
- Revisions rows can render without a disclosure chevron when they open dialogs rather than nested
  sections.

### Fixed

- Autosave no longer reverts queued status, slug, or publication date; schedule/publish timestamps
  no longer drift with the viewer timezone.
- Email rendering now matches authored content, including translated block attributes, responsive
  featured images, safe URL handling, and sample/runtime variable boundaries.
- Discussion settings now target the actual toggle input rather than its label wrapper.
- Disabled commenting freezes new posts while retaining existing thread data for host consumers.
- Duplicated blocks receive fresh IDs so edits target the copy.
- Navigator selection/drag behavior, child toolbar targeting, column flex-basis, heading
  specificity, icon picker theming/search chrome, and multi-word font family quoting.
- AI assistant icon lookup, translation preservation, prompt-size limits, and fresh-open composer
  height.
- SEO score classification, checklist status colors, and sitemap locale assumptions.
- Server-side rescue prevents locale-specific saves from overwriting bare source attributes.

## [0.0.1] - 2026-08-10

First public release: block editor, media library, taxonomy, post templates, canonical role gates,
AI writing assistant, MCP integration, revisions, autosave, and host-owned rendering seams.

[Unreleased]: https://github.com/tedydonel/Heisenberg/compare/v0.0.7...HEAD
[0.0.7]: https://github.com/tedydonel/Heisenberg/compare/v0.0.6...v0.0.7
[0.0.6]: https://github.com/tedydonel/Heisenberg/compare/v0.0.5...v0.0.6
[0.0.5]: https://github.com/tedydonel/Heisenberg/compare/v0.0.4...v0.0.5
[0.0.4]: https://github.com/tedydonel/Heisenberg/compare/v0.0.3...v0.0.4
[0.0.3]: https://github.com/tedydonel/Heisenberg/compare/v0.0.2...v0.0.3
[0.0.2]: https://github.com/tedydonel/Heisenberg/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/tedydonel/Heisenberg/releases/tag/v0.0.1

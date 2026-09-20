# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) for tagged releases.

## [Unreleased]

See [`UPGRADING.md`](UPGRADING.md) for what these mean for an existing install.

### Security

- **Livewire media library authorization.** The `media-library` component now authorizes `media.viewAny` before listing or selecting files, matching `MediaLibraryController`; previously only upload and delete were gated.
- **Saved-pattern endpoints now require authorization.** `/editor/patterns` (list / save / delete) had no role check at all, so with the default `['web']` middleware an anonymous visitor could write arbitrary JSON and delete every pattern. All three actions now require the `authors` tier (with the usual local-dev bypass), and a pattern's `blocks` payload is capped at 512 KB.
- **SSRF guard for outbound AI/MCP requests.** New `OutboundUrlGuard` vets MCP server URLs and custom provider base URLs on save and again before every request. Private networks are allowed automatically only in `local` (`heisenberg.ai.outbound.*`), link-local / cloud-metadata ranges are always blocked, disguised host forms (bracketed IPv6, decimal/hex/short IPv4, `*.localhost`) are normalized or refused, and these requests no longer follow redirects.
- **SVG upload guard.** `.svg`/`.svgz` uploads are refused unless a `SvgSanitizer` is bound (`heisenberg.media.svg_sanitizer`); `.svgz` is inflated incrementally under a hard size cap.
- **Anonymous-bypass warning.** A throttled log warning fires while the local-dev anonymous bypass is active, escalated when `APP_DEBUG` is false.

### Added

- **Demo host app** under `workbench/` with an end-to-end adopter-path test (`tests/Demo`), screenshots, and `docs/demo.md`.
- `UPGRADING.md`, `docs/ARCHITECTURE.md`, and `docs/STATUS.md`; the frozen `TODO.md` / `CODE_REVIEW.md` moved to `docs/archive/`.
- CI: explicit Laravel 11 / 12 / 13 matrix, Larastan (baselined), Pint, `composer audit`, Dependabot, and the jsdom + Playwright JS harnesses. `composer test:parallel` runs the suite under paratest.
- Editor stylesheet caching: versioned URLs with `immutable` caching, ETag / `304` revalidation otherwise (was `no-store`).
- Autosave skips saves whose content is unchanged and backs off for very large documents.

### Changed

- **Internal structure, no behavior change.** `McpToolRegistry`, `BlockRegistryService` and `BlockRenderer` are now thin façades with unchanged public APIs over `src/Mcp/`, `src/Blocks/` and `src/Rendering/`; `block-runtime.blade.php` is a table of contents over 13 ordered partials emitting a byte-identical page. Pinned by a tool-catalogue snapshot test and a renderer golden-output corpus. Hosts that extended these classes by subclassing and overriding private/protected methods should re-check their overrides.
- Icon blocks no longer re-read the icon manifest from disk on every render.
- Code style is now enforced by Pint (config tuned to the existing conventions) and static analysis by Larastan with a baseline.
- `heisenberg_posts.locale` is a plain string column instead of a database ENUM; the supported locales are an application concern (`heisenberg.locales`).
- With the bundled public route enabled and no `seo.url_template`, sitemap / canonical / hreflang URLs now use the public route instead of the editor preview.
- `orchestra/testbench` dev constraint widened to `^11.0`. The package advertised Laravel 13 support that could not previously be installed, so the suite had never run on it; it now does, on 13.32, and CI runs a real 11 / 12 / 13 matrix in parallel (~5 min per lane instead of ~33).
- Test suite hygiene: CSRF is disabled through one version-agnostic helper (Laravel 13 renamed the middleware, which silently turned 30 files' `withoutMiddleware()` calls into no-ops), and the file-backed theme / saved-theme / AI settings / AI credential stores get a fresh temp directory per test instead of sharing the Testbench skeleton's `storage/` with `testbench serve`.

### Fixed

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

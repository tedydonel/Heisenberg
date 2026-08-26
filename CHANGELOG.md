# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) for tagged releases.

## [Unreleased]

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

[Unreleased]: https://github.com/tedydonel/Heisenberg/compare/v0.0.2...HEAD
[0.0.2]: https://github.com/tedydonel/Heisenberg/compare/v0.0.1...v0.0.2
[0.0.1]: https://github.com/tedydonel/Heisenberg/releases/tag/v0.0.1

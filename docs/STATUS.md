# Status

**As of 2026-09-23 · v0.0.10 · ~6 weeks old, 239 commits, solo maintainer.**

This is the living "what's actually true right now" document. When `TODO.md`, `CODE_REVIEW.md`,
and `docs/ROADMAP.md` went stale (frozen 2026-08-06, archived to `docs/archive/`), nothing
tracked the ~170 commits that followed — an email builder, an AI assistant, bidirectional MCP,
threaded comments, SEO, revisions, and single-row bilingual content all shipped with no planning
doc catching up. This document replaces them. **Keep it alive**: update it on every tagged
release, even if only the version line and one bullet change. A status doc nobody touches for six
weeks is exactly the failure mode this file exists to fix.

## What Heisenberg is

Heisenberg is **an embeddable content + email engine for Laravel, with AI and MCP built in** —
not "a lightweight blog builder." It drops a block editor, a bilingual content model, and a
shared email builder into an existing Laravel app without asking that app to adopt a CMS, a user
system, or a frontend framework. Differentiators: no users/no theme system (a handful of
contracts, not a login table), no build step (vanilla JS + server-authoritative rendering),
bilingual-native content (one row, two languages, not two drifting documents), posts and email on
one block engine, and MCP in both directions (Heisenberg as client and as server).

**Non-goals**: not a full CMS, no admin panel or user management, no theme system, no Filament
dependency, and — before 1.0 — no arbitrary N-locale support. Bilingual (en/fr) single-row
content is a deliberate design decision, not a placeholder; see `IDEA.md`.

## What's shipped

One line per subsystem. **Finished** = built, wired end-to-end, and tested. **Partial** = real
and wired, but with a named gap. **Decorative** = present in the UI but not functionally wired.

| Subsystem | Status | Notes |
|---|---|---|
| Block engine (registry/validator/renderer/sanitizer) | Finished | 12 contracts; one PHP renderer walks the same template for editor canvas, preview, public page, and email. |
| Write pipeline (`BlocksPayloadService::validatePayload`) | Finished | Single choke point for editor saves, autosave, and MCP writes alike. |
| Public render path (`GET /posts/{locale}/{slug}`) | Finished, opt-in | `PostPublicController::show()` runs the real render pipeline (SEO, hreflang, comments, featured image included), 404s for email posts. Off by default (`heisenberg.public.routes`); 11 tests in `tests/Public/PostPublicControllerTest.php`. No longer "open, and now blocking" — it shipped 2026-08-23. |
| Post-template rendering pipeline | **Partial** | `PostTemplateRegistryService` discovers and validates template contracts (`php artisan templates:verify`), but neither the public route nor the preview controller reads a post's template to pick a layout — the page shell is hardcoded Blade. What *is* live: the template concept's 4 capability-adapter contracts (comments/SEO/related-posts/views providers), consumed directly. A template today is validated JSON with no consumer for its layout half. |
| Undo/redo | Finished | Snapshot-based history in `block-runtime.blade.php` (debounced 400ms, capped at 100 snapshots), covers attribute/support writes and structural edits, wired to Ctrl/Cmd+Z and the topbar buttons. Covered only by the manually-run `tests/js/history-revisions-matrix.mjs` harness, which was not part of CI as of v0.0.7. |
| Topbar Preview button | Finished, one caveat | For an existing post it opens `/editor/{post}/preview`, which renders the **last saved** block tree, not unsaved edits — by design, documented in `routes/editor.php`. For a never-saved document it previews live in-memory state via a session-backed route. |
| Saved patterns | Finished | `Pattern` model + `HeisenbergPatternController` (list/save/delete) behind the **Patterns** tab (renamed from Blocks, 2026-09-23) and the toolbar's save-as-pattern dialog. This row previously claimed the UI was wired when it was not: `editor/index.blade.php` never passed the panel its URLs or rows, so the tab could list nothing and the toolbar's save had nowhere to post, and the save button itself threw `closeAll is not defined` before its popover could open. Fixed 2026-09-23 and covered by `tests/Editor/PatternsPanelWiringTest.php`. Since v0.0.8 the endpoints, previously open to anonymous visitors, require the `authors` tier and cap payload size; covered by `tests/Patterns`. Patterns are install-wide (no owner), and their block payload is only shape-checked on save — full validation happens when the inserted blocks are saved with a post. |
| Accessibility sweep | **Open, not started** | No focus trapping, no `Escape` outside `ui/select`, no `role="tabpanel"`/`aria-controls`, no `aria-live` on save/autosave/conflict state. `TODO.md` item 6.4 and `code-review-2026-08-07.md` finding N10; no commit since 2026-08-07 addresses either. |
| Media library | Finished | Upload, responsive variants, bilingual alt/caption, `VirusScanner` seam. Extension allowlist enforced inside `MediaLibraryService` itself (not just the form request) after an SVG-upload bypass was found via non-HTTP callers. |
| Taxonomy (categories/tags) | Finished | Many-to-many via pivots (categories moved off a single FK — see `UPGRADING.md`). |
| Comments | Finished | Threaded, moderated, public thread API, opt-out routes. |
| SEO | Finished | Real storage/scoring/sitemap/hreflang since 2026-08-12 — no longer a mockup. |
| Email builder | Finished (simpler than it was) | Same editor, email-safe block palette, CID-embedded self-contained MIME output. The value-resolving personalization layer (interpolator, admin batch ZIP export) was removed 2026-09-17 — Heisenberg renders `{{ tokens }}` verbatim and the host substitutes them at send time. What remains (re-added 2026-09-19) is authoring-only variable *metadata*: `heisenberg.email.variables` feeds `EmailVariableCatalog`, the Email Variables panel, and `{{` autocomplete; it never resolves values. See `docs/email-system.md` §6. Since 2026-09-23 the `icon` block is email-safe — its glyph is rasterized by the editor and embedded as an inline `cid:` PNG (§4.2) — and a theme font reaches the message instead of collapsing to a web-safe stack alone (§2.1). |
| AI assistant | Finished | Conversations persist server-side (`AiConversation`) and survive a refresh: the panel records which thread is open when one is created, not only when one is reopened from history, and a tab that remembers nothing continues the post's most recent thread (2026-09-23 — before that a reload came back empty and the next message carried no prior turns). Tool-calling loop with iteration budget and per-call caching; 10 provider presets, 2 actual wire formats (Anthropic, OpenAI-compatible); API keys never touch the plain-JSON settings file, only the `AiCredentialStore` seam. |
| MCP (bidirectional) | Finished, one open gap | Server (`McpToolRegistry`, inbound) and client (`HttpMcpClient`, outbound) both exist over HTTP/JSON-RPC. The inbound server speaks the Streamable HTTP transport (v0.0.8); outbound calls pass through `OutboundUrlGuard` (v0.0.8); the one open gap is that the vetted IP is not pinned into the request — see Known debt. |
| Revisions | Finished | Snapshot on every update; restore replays through the same document-replace path undo uses. |
| Translations (single-row bilingual) | Finished | One post, one row, both languages; `TranslationStatusService` reports per-language completeness. A DB migration converting the `locale` column from a fixed `ENUM('en','fr')` to a plain string shipped in v0.0.8 (see `UPGRADING.md`). |

## Released: v0.0.8 (2026-09-22)

The project review, the single-canvas email editor, and the AI-panel fixes below are all tagged as
`v0.0.8` — `CHANGELOG.md` has the itemised list and `UPGRADING.md` says what each breaking change
means for an existing install. `CHANGELOG.md`'s own `## [Unreleased]` section stays empty until the
next batch of work.

## Next 3 things

1. **Wire post-template capabilities into the bundled public page.** This is the demo app's main
   finding and the biggest remaining gap in the adopter path: `PostTemplateRegistryService`
   discovers and validates template contracts, but nothing renders from them. Of the 11 declared
   capabilities the shipped `preview` view implements 3, partially (featured image, TOC, comments);
   breadcrumbs, reading time, author box, share buttons, pagination, post views, related posts and
   template-level SEO are rendered nowhere in `src/`. The workbench wires them by hand in ~100
   lines (`workbench/routes/web.php`, `workbench/resources/views/blog/show.blade.php`) — that code
   is the spec for what the package should offer. Decide: ship capability partials hosts can
   include, or cut the capability list down to what is real.
2. **Accessibility sweep.** Not started since 2026-08-07: no focus trapping in dialogs, `Escape`
   handled only in `ui/select`, no `role="tabpanel"`/`aria-controls`, no `aria-live` on
   save/autosave/conflict state. An editor that is keyboard-hostile is a hard blocker for a class
   of adopters, and it gets more expensive with every new panel.
3. **Finish the test-quality pass.** Still open — v0.0.8 shipped without it, since the email-canvas
   and AI-panel fixes it landed alongside were themselves large and host-visible enough not to sit
   unreleased any longer. Convert the remaining string-matching files (largest first:
   `InspectorWiringTest` 76, `SupportsCapabilityFixtureTest` 55, `BlockRendererTest` 54,
   `EditorPromptTest` 52, `ColorPickerTest` 51) and give `EditorPrompt` an identifier-addressable
   rule table so prompt tests assert rule IDs instead of prose.

## Known debt

- **Post-template registry has no rendering consumer** — see Next 3 things #1.
- **A11y sweep not started** (archived TODO 6.4) — see Next 3 things #2.
- **SSRF guard does not pin the vetted IP.** The guard re-resolves immediately before each request,
  which narrows a DNS-rebinding window to milliseconds but does not close it; closing it means
  passing the vetted IP to the HTTP client (`CURLOPT_RESOLVE`). Admin-tier surfaces only;
  documented in `SECURITY.md`.
- **Saved-pattern payloads are only shape-checked on save.** Full `BlocksPayloadService` validation
  happens when the inserted blocks are saved with a post, so nothing unvalidated is ever rendered,
  but a pattern can hold a block name that no longer exists.
- **TOC labels and comments are not per-locale** — a French page shows the authored TOC labels and
  the shared comment thread as-is (the latter by design, see `docs/content-translation.md` §0).
- **Saved themes live in a JSON file, not the database** (`storage/app/heisenberg/themes.json`, via
  `SavedThemeRepository`). Each is a complete, independent copy of every token — nothing an author
  saves lives in code — but it is file state, so it does not travel with a database dump.
- **No shared popover/menu primitive** (archived TODO 6.3) — several independent reimplementations.
- **Undo history stores full-document snapshots** (up to 100) and autosave still PUTs the whole
  block tree; fine for typical posts, a memory/bandwidth cost for very large ones.
- **`tests/js/canvas-drag-matrix.mjs`** has one reproducible failure ("a no-drag click selects the
  block and lands the caret in its text") that predates this work and has not been diagnosed.
- **MySQL / PostgreSQL are untested in CI.** The locale migration's driver-specific branches were
  verified by compiling the SQL each grammar emits, not by running against live servers.
- **`docs/inspector-composition.md` is stale** — bannered rather than rewritten.
- Needs verification (neither confirmed open nor fixed): whether the editor's title-state
  duplication across closures was ever consolidated, and whether archived TODO 6.10's "dead
  contracts" note still applies now that there are 12 blocks.

Items confirmed **fixed** since 2026-08-06 and dropped from this list: missing `.hb-align-*` CSS
rules, canvas ignoring `style.className`, the public preview routes skipping `PostPolicy`
authorization, dead `hb:pick-image`/`hb:featured-image-change` event listeners, and the SEO panel
being a non-functional mockup.

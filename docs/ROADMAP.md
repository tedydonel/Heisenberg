# Heisenberg — Roadmap

> This document used to carry a detailed "Done / In progress / Next" plan. That plan stopped
> being updated on 2026-08-06 and was overtaken by ~170 commits (email builder, AI assistant,
> bidirectional MCP, threaded comments, SEO, revisions, single-row bilingual content) — it was
> deleted rather than left stale. For **current state**, see [`docs/STATUS.md`](STATUS.md). For
> the block engine's original reconstruction spec, see [`docs/BLUEPRINT.md`](BLUEPRINT.md). This
> file now holds only the genuinely forward-looking plan: what has to be true before 0.1.0, and
> before 1.0.

## Toward 0.1.0

0.1.0 is "safe for a stranger to install," not a feature milestone. What must be true:

- **A stable-enough schema.** The `locale` column migrating from a fixed `ENUM('en','fr')` to a
  plain string is in progress; once it lands, the schema shape should not need another breaking
  migration for a while. See `UPGRADING.md`'s Unreleased section.
- **An upgrade guide that actually exists.** `UPGRADING.md` now covers v0.0.1 → v0.0.7; it needs
  to keep pace with every tag from here, not fall behind the way the planning docs did.
- **A demo/workbench app, finished.** Not screenshots — a real Laravel app that installs
  Heisenberg as a dependency and exercises the adopter path end to end (install, migrate, author
  a bilingual post, send an email, connect an MCP client). This is `docs/archive/TODO.md` Phase 5,
  unstarted since 2026-08-05 and now in progress; finishing it is what would have caught the
  config-merge bugs `ConfigMerge`'s own docblock says bit a real install three times.
- **CI gates that mean something.** Larastan, Pint, `composer audit`, and a real Laravel
  11/12/13 matrix (today's CI is PHP-version-only) — in progress on `chore/review-fixes`, not yet
  merged.
- **A JS test harness.** There is currently no automated coverage for the ~16,000-line vanilla-JS
  editor runtime at all — undo/redo, canvas rendering, and most of the shortcode-dialect parity
  guarantee are unverified by CI.
- **The god classes stop growing.** `McpToolRegistry` (2,046 lines), `BlockRegistryService`
  (1,435 lines), `BlockRenderer` (1,422 lines), and `block-runtime.blade.php` (2,014 lines) don't
  need to shrink before 0.1.0, but they shouldn't keep absorbing new responsibilities unsplit.

## Toward 1.0

- **Outside usage.** At least one real adopter running Heisenberg in a non-demo application,
  surfacing integration problems this repo's own tests can't.
- **The post-template rendering gap closed.** Templates are validated JSON with no rendering
  consumer today (see `docs/STATUS.md`); either wire them into the public/preview render path or
  retire the concept.
- **An accessibility pass.** No focus trapping, no consistent `Escape` handling, no ARIA live
  region for save/autosave state — open since at least 2026-08-07.
- **A considered position on N-locale support**, not a default. Bilingual (en/fr) single-row
  content is the deliberate 0.x design; broadening past two locales is a real schema and UX
  question, not a config toggle, and stays out of scope until 1.0 explicitly takes it up.
- **Versioning discipline.** Pre-1.0, `0.0.x` releases may carry breaking changes (this package
  has shipped several); 1.0 is the point at which that stops being acceptable, and semver starts
  meaning what hosts expect it to mean.

## Open questions — resolved

The prior version of this document carried an open-questions table; most of what it asked has
since been settled by shipped code or explicit lead decisions. For the record:

| Question | Resolution |
|---|---|
| Is the public render path (`GET /posts/{locale}/{slug}`) still blocking? | **No.** It shipped 2026-08-23, is real (`PostPublicController` runs the full `BlockRenderer` pipeline, SEO, hreflang, comments included), and is tested (`tests/Public/PostPublicControllerTest.php`). It ships **opt-in**, off by default — a host that wants a different URL shape leaves it off and binds its own `PostUrlResolver`. |
| Is single-row bilingual content a stopgap or the design? | **The design**, per an explicit lead decision (see `IDEA.md`). Arbitrary N-locale support is a non-goal before 1.0, not an oversight. |
| Does Heisenberg still own email personalization (variable substitution, batch export)? | **No.** That layer was removed 2026-09-17; Heisenberg renders `{{ tokens }}` verbatim and the host substitutes and sends. See `docs/email-system.md` §6 and `UPGRADING.md`. |
| Is the post-template contract wired into rendering? | **Not yet** — it's validated, discoverable JSON with no layout consumer. Tracked above as a 1.0 requirement, not assumed done. |

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project aims to
follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html) once it tags a
first release.

## [Unreleased]

### Security
- **Fixed a `javascript:` XSS bypass** in the shared URL filter (`BlockRenderer::safeUrl()`
  and `NullMediaResolver::safeUrl()`): a control character inside the scheme
  (`java\tscript:…`) made `parse_url()` report no scheme, so the value slipped past
  the allow-list and browsers still executed it. Control characters are now stripped
  before the scheme is parsed, with a regression test covering the obfuscated variants.
- Builder route middleware is now **config-driven** so a host can protect the builder
  surface (theme write, preview store) with its own `auth`/role middleware; the dead
  `middleware.builder` config key is now actually consumed.
- `ThemeRepository::save()` now writes with `LOCK_EX`.
- `target="_blank"` links now emit `rel="noopener noreferrer"`.

### Added
- **Builder full-kit overhaul (in progress).** The block toolbar and inspector are
  growing to the full control superset from the `heisenberg.pen` design, with each
  block loading only the controls its contract declares.
  - Phase 1 — backend foundation: extended `supports` schema; 11 new lockstep CSS-value
    sanitizers (opacity, angle, signed length, box-shadow, and alignment/flex/position
    enums); a generated shared "supports capabilities" stylesheet
    (`Support/SupportsStyle.php`, served at `/heisenberg-assets/supports.css`); new
    inspector-panel derivation (Alignment, Position, Flex Layout, Appearance, Effects,
    extended Typography/Dimensions/Stroke).
  - Phase 2 — inspector control kit (segmented, xy-pair, align-grid, shadow widgets).
- `registryHash` in a blocks payload is now verified against the live registry hash.

### Changed
- Removed dead code: the `iconUrlTemplate` registry field (pointed at an unregistered
  route) and ~240 lines of superseded inspector rendering in `builder.js`.
- Config no longer references the six not-yet-built domain models (Category, Tag,
  Comment, Revision, Pattern, SeoMeta); they are commented as M3 work.

### Fixed
- Missing per-block CSS files now log a warning in local/dev instead of failing silently.

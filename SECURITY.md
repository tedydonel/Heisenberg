# Security Policy

Heisenberg renders user-authored block content to HTML, so its security posture
(escaping, sanitization, scheme/CSS allow-lists) is core to the package.

## Reporting a vulnerability

Please report suspected vulnerabilities privately rather than opening a public
issue. Email the maintainer with:

- a description of the issue and its impact,
- steps or a proof-of-concept to reproduce it,
- the affected version/commit.

You can expect an acknowledgement within a few business days and a coordinated
disclosure once a fix is available.

## Scope

Security-critical surfaces, and where they live:

- **HTML rendering / XSS** — `src/Services/BlockRenderer.php` (tag/attribute/CSS
  allow-lists, URL scheme filter) and `src/Services/HtmlSanitizationService.php`
  (the HTMLPurifier backstop).
- **Path traversal** — icon/font/block asset lookups
  (`BlockRegistryService::validatePath`, `BuilderController::font`,
  `LucideIconProvider`/`PhosphorIconProvider`).
- **Authorization** — the builder HTTP surface is protected by host-configured
  middleware (`config('heisenberg.middleware.builder')`); hosts must set this to
  include their `auth`/role middleware in production.

Please do not loosen the sanitizer allow-lists without a security review — several
carry explicit regression tests and inline warnings.

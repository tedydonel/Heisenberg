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
- **Local-dev anonymous bypass** — `src/Adapters/LocalDevRoleGate.php` treats a
  request with no logged-in user as fully authorized on `/editor` and its media
  library, but ONLY while `app()->environment('local')` AND
  `config('heisenberg.allow_anonymous_in_local')` (default `true`) are BOTH true,
  re-checked on every call. `HeisenbergServiceProvider::warnAboutAnonymousLocalBypassIfActive()`
  logs a `Log::warning()` once per boot while this is active — escalated wording
  when `config('app.debug')` is false, since a real dev box rarely runs with
  debug off — so this misconfiguration cannot go unnoticed in a log a host
  actually reads. Set `HEISENBERG_ALLOW_ANONYMOUS_IN_LOCAL=false` to disable the
  bypass itself, or `HEISENBERG_WARN_ANONYMOUS_IN_LOCAL=false` to silence only
  the notice. This bypass must NEVER be reachable in production; if `APP_ENV`
  can legitimately be `local` on a box real visitors can reach, disable it.
- **Outbound SSRF (AI/MCP)** — every URL Heisenberg's AI assistant fetches
  server-side (an outbound MCP tool call, the MCP "test connection" probe, and a
  custom OpenAI-compatible/Anthropic provider `base_url`) is checked by
  `src/Support/OutboundUrlGuard.php` before the request is made — both when the
  entry is SAVED (`AiSettingsRepository::validateProviders`/`validateServers`)
  and again IMMEDIATELY BEFORE every actual request (`HttpMcpClient::rpc()`,
  `AiMcpController::test()`, `AnthropicProvider`, `OpenAiCompatibleProvider`),
  which shrinks the DNS-rebinding window a save-time-only check leaves open from
  "any time after save" to milliseconds. **Known limit:** it does not close it —
  the HTTP client resolves the hostname again itself, so a TTL-0 rebinding race
  (or a host that is unresolvable to the guard, which is allowed through) is
  still theoretically possible; the vetted IP is not yet pinned into the request
  (`CURLOPT_RESOLVE`). These surfaces are `admins`-tier only. Host spellings the
  HTTP client would expand on its own — bracketed IPv6 literals, decimal/hex/
  octal/short IPv4 (`2130706433`, `0x7f.0.0.1`, `127.1`), `*.localhost` — are
  normalized or refused before classification.
  Loopback/RFC1918/CGNAT/IPv6-ULA targets are allowed automatically only in the
  `local` environment (`config('heisenberg.ai.outbound.allow_private_networks')`
  opts them in elsewhere — a host running an MCP server inside its own VPC must
  set this explicitly, or name the host in
  `config('heisenberg.ai.outbound.allowed_hosts')`); link-local addresses
  (169.254.0.0/16 and IPv6 `fe80::/10` — this is ALSO the AWS/GCP/Azure cloud
  instance-metadata range) are blocked **unconditionally**, with no config flag
  able to re-enable them. Outbound requests through these paths also disable
  HTTP redirect-following, so an otherwise-allowed URL cannot 302 into a blocked
  range unnoticed.
- **Stored XSS via SVG uploads** — an `.svg` is an XML document that can carry
  an inline `<script>` or event-handler attribute and is later served back from
  this app's own origin with a real `image/svg+xml` content type.
  `MediaLibraryService::storeOne()` refuses every `.svg`/`.svgz` upload outright
  — however `config('heisenberg.media.extensions')` is set — unless a real
  `Heisenberg\Contracts\SvgSanitizer` implementation is bound via
  `config('heisenberg.media.svg_sanitizer')`; there is deliberately no bundled
  "null" sanitizer the way `VirusScanner` has one, since a permissive default
  here would defeat the point. Once a sanitizer is bound, its output — never
  the raw upload — is what reaches disk.

Please do not loosen the sanitizer allow-lists without a security review — several
carry explicit regression tests and inline warnings.

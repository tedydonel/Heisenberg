# Host-Defined Email Template Variables + Admin Batch Export

> **For Hermes:** Execute this plan with fresh MiniMax M3 workers (`--provider minimax-oauth -m minimax-m3`) task-by-task, using strict RED-GREEN-REFACTOR and two-stage review (spec compliance, then code quality).

**Goal:** Let a host Laravel application register any email variable keys and custom value types, expose those definitions in Heisenberg’s email editor, personalize render/export per recipient, and let an **admin** mass-generate a finite batch of personalized HTML/EML files for every requested locale — without Heisenberg owning SMTP, subscriber lists, campaigns, or a user directory.

**Architecture:** Add a host-extensible variable registry beside the existing block registry. A per-recipient immutable context is an explicit flat map of values keyed by registered dotted names. Before the existing email block pipeline runs, a context-aware interpolator formats and substitutes variables into a **copied** block tree and subject; then `BlockRenderer`, URL allow-list, rich-text sanitizer, CID rewrite, token resolution, CSS inlining, and plain-text generation remain authoritative. Editor preview/single export use non-secret registered **samples**. Real render, mailable, and **admin batch export** are strict and fail before producing output when a referenced variable is unknown, missing, invalid, or incompatible. Batch export is a file factory: N host-supplied recipient maps × requested locales → zip. Sending those files is the host’s mailer, never this package.

**Tech Stack:** PHP 8.2+, Laravel 11–13 container/mail, Orchestra Testbench/PHPUnit, server-rendered Blade and package-native vanilla JavaScript (no Node/Vite, no new Composer dependency). Zip via PHP `ZipArchive` (already required by typical PHP installs; skip/fail clearly if the extension is missing).

---

## Progress (worker log, 2026-08-26)

### Task 0 — baseline
- **Command:** `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php`
- **Result (pre-implementation baseline, captured once before any E5 code):** OK (83 tests, 225 assertions), 01:26 wall-clock, PHP 8.4.22, PHPUnit 11.5.56. No product-doc changes made for baseline.
- **Post-Task-1 verification (same command, after registry/formatter contract shipped):** OK (95 tests, 285 assertions), 01:45 wall-clock. The +12 tests / +60 assertions are exactly the new `EmailVariableRegistryTest` cases; every pre-existing test is still green.

### Task 1 — host variable registry + formatter contract (GREEN)
- **Path created — contract:** `src/Contracts/EmailVariableType.php` (the `key()` / `targets()` / `format()` seam the plan's "Public contract shape" pins).
- **Path created — value object:** `src/Support/EmailVariableDefinition.php` (`key`, `label`, `type`, `sample`, optional `group` / `description` / `options`, all `readonly`).
- **Path created — immutable flat-map:** `src/Support/EmailVariableContext.php` with named `mode` (`runtime` / `sample`), `runtime(array|self)` and `samples(EmailVariableRegistry)` factories, `get()` / `has()` / `mode()` / `isRuntime()` / `all()`. Runtime maps are retained exactly; `has()` distinguishes an explicit `null` value from a missing key.
- **Path created — registry:** `src/Services/EmailVariableRegistry.php` (`registerType()` / `register()` / `type()` / `definition()` / `definitions()` / `types()` / `format()` / `editorMetadata()`; built-in types auto-registered in constructor; key pattern `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$`; reserved BlockRenderer roots rejected; labels and formatter target lists validated centrally; duplicate key & duplicate type both throw `InvalidArgumentException`; `editorMetadata()` formats and caches sample strings when metadata is requested).
- **Path created — built-in formatters (six):** `src/Mail/VariableTypes/TextEmailVariableType.php` (target `text`), `UrlEmailVariableType.php` (target `url`), `EmailAddressEmailVariableType.php` (targets `email` and `url` for `mailto:` fields), `NumberEmailVariableType.php` (target `text`, locale-aware via `options['decimals']`), `BooleanEmailVariableType.php` (target `text`, `options['format']` ∈ `code` / `toggle` / `word`), `DateEmailVariableType.php` (target `text`, accepts `DateTimeInterface` / timestamp / ISO-8601 string, `options['format']` PHP `date()` pattern). All deliberately avoid `ext-intl` (plan §Step 2 GREEN).
- **Path modified — singleton binding:** `src/HeisenbergServiceProvider.php` `registerEngine()` now binds `Heisenberg\Services\EmailVariableRegistry::class` as a singleton, next to `EmailRenderer` and `BlockRenderer`.
- **Path created — focused test:** `tests/Email/EmailVariableRegistryTest.php` (16 tests, 75 assertions). Covers built-in type registration, central target/label validation, host custom type, editor-safe metadata without formatter objects / runtime values, duplicate keys/types, invalid/reserved roots and descendants, unknown types, sample contexts, exact runtime-map/null semantics, non-scalar custom samples, and exception value redaction.

#### Compatibility decisions recorded for Task 1
- **Key pattern relaxed to allow single-segment keys.** The plan text in "Locked API decisions" §1 says "Keys match a conservative dotted identifier pattern"; the plan's own "Target public usage" block uses `unsubscribe_url` (no dot) as a registered key. To honor both — "conservative" (no whitespace, no leading/trailing/double dots, starts with a letter, only `[a-z0-9_]` inside segments) AND the documented public example — the implemented regex is `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$`. A single-segment `unsubscribe_url` passes; `user.first_name` still passes; `user..first_name` / ` user.first_name` / `1user.first` still fail. Reserved BlockRenderer roots (`id`, `name`, `attributes`, `supports`, `lang`) are rejected as a separate check, regardless of segment count.
- **Backward compatibility.** No public API changed. `EmailRenderer::render()` still has the three-positional-arg shape; `HeisenbergMailable::__construct($postId, $locale)` is unchanged; `PostPolicy` is unchanged; `EmailPreviewController` and `EditorController` are unchanged for Task 1 (Tasks 3 / 4 / 5 will thread variables through). The new registry is additive and unbound callers see no behavior change.
- **Editor metadata sample formatting is lazy and cached.** `editorMetadata()` runs each definition's `sample` through its formatter when metadata is first requested, caches the formatted string, and busts the cache on every `register()` call. The picker receives literal strings rather than formatter or host value objects; an invalid sample throws instead of becoming a silent empty string.

#### Verification commands and outcomes (Task 1)
- **RED (focused, expected failure):**
  ```
  vendor/bin/phpunit tests/Email/EmailVariableRegistryTest.php
  ```
  → `Message: Interface "Heisenberg\Contracts\EmailVariableType" not found` (`tests/Email/EmailVariableRegistryTest.php:330`). Confirmed RED: no production code existed.
- **GREEN (focused, after production code):**
  ```
  vendor/bin/phpunit tests/Email/EmailVariableRegistryTest.php
  ```
  → `OK (12 tests, 60 assertions)`, 00:01.4.
- **Plan-required "Verify" (relevant suite):**
  ```
  vendor/bin/phpunit tests/Email
  ```
  → `OK (77 tests, 229 assertions)`, 00:33.
- **Task 0 baseline command rerun (full required scope):**
  ```
  vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php
  ```
  → `OK (95 tests, 285 assertions)`, 01:45.

#### Risks remaining at end of Task 1
- `EmailVariableRegistry::editorMetadata()` formats samples using the configured default locale and caches them until another definition is registered. Task 5 must decide whether locale switching needs a locale-parameterized metadata cache.
- Host providers that want to REPLACE a built-in formatter (`text`, `url`, …) must do so via a separate "override" seam — the current duplicate-key guard rejects replacement. Documented gap; a future Task 1.x or 9 can add `replaceType()` if a host actually needs it.
- No `EmailVariableResolutionException` yet — that class is introduced in Task 2 (the interpolator) along with the strict aggregated exception path. Task 1's failures are immediate `InvalidArgumentException` throws from the registry / formatter; Task 2 graduates them to the aggregated form the plan's interpolation algorithm describes.

#### Parent correction review (Task 1, 2026-08-26)
- Fresh MiniMax-M3 spec and quality reviews found missing central target validation plus a dangling nonexistent method reference. Parent review additionally found non-exact runtime-map handling, explicit-null ambiguity, an empty-label gap, and a date formatter error that exposed the supplied value.
- **RED:** `vendor/bin/phpunit tests/Email/EmailVariableRegistryTest.php` → 16 tests, 4 expected failures covering those gaps.
- **GREEN:** `vendor/bin/phpunit tests/Email/EmailVariableRegistryTest.php` → `OK (16 tests, 72 assertions)`; `vendor/bin/phpunit tests/Email` → `OK (81 tests, 241 assertions)`; `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php` → `OK (99 tests, 297 assertions)`.

### Task 2 — context-aware, strict interpolation before sanitization (GREEN)
- **Path created — aggregated resolution exception:** `src/Support/EmailVariableResolutionException.php`. Carries `list<{key, reason}>` failures plus convenience `getKey()` / `getReason()` / `getKeys()` / `getFailures()` accessors; `single()` convenience constructor; reason constants `REASON_UNKNOWN_TOKEN`, `REASON_MISSING_VALUE`, `REASON_FORMATTER_FAILED`, `REASON_INCOMPATIBLE_TARGET`. `getMessage()` is deterministic and value-free — formatter-internal exception messages are discarded at the throw site, never reach the message string.
- **Path created — interpolator:** `src/Services/EmailVariableInterpolator.php`. Single public surface — `interpolateSubject(string, EmailVariableContext, string): string` and `interpolateBlocks(array, EmailVariableContext, string): list<array>`. Constructor takes the existing `EmailVariableRegistry` and `BlockRegistryService` singletons.
- **Path modified — service-provider binding:** `src/HeisenbergServiceProvider.php` `registerEngine()` now binds `Heisenberg\Services\EmailVariableInterpolator::class` as a singleton beside `EmailRenderer` and `BlockRenderer`.
- **Path created — focused test:** `tests/Email/EmailVariableInterpolatorTest.php` (28 tests, 49 assertions). Covers plain/rich/URL/translatable-string substitution, repeated tokens and distinct-key reporting, `mailto:` email values, sanitizer ordering, formatter compatibility before invocation, non-scalar and null values, locale threading, raw MIME subjects, safe aggregated failures, ignored structural fields, recursive copy/no mutation, samples, optional surrounding whitespace, and end-to-end `BlockRenderer` integration.
- **Path NOT modified:** `EmailRenderer.php`, `BlockRenderer.php`, `HeisenbergMailable.php`, `EmailPreviewController.php`, `EmailExportController.php`, `EditorController.php`, `PostPolicy.php`, `routes/email.php`, `routes/editor.php`, `resources/views/components/live/pickers/variable-menu.blade.php`, `resources/views/components/live/pickers/email-variable-menu.blade.php` (does not yet exist), and any database migration. The interpolator ships as a focused, resolvable service that Tasks 3 / 4 / 6 wire into the renderer / preview / batch paths.

#### Compatibility decisions recorded for Task 2
- **Token-aware attributes are discovered from the contract, not hardcoded.** For each block, the interpolator reads `BlockRegistryService::getBlock($blockName)['attributes'][$name]` and decides per-attribute: `type: "rich-text"` → escape + substitute (HTML-escape the formatted replacement before `BlockRenderer::sanitizeRichText()`); `type: "url"` → substitute raw, `BlockRenderer::safeUrl()` is the gate that enforces scheme policy; `translatable: true` and not rich-text → substitute raw, existing text/attribute escaping handles it; everything else (`anchor`, `extraClasses`, `class`, `style`, `supports.*`, unknown contract types) is left alone. The same mechanism covers `caption`, `alt`, `titleAttr`, `href` (image), and any future host-defined block whose contract follows the same shape — no block-name or attribute-name list is hardcoded.
- **Whitespace rule follows the locked optional-surrounding-whitespace syntax.** The regex is `/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/`; repeated spaces or tabs are accepted, while invalid dotted identifiers and unclosed braces remain literal.
- **Subject interpolation stays raw plain text.** MIME subjects must not contain HTML entities; `EmailRenderer::wrapShell()` escapes the same subject separately for `<title>`. Formatter target compatibility is checked before formatter invocation.
- **Formatter exceptions are discarded at the throw site.** The interpolator catches every `Throwable` from `EmailVariableRegistry::format()` and records `{key, REASON_FORMATTER_FAILED}` — the wrapped exception's message is intentionally not preserved, since formatter implementations are host code that may surface runtime values in their own error strings. The aggregated `EmailVariableResolutionException::getMessage()` is therefore safe to log or surface over HTTP without redaction.
- **Sample context resolution goes through the registered sample.** `EmailVariableContext::samples($registry)` carries every registered definition's literal `sample` value into the map; the interpolator substitutes that sample through the same formatter path a runtime value would take. A sample context missing a key is reported as `REASON_MISSING_VALUE`. Editor metadata formats and caches samples lazily when requested.
- **No SMTP, no campaigns, no recipient tables, no editor picker UI yet.** Task 2 is the interpolation pipeline; Tasks 3 (renderer / mailable threading), 4 (preview / export samples), 5 (editor picker), 6 (admin batch) are the next steps. The interpolator ships resolvable so focused tests + any host that wants to drive interpolation outside the renderer can use it, but `EmailRenderer::render()` still ignores it.
- **BlockRenderer is not taught anything about recipient data.** The interpolator reads the contract via the existing `BlockRegistryService` only to classify attributes — it does not pass recipient values, contexts, or registry references to the renderer. The renderer's existing `substitute()` / `resolveAttributes()` / `sanitizeRichText()` / `safeUrl()` methods are untouched; the interpolated copy is fed into them as ordinary block data.

#### Verification commands and outcomes (Task 2)
- **RED (focused, expected failure):** `vendor/bin/phpunit tests/Email/EmailVariableInterpolatorTest.php` → `ReflectionException: Class "Heisenberg\Services\EmailVariableInterpolator" does not exist` at 24 of 25 tests; the 25th passed because the test file's helper classes resolved independently. Confirmed RED: no production code existed.
- **GREEN (focused, after review corrections):** `vendor/bin/phpunit tests/Email/EmailVariableInterpolatorTest.php` → `OK (28 tests, 49 assertions)`.
- **Plan-required "Verify" (relevant suite):** `vendor/bin/phpunit tests/Email` → `OK (109 tests, 293 assertions)`.

#### Risks remaining at end of Task 2
- The interpolator ships standalone. Until Task 3 wires it into `EmailRenderer::render()`, registering variables does not yet personalize a real render — a focused test that calls `interpolateBlocks()` then `BlockRenderer::renderBlock()` proves the pipeline pieces line up, but the production `EmailRenderer` is unchanged.
- `EmailVariableInterpolator` short-circuits with `continue` on attributes whose value does not match `self::TOKEN_PATTERN`. This means a block that contains NO tokens is copied as-is, byte-for-byte — a regression check for `tests/Email/EmailVariableInterpolatorTest.php::test_no_variable_content_round_trips_byte_for_byte`. A future optimisation that "pre-walks attributes with `array_walk_recursive`" must preserve this no-touch posture or the no-variable byte-for-byte guarantee is lost.
- The interpolator walks `attributes` and `innerBlocks` only. A contract author who later exposes a token through a `supports.*` slot will see the token pass through unchanged — that is the intended posture (those slots are not user-facing content), and any future change to walk into `supports` would need a fresh security review.

#### Parent correction review (Task 2, 2026-08-26)
- Two fresh MiniMax-M3 reviews caught HTML-encoded MIME subjects, compatibility checks running after host formatters, narrow whitespace handling, reserved-root descendants being registrable, duplicate `getKeys()` output, and the built-in email type not supporting `mailto:` URL fields.
- **RED:** combined Task 1/2 focused command produced 5 expected failures and 1 expected error across 44 tests.
- **GREEN:** combined Task 1/2 focused command → `OK (44 tests, 124 assertions)`; `vendor/bin/phpunit tests/Email` → `OK (109 tests, 293 assertions)`; `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php` → `OK (127 tests, 349 assertions)`.

### Task 3 — renderer and mailable personalization threading (GREEN)
- **Paths modified:** `src/Services/EmailRenderer.php`, `src/Mail/HeisenbergMailable.php`, `src/HeisenbergServiceProvider.php`, `tests/Email/EmailRendererTest.php`, `tests/Email/HeisenbergMailableTest.php`.
- `EmailRenderer::render(Post, string, bool = false, ?EmailVariableContext = null)` preserves the existing first three positional arguments. An omitted context is a strict empty runtime context, never samples. Subject and copied blocks share one failure accumulator, then the interpolated block copy feeds `capColumns()`, HTML rendering, image/CID rewriting, theme-token resolution, plain text, shell/inlining, and size calculation.
- `HeisenbergMailable::__construct(int|string, ?string = null, array|EmailVariableContext = [])` preserves existing two-argument calls. Arrays normalize to strict runtime contexts; an explicit context and its mode are preserved. The class remains non-`ShouldQueue`; queued instances carry one already-rendered recipient result.
- **RED (worker):** focused renderer/mailable suite added 16 failing Task 3 tests before production wiring. **Parent RED:** `test_subject_and_block_failures_are_aggregated_into_one_exception` failed because only the subject failure was returned.
- **GREEN:** `vendor/bin/phpunit tests/Email/EmailRendererTest.php tests/Email/HeisenbergMailableTest.php` → `OK (43 tests, 131 assertions)`; `vendor/bin/phpunit tests/Email` → `OK (131 tests, 362 assertions)`; `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php` → `OK (149 tests, 418 assertions)`.
- **Review corrections:** combined subject+block failures into one exception, corrected queue documentation, restored the pre-existing Outlook `mso-table-lspace` regression assertion, and corrected raw/interpolated-tree comments. No controller, preview/export, batch, picker, SMTP, recipient-store, or campaign work was added.
- **Remaining Task 3 risk:** direct manual construction of `EmailRenderer` now needs the interpolator dependency; package/host container resolution remains backward compatible and is the documented construction path.

### Task 4 — sample-only author-facing GETs (preview, size, single export) (GREEN)
- **Paths modified:** `src/Http/Controllers/EmailPreviewController.php`, `tests/Email/EmailPreviewControllerTest.php`, `tests/Email/EmailExportControllerTest.php`. No changes to `EmailRenderer.php`, `EmailRenderer::render()`, the renderer default, `HeisenbergMailable.php`, `PostPolicy.php`, `routes/email.php`, `routes/editor.php`, `HeisenbergServiceProvider.php`, or `EmailVariableResolutionException.php` (its API surface and message shape were already value-free and aggregation-friendly). No new files, no new dependencies.
- The constructor gains an explicit `EmailVariableRegistry $variables` dependency. A single private helper `sampleContext()` returns `EmailVariableContext::samples($this->variables)` — the registry singleton is the source of truth for keys + sample values; the registry's own resolver is the path that produces a registered `sample` (so even a non-scalar host sample flows through the registered formatter before reaching the response).
- Every render call site in the controller passes the sample context explicitly:
  - `showBySlug` — `render($model, $locale, preview: true, variables: $this->sampleContext())`
  - `size` — `render($model, $locale, variables: $this->sampleContext())`
  - `exportHtml` (private) — `render($model, $locale, preview: true, variables: $this->sampleContext())`
  - `exportEml` (private) — `render($model, $locale, variables: $this->sampleContext())`
  - The `show` / `export` id-scoped editor routes REDIRECT to the slug URL; the redirect carries `locale` and `format` and the slug URL is where the actual sample-substituted render happens, so sample substitution reaches those GETs end-to-end without a separate render call. `showBySlug`'s return type widens from `Response` to `\Symfony\Component\HttpFoundation\Response` so it can return either the existing text/html response or a JsonResponse 422.
- A new private helper `respondToResolutionFailure(EmailVariableResolutionException)` builds a 422 JsonResponse carrying `{message, failures, keys}` — keys and reasons only, no runtime values, no formatter exception messages, no stack traces, no raw `{{ ... }}` tokens. The exception's `getMessage()` is already deterministic and value-free (Task 2 discarded formatter internals at the throw site), so it is safe to surface verbatim. `failures` and `keys` mirror `getFailures()` and `getKeys()` so programmatic callers can iterate without parsing the summary.
- Each render call site wraps its `render()` invocation in `try { ... } catch (EmailVariableResolutionException $e) { return $this->respondToResolutionFailure($e); }`. The PostPolicy `view` check runs BEFORE the try/catch — an unauthorized actor still 403s, never gets a 422 with token keys.
- **RED (worker) — preview, redirect, query-string sample isolation:** `vendor/bin/phpunit tests/Email/EmailPreviewControllerTest.php --filter "samples_never_raw_tokens|does_not_accept_runtime_values|id_scoped_routes_redirect"` → 3 expected failures with HTTP 500 from `EmailVariableResolutionException: Email variable resolution failed for N token(s): user.first_name: missing value; …`. RED confirmed: the renderer threw because the controller did not pass the sample context. **GREEN:** → `OK (3 tests, 12 assertions)`.
- **RED — size endpoint:** `test_size_endpoint_uses_samples_and_returns_200` and `test_size_endpoint_does_not_accept_runtime_values_from_query_string` would 500 because `size()` did not pass the sample context. After wiring, both pass: `OK (2 tests, 7 assertions)`.
- **RED — HTML export:** `test_html_export_substitutes_registered_samples_never_raw_tokens` and `test_html_export_does_not_accept_runtime_values_from_query_string` would 500. After wiring, both pass: `OK (2 tests, 10 assertions)`.
- **RED — EML export:** `test_eml_export_substitutes_registered_samples_in_subject_and_body` and `test_eml_export_does_not_accept_runtime_values_from_query_string` would 500. After wiring, both pass: `OK (2 tests, 14 assertions)`. The runtime-injection test compares the bodies, not byte-for-byte raw output, because Symfony Mime generates a fresh `Message-ID`, `Date`, and multipart boundary on each `toString()`.
- **RED — 422 controlled error path:** `test_show_by_slug_returns_422_with_keys_and_safe_reasons_for_unregistered_token`, `test_size_endpoint_returns_422_for_unregistered_token`, `test_html_export_returns_422_for_unregistered_token`, `test_eml_export_returns_422_for_unregistered_token`, and `test_422_body_never_includes_formatter_exception_message_or_stack_trace` (with a `ThrowingTextEmailVariableType` that throws `\RuntimeException('host-secret-token: formatter-internal state …')`) all expect a 422 with `{message, failures, keys}` containing `user.unknown` (in the unregistered-token tests) or `user.first_name` with reason `formatter failed` (in the formatter-failure test). All pass: `OK (5 tests, 32 assertions)`. The formatter-failure test pins that the host secret string `host-secret-token`, the substring `formatter-internal`, the substring `Stack trace`, the substring `#0 /`, and the substring `EmailVariableInterpolator.php` NEVER appear in the 422 body. The 422 body contains the literal safe reason `formatter failed` and the registered key.
- **RED — sample-vs-runtime non-leakage, locale, token-free compatibility:** `test_html_export_uses_the_exact_registered_sample_value_never_a_runtime_default` (uses a non-secret sample `Tedy-Donelly` and asserts the exact string reaches both heading and rich-text button label, with no `Hi Sample` and no `{{ user.first_name }}` leakage); `test_html_export_threads_locale_through_sample_substitution` (asserts `?locale=fr` still substitutes the sample — French title `Bonjour Sample`); `test_show_by_slug_token_free_email_renders_through_the_sample_path_unmodified` (an email with NO tokens still renders cleanly through the sample path — no `{{` survives). All pass: `OK (3 tests, 11 assertions)`.
- **Final GREEN:** `vendor/bin/phpunit tests/Email/EmailPreviewControllerTest.php tests/Email/EmailExportControllerTest.php tests/Email/EmailRendererTest.php tests/Email/HeisenbergMailableTest.php tests/Email/EmailVariableRegistryTest.php tests/Email/EmailVariableInterpolatorTest.php tests/Editor/EmailEditorWiringTest.php` → `OK (151 tests, 476 assertions)`; `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php` (baseline scope) → `OK (166 tests, 504 assertions)`. Task 4 added 17 focused tests / 86 assertions (166 − 149 = +17 tests; 504 − 418 = +86 assertions). All pre-existing tests stay green.

#### Compatibility decisions recorded for Task 4
- **Controller constructor widens; signature stable.** `EmailPreviewController::__construct(EmailRenderer, EmailVariableRegistry)` is the only shape change. The container resolves both — `EmailRenderer` was already a singleton, and `EmailVariableRegistry` is the singleton Heisenberg already binds. No new service-provider binding required.
- **Renderer fourth parameter unchanged.** `EmailRenderer::render(Post, string, bool = false, ?EmailVariableContext = null)` still defaults to a strict empty runtime context. Sample substitution is the controller's responsibility, not the renderer's. HeisenbergMailable's host mailer seam and the upcoming admin batch endpoint therefore see a strict empty runtime context if they ever call the renderer without a fourth argument — no behavior leak.
- **No query / body / header runtime-map reading.** The controller does not touch `$request->query('variables')`, `$request->input(...)`, or `$request->header(...)` for runtime values. `locale` and `format` are the only request inputs the controller reads. A `?variables[user.first_name]=Ada` is silently dropped at the boundary.
- **422 response shape.** JSON with three keys:
  - `message` — the exception's `getMessage()` (value-free, deterministic, formatter-internal-secret-free by Task 2's design).
  - `failures` — `list<{key, reason}>` from `EmailVariableResolutionException::getFailures()`. Reason values are the four constants: `unknown token`, `missing value`, `formatter failed`, `formatter target incompatible`. No runtime values, no formatter internals, no stack traces.
  - `keys` — distinct keys (`array_values(array_unique(...))`) from `getKeys()`, insertion order preserved.
- **Idempotent fixture for the formatter-failure test.** A custom `Heisenberg\Tests\Email\ThrowingTextEmailVariableType` (defined at the bottom of `EmailExportControllerTest.php`, namespaced alongside the test class) registers a `throwing` formatter type and a definition whose `type: 'throwing'` triggers the formatter. Cannot collide with the built-in `text` type, which the registry rejects on duplicate registration.
- **Locale stays sample-resolved.** `?locale=fr` is honored exactly as before — the controller's `contentLocale()` validates and `App::setLocale()`s; the renderer's interpolated subject and blocks then run with `locale: fr`; samples that pass through formatters receive that locale. The French title `Bonjour {{ user.first_name }}` becomes `Bonjour Sample`, not `Bonjour Ada`.
- **Token-free baseline preserved.** An email with no `{{ ... }}` tokens anywhere — and no registered definitions — renders byte-for-byte on the new sample path. The interpolator short-circuits on no-token strings (no formatter call, no allocation); an empty `samples()` context produces no entries to look up; the renderer path is otherwise identical to a strict-empty-runtime call with no tokens.
- **Cid/url/format/locale/PostPolicy/slug validation/redirect semantics — all unchanged.** The wiring change is at the renderer-call boundary only. PostPolicy `view` still 403s on draft emails; the slug route still scopes to `type = 'email'`; the export route still defaults to `format=html`; the `<slug>-<locale>.{html,eml}` filename rule still applies; the `mail.from.address` 422 path for EML still triggers; `sizeBytes` still reports the cid-embedded render's HTML length + attachment bytes.
- **Unknown token → 422 for every author-facing GET, not just preview.** Size, HTML export, EML export all catch `EmailVariableResolutionException` and return the same `{message, failures, keys}` 422. The same body shape is the response for a `user.unknown` token in any of the four call sites — the author gets one consistent error contract regardless of which entry point surfaced the problem.

#### Risks remaining at end of Task 4
- The four-render-call wiring is exact-text explicit (`variables: $this->sampleContext()` every time). A future maintainer who adds a fifth author-facing render and forgets the explicit argument will get a strict-empty-runtime context (HTTP 500 from `EmailVariableResolutionException`). Task 5/6/7 should re-confirm the wiring if they introduce new GETs.
- The 422 JSON response carries `keys` and `failures`. A future endpoint that wants to surface these via a Blade view (instead of JSON) would need to translate them — out of scope for Task 4.
- No coverage of `GET /editor/{post}/email-export` FOLLOWING the redirect into `exportBySlug`/`exportModel`/`exportHtml`/`exportEml`. The redirect carries `format` through; the slug URL is the actual sample-substituted render; the assertion that `?format=eml&variables[user.first_name]=Ada` produces a sample-substituted EML lives at the slug URL, not the editor URL. This is the intended posture (one route to reason about) but a reviewer tracing the editor-id path should follow the redirect.

#### Original Task 4 checklist criteria fulfilled by this implementation
- [x] `showBySlug` passes `EmailVariableContext::samples(...)` explicitly as the renderer's fourth argument. (See line `$result = $this->renderer->render($model, $locale, preview: true, variables: $this->sampleContext());` in `EmailPreviewController::showBySlug`.)
- [x] Size, HTML export, EML export pass samples explicitly (three additional call sites).
- [x] Id-scoped editor routes that delegate to slug redirect correctly (verified by `test_known_id_scoped_routes_redirect_to_the_slug_which_then_uses_samples`).
- [x] Renderer default is NOT changed to samples (no edits to `EmailRenderer.php` or `EmailRenderer::render()`).
- [x] Runtime values are NOT accepted from query strings, request bodies, or headers (controller never reads them; tests assert `?variables[user.first_name]=Ada` is silently ignored and the sample reaches the response).
- [x] Unknown tokens / missing samples / formatter failures return controlled HTTP 422 with `{message, failures, keys}` (5 tests across preview, size, HTML export, EML export, and formatter-failure secret-redaction).
- [x] 422 body carries keys and safe reasons only; never runtime values, formatter exception messages, stack traces, or raw `{{ ... }}` tokens (asserted explicitly in `test_422_body_never_includes_formatter_exception_message_or_stack_trace`).
- [x] Existing PostPolicy `view`, slug/locale validation, redirect semantics, preview/CID URL behavior, size response shape, HTML/EML headers/content, MIME subject, and token-free bytes remain compatible (full `tests/Email` suite and `tests/Editor/EmailEditorWiringTest.php` pass with no edits to those files).
- [x] Single preview/size/HTML/EML uses registered samples only, including non-scalar samples through formatters (sample context's `get()` returns the literal `definition->sample` value, which `EmailVariableRegistry::format()` runs through the registered formatter — the same path runtime values would take).
- [x] No Task 5+ work, no runtime recipient inputs, no policy tier changes, no picker file changes. No SMTP / campaign / subscriber / recipient table work. No commit.

#### Task 4 — original plan checklist criteria NOT fulfilled here (out of scope)
- Email-only editor picker (Task 5).
- Admin batch export (Task 6).
- Docs/examples (Task 7).
- Spec and quality/security reviews (Task 8).

### Task 5 — email-only variable picker (GREEN)
- **Paths created:** `resources/views/components/live/pickers/email-variable-menu.blade.php`, `tests/Editor/EmailVariablePickerTest.php`, `tests/Editor/EmailVariablePickerWiringTest.php`.
- **Paths modified:** `src/Http/Controllers/EditorController.php`, `resources/views/editor/index.blade.php`, `resources/lang/en/editor.php`, `resources/lang/fr/editor.php`, `docs/email-system.md`, and this plan. `resources/views/components/live/pickers/variable-menu.blade.php` remains byte-unchanged (`git diff --exit-code` green).
- `EditorController` passes only `EmailVariableRegistry::editorMetadata()` rows and canonical `text,url,email` target metadata. The picker is absent on posts, empty registries, and email pages whose actor cannot `update`; new-email gating uses the same policy against an unsaved actor-owned draft posture, preserving the local-development bypass without exposing production guests.
- The separate Blade picker dynamically mounts triggers beside both subject mirrors, eligible text/URL settings controls, and the selected rich-text block toolbar. It excludes anchor, classes/chips, select/enum, boolean, number/range, and structural fields. Target filtering uses formatter targets; search covers label/key/group; Arrow keys, Enter, Escape, outside close, focus/caret restoration, empty state, `hb:refresh`, and locale-change rewiring are package-native vanilla JS.
- Tokens are inserted as literal text with `setRangeText()` or `document.createTextNode()`, never assigned through `innerHTML`. Existing bubbling `input` events keep title mirroring, `hbEditor` block state, autosave payloads, and locale-specific attributes on the established persistence path.
- **RED (MiniMax-M3 worker):** `EmailVariablePickerTest` started with 4 expected failures and `EmailVariablePickerWiringTest` with 5 expected failures. The worker then hit its MiniMax-M3 Token Plan limit with four partial-work failures remaining. **Parent RED/correction:** fixed missing runtime triggers, subject target filtering, caret capture, Blade-literal token escaping, blank-email authorization, canonical target order, and invalid test assumptions.
- **GREEN:** `vendor/bin/phpunit tests/Editor/EmailVariablePickerTest.php tests/Editor/EmailVariablePickerWiringTest.php` → `OK (17 tests, 68 assertions)`; focused picker/editor/theme/email verification → `OK (220 tests, 818 assertions)`.
- **Scope preserved:** no Task 6 batch work, no SMTP/campaign/subscriber/recipient storage, no runtime values in editor HTML, no new policy class, no dependency/build step, and no Pencil revisit.
- **Review limitation:** MiniMax-M3 subagent review could not be re-dispatched after the provider returned HTTP 429 Token Plan exhaustion; parent review traced and corrected the partial implementation and ran the focused suites. Final Task 8 still requires fresh MiniMax-M3 spec/security review when quota is available.

### Task 6 — admin batch generate and export, no SMTP (GREEN)
- **Paths created:** `src/Support/EmailBatchExportResult.php`, `src/Support/EmailBatchTranslationMissingException.php`, `src/Services/EmailBatchExporter.php`, `src/Http/Controllers/EmailBatchExportController.php`, `tests/Email/EmailBatchExporterTest.php`, `tests/Email/EmailBatchExportControllerTest.php`.
- **Paths modified:** `src/Policies/PostPolicy.php` (`generateEmailBatch` using `LocalDevRoleGate` + `email.generate` + `email` + `published`), `src/HeisenbergServiceProvider.php` (exporter dependency binding), `config/heisenberg.php` (`email.batch_max_recipients=100` and flat tier key `'email.generate'=>['admin']`), `routes/editor.php` (`POST /editor/email/{post}/batch-export` behind the editor middleware), `src/Services/EmailVariableInterpolator.php` (requested-locale suffixed attributes), docs, and this plan. The worker's drive-by `ConfigRoleGate` refactor was reverted.
- `EmailBatchExporter::export(Post, array): EmailBatchExportResult` validates format, locales (default all configured), recipients (1..cap, safe unique IDs), and registered value-map keys while preserving arbitrary mixed values for custom formatters. It checks the real single-row translation architecture through `TranslationStatusService`: every requested locale must have complete persisted title/block content, and one export creates the exact N×locales matrix in one ZIP. This adapts the plan's stale “matching translation row” wording to the current single-row model and records the conflict for later agents.
- Every recipient/locale render uses a strict runtime context. Resolution failures are collected across the complete matrix before any ZIP is allocated and rethrown once with safe contextual keys (`recipient/locale/variable`) and reasons only. HTML uses preview+absolute URLs; EML uses CID embeds and configured From. Zip open/add/finalize failures clean up; successful HTTP responses use `deleteFileAfterSend(true)`. DTO remains only `path/fileCount/recipientCount/locales`.
- `EmailBatchExportController` wraps the exporter behind `Gate::forUser(...)->authorize('generateEmailBatch', $post)` (LocalDevRoleGate already wrapped inside `PostPolicy`). JSON-only body; success is `Symfony\Component\HttpFoundation\BinaryFileResponse` with `Content-Type: application/zip`, `Content-Disposition: attachment`, `deleteFileAfterSend(true)` so the temp zip is unlinked in the same `finally` that streamed it. Every failure surfaces as a controlled `{message}` 422; runtime values never appear in any error body.
- `PostPolicy::generateEmailBatch` is a single, three-part check — `email.generate` tier + `$post->type === 'email'` + `$post->status === 'published'` — exactly the locked decision §9. The controller 404s a non-email post before the Gate fires (so a forged body never reaches the policy for the wrong document type).
- **RED → GREEN:** worker REDs were missing exporter classes and a missing HTTP route. Parent review added failing coverage for one-call N×locales, custom object values, cross-matrix failure aggregation, and locale-suffixed interpolation. Final Task 6 focused exporter/controller → `OK (35 tests, 76 assertions)`; focused exporter/controller/interpolator → `OK (64 tests, 127 assertions)`; `tests/Email` → `OK (184 tests, 526 assertions)`; baseline scope → `OK (202 tests, 582 assertions)`.
- **Scope preserved:** no `Mail::send`, no SMTP config, no recipient persistence / list derivation, no campaign tables, no `RoleGate::rolesOf()` enumeration as a recipient source, no editor chrome changes, no picker diff, no Pencil revisit, no `EmailRenderer` change, no mailable change, no doc/style-table change. Every failure path uses the same value-free message shape the existing interpolator + preview controller already established.
- **Review outcome:** fresh MiniMax-M3 spec review caught the custom-object rejection; the quality review was interrupted after live inspection. Parent review additionally corrected one-call locale generation, cross-matrix aggregation, JSON enforcement/error details, service injection, role-gate scope drift, ZIP return checks/cleanup, and binary test output. Task 8 still performs final whole-feature review.

### Task 7 — docs/examples (GREEN, docs-only)
- **Paths created:** `examples/EmailVariables/MoneyEmailVariableType.php`, `examples/EmailVariables/AppServiceProvider.php`, `examples/EmailVariables/BatchExport.php`.
- **Paths modified:** `docs/email-system.md` (closed three stale "Task 7 pending" lines; added a dedicated §6.8 "Host seam, roles, and personalization lifecycle" cheat sheet covering registration, custom formatters, mandatory non-secret samples, exact flat runtime maps with arbitrary value objects, renderer/mailable host-SMTP seam vs Heisenberg admin batch ZIP/no SMTP, RoleGate tiers with host-remappable `email.generate`, recipients as explicit maps (N = count), one-call default all configured locales on the single-row translation model with `EmailBatchTranslationMissingException` for incomplete locales, strict unknown/missing/formatter/target aggregation, interpolation-before-rich-text/safeUrl, sample-only author-facing GETs, signed personalized browser links as host responsibility, queued mailable pre-render / queue-safe construction, no recipient migration/product), and this plan.
- `MoneyEmailVariableType` implements `\Heisenberg\Contracts\EmailVariableType` exactly (`key()`, `targets()`, `format()`); declares a host placeholder `Money` value object under a clearly-labeled `Host\` namespace so the file is self-contained and `php -l` clean.
- `AppServiceProvider` injects `EmailVariableRegistry` in `boot(...)` via the registered singleton, calls `registerType()` first, then `register()` with `text`, `url`, custom `money`, and `email` definitions, including a non-scalar `Money` sample for the custom formatter and a non-secret `https://example.test/unsubscribe/sample` placeholder for the URL.
- `BatchExport` calls `app(EmailBatchExporter::class)->export($email, [...])` exactly as the HTTP route does, consumes `EmailBatchExportResult` (`path` / `fileCount` / `recipientCount` / `locales`), passes the exact `{id, values}` recipients shape, and never calls `Mail::send`. Handles `EmailBatchTranslationMissingException` (structural) and `EmailVariableResolutionException` (aggregated) separately; the resolver's `getFailures()` table is the admin-facing error contract. Locales are intentionally omitted from the example options array so the one-call default (`LocaleConfig::locales()`) is on display.
- **Docs truthfulness:** README now has a concise email-personalization/host-seam section linking the full email-system doc and compile-checked examples. Stale "Task 7 pending" wording in `docs/email-system.md` is closed; the §7 Waves status lists Tasks 0–7 landed and Task 8 review pending.
- **Verification commands and outcomes (Task 7):**
  - `php -l examples/EmailVariables/MoneyEmailVariableType.php` → `No syntax errors detected`.
  - `php -l examples/EmailVariables/AppServiceProvider.php` → `No syntax errors detected`.
  - `php -l examples/EmailVariables/BatchExport.php` → `No syntax errors detected`.
  - `git diff --check` on the modified docs/email-system.md and the three examples: clean (no conflict markers, no trailing whitespace).
  - Targeted searches: `grep -nE "(Mail::send|smtp|campaign|subscriber)" examples/EmailVariables/` and `docs/email-system.md` §6.7/§6.8 confirm only the negative-form claims ("Heisenberg still does not own SMTP", "No `Mail::send`", "no subscriber / campaign / scheduling tables", etc.) and zero `Mail::send` / SMTP-config / `RoleGate::rolesOf` enumeration as a recipient source.
- **Scope preserved:** no production code modified, no tests modified, no `EmailRenderer` / `HeisenbergMailable` / `HeisenbergServiceProvider` / `PostPolicy` / `EditorController` / `routes/*` touched, no SMTP / mailer config / recipient table / scheduler added, no `git add -A`, no commit (parent commits after reviewing Tasks 7+8).
- **Risks remaining at end of Task 7:** the docs/examples still describe the system at the moment the bundle shipped. Task 8 (two-stage spec/quality/security review) is the only remaining task and may surface further wording tweaks. The three examples are intentionally copy-paste, NOT load-bearing in the test suite — the test suite already pins every API surface they demonstrate.

#### Original Task 7 checklist criteria fulfilled by this implementation
- [x] Documentation covers registration during host provider boot (`§6.8 Registration` + `examples/EmailVariables/AppServiceProvider.php`).
- [x] Documentation covers built-in types and custom formatter contract (`§6.2` table + `§6.8 Built-in formatters and custom formatters` + `examples/EmailVariables/MoneyEmailVariableType.php`).
- [x] Documentation covers required non-secret samples (`§6.8 Mandatory non-secret samples`).
- [x] Documentation covers exact flat runtime map with arbitrary custom value objects (`§6.8 Exact flat runtime maps with arbitrary custom value objects`).
- [x] Documentation covers mailable (host SMTP) vs batch zip (Heisenberg, no SMTP) (`§6.8 Renderer/mailable host-SMTP seam vs Heisenberg admin batch zip`).
- [x] Documentation covers `RoleGate` tiers, host remaps `email.generate` (`§6.8 RoleGate tiers, host remappable email.generate`).
- [x] Documentation covers recipients as explicit maps (N = count) (`§6.8 Recipients are explicit maps, not Heisenberg users`).
- [x] Documentation covers one-call default all configured locales on single-row model; incomplete locale fails that pair (`§6.8 One-call default: every configured locale`).
- [x] Documentation covers strict missing/unknown/formatter/target failures (`§6.8 Strict unknown / missing / formatter / target failures`).
- [x] Documentation covers escaping and URL safety; why values resolve before sanitization (`§6.8 Interpolation-before-rich-text / safeUrl`).
- [x] Documentation covers preview/single-export sample semantics (`§6.8 Sample-only preview / single export`).
- [x] Documentation covers host responsibility for signed personalized browser links (`§6.8 Signed personalized browser links are host responsibility`).
- [x] Documentation covers queued mail: queue-safe values; no implicit model lookup (`§6.8 Queued mailable: queue-safe values, no implicit lookup`).
- [x] Documentation covers no database migration for recipients (`§6.8 No recipient migration / database / product surface`).
- [x] Examples use actual APIs: `EmailVariableType` (formatter contract), `EmailVariableRegistry::registerType/register` (AppServiceProvider), `EmailBatchExporter::export` + `EmailBatchExportResult` (`path/fileCount/recipientCount/locales`) (BatchExport). No fake package APIs/classes. Host-domain placeholder classes are clearly identified in comments/type examples and pass `php -l`.
- [x] Examples verify cleanly with `php -l` (three out of three pass).
- [x] No Task 8 work, no broad-suite rerun, no production/test modification, no commit.

---

## Locked product decisions (read before coding)

---

## Locked product decisions (read before coding)

### Heisenberg still does not send mail

No SMTP config, no mailer connection UI, no queue worker for delivery, no open/click tracking. `HeisenbergMailable` stays an **optional** host convenience for `Mail::send()`. Heisenberg’s own “get mail out” path is **file export** (existing single-document HTML/EML, plus the new admin batch zip).

### Roles: same `RoleGate` as the rest of the package — not a mailing list

The host already maps its roles onto Heisenberg’s canonical vocabulary in `config('heisenberg.roles')`: `admin`, `editor`, `author`, and `viewer` (the “normal user”). Policies ask for **tiers** (`admins`, `editors`, `authors`, `email.generate`), never `App\Models\User` and never `HeisenbergUser` as a recipient.

| Actor | Email template (existing PostPolicy + lifecycle) | Variables in editor | Single sample preview/export | Personalized batch generate/export |
|---|---|---|---|---|
| `admin` (tier `admins` / `email.generate`) | Full, including delete | Yes | Yes | **Yes — the only default** |
| `editor` (tier `editors`) | Author + publish (`lifecycle.role_permissions.published`) | Yes if they can `view`/`update` | Yes (`view`) | No unless host remaps `email.generate` |
| `author` (tier `authors`) | Own drafts; cannot publish | Yes on emails they may `update` | Yes if they may `view` | No |
| `viewer` / guest (“normal user”) | Published slug “view in browser” only | No picker | No editor export | No |

Do **not** enumerate Heisenberg users as recipients. `HeisenbergUser` is the editor/auth actor (`getAuthIdentifier()` only). There is no user directory in this package; `RoleGate` cannot list a mailing list. The admin (or the host job calling the service) **supplies** the exact recipient value maps and the count N. Heisenberg generates **exactly those N × locales** files and nothing else.

### Locales: generate every requested locale that exists

Default install locales are `LocaleConfig::locales()` (`en` and `fr` unless the host overrides). Batch export defaults to **all of those locales**, not “the UI locale.” For each recipient × locale pair, render the matching translation row of the email (`posts.locale` + shared slug). If the admin asked for a locale that has no persisted translation, **fail that pair** (aggregated with other errors) — do not silently ship the default-locale body labeled as French.

A recipient row may include a host `locale` **variable** (for copy like `{{ user.locale }}`). That is data. The **render locale** is the batch’s requested locale list, not inferred from the value map unless the host only puts that recipient in one batch call.

### Recipients stay a flat map

```php
['user.first_name' => $user->first_name, 'unsubscribe_url' => $url]
```

No Eloquent introspection. Keys must be registered. Values are never persisted on the email post.

---

## Scope and non-goals

### In scope

- Dotted tokens such as `{{ user.first_name }}`, `{{ user.email }}`, `{{ campaign.name }}`, and `{{ unsubscribe_url }}`.
- Host registration of arbitrary keys, labels, groups, descriptions, safe sample values, formatter types, and formatter options.
- Host registration of arbitrary custom data types through a public formatter contract.
- Per-recipient values at render/send/batch-export time; values are never persisted into the email post.
- Subject, rich text, plain text, button/image URLs, alt/caption text, quote/list fields, and other email-safe string attributes.
- A searchable **email-only** variable picker (own Blade component, not the theme-token menu).
- Strict failures and security tests proving interpolation occurs before context-specific sanitization.
- `RoleGate` + config tier `email.generate` (default `['admin']`) for batch generation.
- Admin batch: host-supplied recipient maps → zip of personalized `.html` and/or `.eml` per recipient per locale. Cap N. No SMTP.

### Explicitly out of scope

- Subscriber/user models, recipient list storage, campaigns, campaign status, scheduling, queues-as-product, **SMTP configuration**, tracking, analytics, unsubscribe persistence, or delivery retries.
- Using `HeisenbergUser` / `RoleGate::rolesOf()` as the set of people to email.
- Logic tags, loops, conditions, filters, expressions, raw HTML variables, or arbitrary PHP evaluation.
- Variables in numeric, boolean, enum, layout, CSS/support, class, anchor, block id/name, schema, or structural fields.
- Database migrations for variables or recipients. Definitions are host code; per-recipient values are runtime-only. Batch output is a download (or service return), not a new table.
- ESP-specific merge syntax. Heisenberg resolves its own variables; sample preview HTML/EML contains registered samples; batch files contain runtime values.

---

## Locked API decisions

1. **Syntax:** only `{{ dotted.key }}` with optional surrounding whitespace. Keys match a conservative dotted identifier pattern; reserved BlockRenderer roots (`id`, `name`, `attributes`, `supports`, `lang`) cannot be registered.
2. **Explicit values:** runtime values use an exact flat map. Do not introspect Eloquent models or arbitrary object paths.
3. **Formatter contract:** every type formats one PHP value to a string and declares compatible output targets (`text`, `url`, optionally `email`). A host may register any additional type implementation.
4. **Definitions:** each variable definition has `key`, `label`, `type`, `sample`, optional `group`, `description`, and `options`. A non-secret sample is required so editor preview, public preview, size measurement, and **non-batch** exports remain deterministic.
5. **Strict runtime behavior:** real render / mailable / batch aggregate and throw for unknown tokens, missing values, formatter failures, and target/type incompatibility. Never send or zip literal unresolved tokens; never silently substitute an empty string.
6. **Security ordering:** values are formatted and substituted before the existing block renderer evaluates URL schemes or sanitizes rich text. Rich-text replacements are escaped text—not trusted markup.
7. **Backward compatibility:** existing emails without variables render byte-for-byte. Keep `EmailRenderer::render(Post $email, string $locale, bool $preview = false, ...)` positional compatibility; add variables as the fourth parameter. Keep the existing two `HeisenbergMailable` constructor arguments; add an optional third values/context argument.
8. **Preview vs batch:** built-in GET preview/size/single export resolve **samples** only and stay on PostPolicy `view`. Recipient-aware output is either the host calling `EmailRenderer` / `HeisenbergMailable` with a runtime map, or the **admin batch** endpoint/service with explicit maps. Never accept runtime maps on public GET query strings.
9. **Authorization:** batch generate uses `RoleGate` tier `email.generate` (config, default role string `admin`). Also require the email `type = 'email'` and `status = 'published'` (docs: published means ready to send). Do not add a parallel ACL. Wrap with `LocalDevRoleGate` the same way `PostPolicy` does.
10. **File placement:** do **not** create `src/Email/`. Follow the existing tree: `Contracts/`, `Support/`, `Services/`, `Mail/VariableTypes/`, `Http/Controllers/`. Do **not** extend `variable-menu.blade.php` (theme tokens). Add `email-variable-menu.blade.php`.

## Target public usage

```php
use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;

// Host AppServiceProvider::boot(...)
public function boot(EmailVariableRegistry $variables): void
{
    $variables->registerType(app(MoneyEmailVariableType::class));

    $variables->register(new EmailVariableDefinition(
        key: 'user.first_name',
        label: 'First name',
        type: 'text',
        sample: 'Tedy',
        group: 'User',
    ));

    $variables->register(new EmailVariableDefinition(
        key: 'account.balance',
        label: 'Account balance',
        type: 'money',
        sample: Money::NGN(250000),
        group: 'Account',
        options: ['currency' => 'NGN'],
    ));

    $variables->register(new EmailVariableDefinition(
        key: 'unsubscribe_url',
        label: 'Unsubscribe URL',
        type: 'url',
        sample: 'https://example.test/unsubscribe/sample',
        group: 'Campaign',
    ));
}
```

```php
use Heisenberg\Mail\HeisenbergMailable;

// Host sends (host SMTP). Heisenberg does not.
Mail::to($user->email)->send(new HeisenbergMailable(
    $emailPostId,
    $user->locale,
    [
        'user.first_name' => $user->first_name,
        'account.balance' => $account->balanceMoney(),
        'unsubscribe_url' => URL::signedRoute('unsubscribe', ['user' => $user->getKey()]),
    ],
));
```

```php
use Heisenberg\Services\EmailBatchExporter;

// Admin / host job: generate files only. Count = count($recipients). Locales = en+fr by default.
$zipPath = app(EmailBatchExporter::class)->export($publishedEmailPost, [
    'format' => 'eml', // or html
    'locales' => \Heisenberg\Support\LocaleConfig::locales(),
    'recipients' => [
        ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://…']],
        ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://…']],
    ],
]);
```

The package receives final values explicitly. It does not fetch the user, campaign, account, or unsubscribe record. It does not discover recipients from `RoleGate`.

---

### Task 0: Capture the baseline and acceptance matrix

**Objective:** Prove the current email system is green and freeze the exact behavioral contract before changing production code.

**Files:**
- Read: `docs/email-system.md`
- Read: `config/heisenberg.php` (`email`, `roles`, `lifecycle.role_permissions`)
- Read: `src/Contracts/RoleGate.php`, `src/Policies/PostPolicy.php`
- Read: `src/Services/EmailRenderer.php`
- Read: `src/Services/BlockRenderer.php`
- Read: `src/Mail/HeisenbergMailable.php`
- Read: `src/Http/Controllers/EmailPreviewController.php`
- Read: `src/Http/Controllers/EditorController.php`
- Read: `resources/views/components/live/pickers/variable-menu.blade.php` (do not modify in later tasks except to prove it is unchanged)
- Read: `tests/Email/*`
- Read: `tests/Editor/EmailEditorWiringTest.php`

**Step 1: Verify the clean baseline**

```bash
vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php
```

Expected: all current email and email-editor tests pass before a feature test is added.

**Step 2: Record acceptance cases in the implementing worker’s notes**

Required vertical slices:

- Same template, two value maps, two different personalized results.
- Subject + rich text + plain text + URL resolve consistently.
- Host custom type formats a non-scalar object.
- Missing/unknown value prevents send and batch zip.
- Malicious HTML becomes text; `javascript:` URL is removed/rejected by the existing URL gate.
- Samples power preview/single export; runtime values never appear in editor HTML.
- Existing email with no tokens remains unchanged.
- Author can insert tokens; viewer cannot hit batch; admin batch with 2 recipients × 2 locales yields 4 files.
- Theme-token `variable-menu` `varselect` payload unchanged.

No commit in this task.

---

### Task 1: Add the host-extensible variable registry and formatter contract

**Objective:** Establish a small public API for host-defined keys and arbitrary value types without introducing host-domain models.

**Files:**
- Create: `src/Contracts/EmailVariableType.php`
- Create: `src/Support/EmailVariableDefinition.php`
- Create: `src/Support/EmailVariableContext.php`
- Create: `src/Services/EmailVariableRegistry.php`
- Create: `src/Mail/VariableTypes/TextEmailVariableType.php`
- Create: `src/Mail/VariableTypes/UrlEmailVariableType.php`
- Create: `src/Mail/VariableTypes/EmailAddressEmailVariableType.php`
- Create: `src/Mail/VariableTypes/NumberEmailVariableType.php`
- Create: `src/Mail/VariableTypes/BooleanEmailVariableType.php`
- Create: `src/Mail/VariableTypes/DateEmailVariableType.php`
- Create: `tests/Email/EmailVariableRegistryTest.php`
- Modify: `src/HeisenbergServiceProvider.php`

Do **not** create `src/Email/`. Built-in types live under `Mail/VariableTypes/` next to `HeisenbergMailable`. They are a formatter catalog, not host-swappable `Adapters/`.

**Public contract shape:**

```php
interface EmailVariableType
{
    public function key(): string;

    /** @return list<'text'|'url'|'email'> */
    public function targets(): array;

    public function format(
        mixed $value,
        EmailVariableDefinition $definition,
        string $locale,
    ): string;
}
```

`EmailVariableContext` is immutable and stores the exact flat runtime map plus a named mode (`runtime` or `sample`). It must not contain users/models, access the container, serialize values, or persist anything.

**Step 1: RED — registry/definition tests**

- Built-in type keys exist once.
- A host can register a custom formatter type and a variable using it.
- Definitions serialize to editor-safe metadata without formatter objects or runtime values.
- Duplicate keys/type keys fail deterministically instead of silently overriding.
- Invalid/reserved keys fail.
- Unknown formatter types fail when a definition is registered.
- Sample context contains only registered samples.
- Samples may be non-scalar when a custom formatter accepts them.

```bash
vendor/bin/phpunit tests/Email/EmailVariableRegistryTest.php
```

Expected: FAIL because the classes do not exist.

**Step 2: GREEN — minimal registry implementation**

- Register built-in types when the singleton is created.
- Bind `EmailVariableRegistry` as a singleton in `HeisenbergServiceProvider::registerEngine()` next to `EmailRenderer`.
- Keep registration programmatic so host providers can inject the registry in `boot()`.
- Keep type formatting locale-aware, but do not add `ext-intl`.
- Validate metadata and targets centrally in the registry.

**Step 3: Verify**

```bash
vendor/bin/phpunit tests/Email
```

**Step 4: Commit narrowly**

```bash
git add src/Contracts/EmailVariableType.php src/Support/EmailVariableDefinition.php src/Support/EmailVariableContext.php src/Services/EmailVariableRegistry.php src/Mail/VariableTypes tests/Email/EmailVariableRegistryTest.php src/HeisenbergServiceProvider.php
git commit -m "feat(email): add host variable registry"
```

---

### Task 2: Implement context-aware, strict interpolation before sanitization

**Objective:** Resolve typed values safely in a copied subject/block tree before the existing rendering/security pipeline.

**Files:**
- Create: `src/Services/EmailVariableInterpolator.php`
- Create: `src/Support/EmailVariableResolutionException.php`
- Create: `tests/Email/EmailVariableInterpolatorTest.php`
- Modify only if required by the minimal API: `src/Support/EmailVariableContext.php`
- Read, do not broadly refactor: `src/Services/BlockRenderer.php`

**Interpolation algorithm:**

1. Extract only valid `{{ dotted.key }}` tokens.
2. Resolve each token against a registered definition and the selected context.
3. Ask the definition’s formatter type to produce a string for the target (`text`, `url`, or `email`).
4. For `email.template` attributes consumed by `rich-text` nodes, HTML-escape the replacement before the block renderer sanitizes the authored rich-text string.
5. For URL attributes, substitute the raw formatted URL before `BlockRenderer::safeUrl()` runs.
6. For ordinary string attributes, substitute raw text and let the existing text/attribute escaping run.
7. Recursively copy `innerBlocks`; never mutate Eloquent models or persisted block content.
8. Resolve the subject through the text target.
9. Aggregate all resolution errors and throw one exception containing keys/reasons only—never values.

Use `BlockRegistryService` to discover which email-template attributes are rich text and which contract attribute definitions are URLs. Do not duplicate a hardcoded list of heading/paragraph/button fields.

`EmailRenderer::textFor()` walks the **raw** attribute tree. The interpolator must therefore run on the copied tree **before** `textFor()` and **before** `renderBlock()`.

**Step 1: RED** — one vertical slice at a time (plain text, repeated tokens, escaped rich text, URL, `javascript:` rejection, custom Money, locale to formatter, aggregated failures, tokens outside attributes/subject ignored).

**Step 2: GREEN** — do not modify `BlockRenderer` to understand recipient data.

**Step 3: Verify**

```bash
vendor/bin/phpunit tests/Email/EmailVariableInterpolatorTest.php
vendor/bin/phpunit tests/Email
```

**Step 4: Commit**

```bash
git add src/Services/EmailVariableInterpolator.php src/Support/EmailVariableResolutionException.php src/Support/EmailVariableContext.php tests/Email/EmailVariableInterpolatorTest.php
git commit -m "feat(email): resolve typed template variables"
```

---

### Task 3: Wire personalization through EmailRenderer and HeisenbergMailable

**Objective:** Make the existing renderer/mailable usable per recipient while preserving all existing calls.

**Files:**
- Modify: `src/Services/EmailRenderer.php`
- Modify: `src/Mail/HeisenbergMailable.php`
- Modify: `src/HeisenbergServiceProvider.php`
- Modify: `tests/Email/EmailRendererTest.php`
- Modify: `tests/Email/HeisenbergMailableTest.php`

**Required signatures:**

```php
public function render(
    Post $email,
    string $locale,
    bool $preview = false,
    ?EmailVariableContext $variables = null,
): EmailRenderResult
```

```php
public function __construct(
    int|string $postId,
    ?string $locale = null,
    array|EmailVariableContext $variables = [],
)
```

An omitted renderer context means a strict empty runtime context. The mailable normalizes a plain array into a strict runtime context. `HeisenbergMailable` is still not `ShouldQueue` by default; document that queued hosts must pass queue-safe scalars/DTOs.

**Step 1–2: RED** — fixture with subject, rich text, button label, button URL; two contexts; CID; `sizeBytes`; stored `Block::content` still tokenized; missing values throw before send construction.

**Step 3: GREEN** — read block content into arrays; interpolate a copied tree and subject once; feed the copy to `capColumns()`, `BlockRenderer`, image rewriting, token resolution, shell/inlining, and `textFor()`. Do not replace final HTML strings.

**Step 4: Verify** then commit:

```bash
git add src/Services/EmailRenderer.php src/Mail/HeisenbergMailable.php src/HeisenbergServiceProvider.php tests/Email/EmailRendererTest.php tests/Email/HeisenbergMailableTest.php
git commit -m "feat(email): personalize rendered mail per recipient"
```

---

### Task 4: Use safe sample contexts for preview, size, and single-document exports

**Objective:** Keep author-facing GET endpoints deterministic without accepting recipient data on public/editor GET routes.

**Files:**
- Modify: `src/Http/Controllers/EmailPreviewController.php`
- Modify: `tests/Email/EmailPreviewControllerTest.php`
- Modify: `tests/Email/EmailExportControllerTest.php`
- Modify if needed: `src/Support/EmailVariableResolutionException.php`

Every controller render call (`showBySlug`, size, HTML export, EML export) must pass `EmailVariableContext::samples(...)` explicitly. Do not make the renderer’s default mode “sample”. Unknown tokens → controlled 422 (keys/reasons only). Runtime values are not accepted from query parameters. Token-free exports remain unchanged.

**Commit:**

```bash
git add src/Http/Controllers/EmailPreviewController.php tests/Email/EmailPreviewControllerTest.php tests/Email/EmailExportControllerTest.php
git commit -m "feat(email): preview registered variable samples"
```

---

### Task 5: Expose registered variables in an email-only insertion UI

**Objective:** Let authors insert valid host-registered tokens without a second editor architecture or a build step.

**Files:**
- Create: `resources/views/components/live/pickers/email-variable-menu.blade.php`
- Modify: `src/Http/Controllers/EditorController.php`
- Modify: `resources/views/editor/index.blade.php`
- Modify: `resources/views/components/live/canvas.blade.php`
- Modify: `resources/views/components/live/inspector/post-title-summary.blade.php`
- Modify: `resources/views/components/live/toolbar/block-toolbar.blade.php`
- Modify only for stable target metadata if needed: `resources/views/components/live/block/content.blade.php`
- Modify: `resources/lang/en/editor.php`
- Modify: `resources/lang/fr/editor.php`
- Modify: `tests/Editor/EmailEditorWiringTest.php`
- Modify: `tests/Editor/StylePanelGatingTest.php` only if proving the theme-token menu is byte-stable (prefer asserting it still mounts `mode="color"` / `mode="number"` and `varselect` `{ name, value }`).

**Do not modify** `resources/views/components/live/pickers/variable-menu.blade.php`. That component is the Style-panel CSS token picker (`color` | `number`, swatches, `varselect` with resolved token values). Email merge tags are a different product: groups, types, targets, insert the literal `{{ dotted.key }}`.

**UI structure:**

- New picker, email documents only, only when the registry has definitions. Root: `data-hb-email-variable-picker`. Reuse existing `hb-varmenu` / `hb-vmi` **CSS classes** if the visual match is cheap; do not share the theme-token boot script.
- Pass only editor-safe definition metadata (`key`, localized label already resolved server-side, group, description, type, targets, formatted sample). Never serialize runtime values, formatter objects, closures, host classes, or secrets.
- Triggers beside canvas subject, inspector subject mirror, and selected-block toolbar.
- Track last eligible insertion target: subject → `text`; `.hb-ce[data-hb-rt]` → `text`; string/rich-text settings → `text`; URL settings → `url`.
- Exclude anchor/class/style/support, selects, booleans, numbers/ranges, enums, chips, structural fields, and non-email documents.
- Filter picker entries by active target vs formatter `targets()`.
- Search by label, key, and group; keyboard navigation; Escape; focus restoration; empty state.
- Insert the literal token as text (`setRangeText` / Range text nodes, never `innerHTML`), then dispatch existing `input` so `hbEditor` persists the token.
- Gate the picker the same way the email editor is gated: if the actor cannot `update` the document, no insertion UI (read-only). Do not invent a new policy class for the picker.

**Verify:**

```bash
vendor/bin/phpunit tests/Editor/EmailEditorWiringTest.php tests/Email
```

Manual Testbench pass: register text + url + custom money; insert; save/reload/locale switch; preview samples; two mailables differ.

**Commit:**

```bash
git add src/Http/Controllers/EditorController.php resources/views/editor/index.blade.php resources/views/components/live/pickers/email-variable-menu.blade.php resources/views/components/live/canvas.blade.php resources/views/components/live/inspector/post-title-summary.blade.php resources/views/components/live/toolbar/block-toolbar.blade.php resources/views/components/live/block/content.blade.php resources/lang/en/editor.php resources/lang/fr/editor.php tests/Editor/EmailEditorWiringTest.php
git commit -m "feat(editor): insert host email variables"
```

---

### Task 6: Admin batch generate and export (no SMTP)

**Objective:** Let an admin (tier `email.generate`) produce exactly N personalized files per requested locale from host-supplied value maps. Heisenberg does not send them.

**Files:**
- Modify: `config/heisenberg.php` — `email.batch_max_recipients` (default `100`) and `roles.email.generate` => `['admin']`.
- Create: `src/Services/EmailBatchExporter.php`
- Create: `src/Support/EmailBatchExportResult.php` (path, counts, locale list — no recipient values).
- Create: `src/Http/Controllers/EmailBatchExportController.php`
- Create: `src/Http/Requests/EmailBatchExportRequest.php` (or validate in controller if that matches nearby email code).
- Modify: `src/Policies/PostPolicy.php` — add `generateEmailBatch(Authenticatable $user, Post $post): bool` using `LocalDevRoleGate` + `email.generate` + `$post->type === 'email'` + `$post->status === 'published'`.
- Modify: `routes/email.php` and/or `routes/editor.php` — authenticated editor-stack route, **not** the public slug group. Suggested: `POST /editor/email/{post}/batch-export` behind `heisenberg.middleware.editor`.
- Modify: `src/HeisenbergServiceProvider.php` — bind exporter.
- Create: `tests/Email/EmailBatchExporterTest.php`
- Create: `tests/Email/EmailBatchExportControllerTest.php`

**Service contract:**

```php
public function export(Post $email, array $options): EmailBatchExportResult
```

`$options`:

- `format`: `eml` | `html` (one format per call; host calls twice if they want both).
- `locales`: list of locale strings; default `LocaleConfig::locales()`. Invalid locale → 422.
- `recipients`: list of `{ id: string, values: array<string, mixed> }`. `id` is filename-safe (slug). Length 1..`email.batch_max_recipients`. Empty list → 422. Over cap → 422.

**Zip layout:**

```text
{slug}/
  {locale}/
    {id}.eml   # or .html
```

HTML batch uses the same preview/absolutize behavior as single HTML export. EML batch uses the real CID render, same From rules as `EmailPreviewController::exportEml` (422 if `mail.from.address` missing for EML). Interpolation uses a **runtime** context per row. One aggregated `EmailVariableResolutionException` if any row fails; no partial zip of successful rows (all-or-nothing), so an admin never ships a truncated campaign.

**HTTP:**

- `Gate::authorize('generateEmailBatch', $post)`.
- Body is JSON, not query string.
- Success: `Content-Type: application/zip`, `Content-Disposition: attachment`.
- Author/editor/viewer without `email.generate` → 403.
- Draft email → 403 (not published).
- Tests must use a real Authenticatable + RoleGate map, not only GuestActor local bypass: at least one case with `role = author` denied and `role = admin` allowed (and one `editor` denied under default config).

**Do not:**

- Call `Mail::send()`, configure SMTP, or persist the zip.
- Load users from the database inside the exporter.
- Fan-out from `RoleGate` membership.

Editor chrome for the button is **optional in this task** if a focused PHPUnit HTTP test covers the route. If a button is added, show it only on email documents when the server says `canGenerateEmailBatch` (from the same policy), never for authors by default.

**Verify:**

```bash
vendor/bin/phpunit tests/Email/EmailBatchExporterTest.php tests/Email/EmailBatchExportControllerTest.php tests/Email
```

**Commit:**

```bash
git add config/heisenberg.php src/Services/EmailBatchExporter.php src/Support/EmailBatchExportResult.php src/Http/Controllers/EmailBatchExportController.php src/Http/Requests/EmailBatchExportRequest.php src/Policies/PostPolicy.php routes src/HeisenbergServiceProvider.php tests/Email/EmailBatchExporterTest.php tests/Email/EmailBatchExportControllerTest.php
git commit -m "feat(email): admin batch export personalized files"
```

---

### Task 7: Document the host seam, roles, and personalization lifecycle

**Objective:** Make the supported integration obvious: variables + admin file factory; host still sends.

**Files:**
- Modify: `docs/email-system.md`
- Modify: `README.md` (only the email/host-seam paragraphs that would otherwise lie)
- Create: `examples/EmailVariables/MoneyEmailVariableType.php`
- Create: `examples/EmailVariables/AppServiceProvider.php`
- Create: `examples/EmailVariables/BatchExport.php`
- Add/modify a docs assertion only if the repository already uses such tests.

**Documentation must cover:**

- Registration during host provider boot.
- Built-in types and custom formatter contract.
- Required non-secret samples.
- Exact flat runtime map.
- Mailable (host SMTP) vs batch zip (Heisenberg, no SMTP).
- `RoleGate` tiers: who authors, who publishes, who batch-exports; host remaps `email.generate`.
- Recipients are not Heisenberg users; N is the length of the admin-supplied list.
- Locales: default all `LocaleConfig::locales()`; missing translation fails that pair.
- Strict missing/unknown behavior.
- Escaping and URL safety; why values resolve before sanitization.
- Preview/single-export sample semantics.
- Host responsibility for signed personalized browser links.
- Queued mail: queue-safe values; no implicit model lookup.
- No database migration for recipients.

**Verify examples:**

```bash
php -l examples/EmailVariables/MoneyEmailVariableType.php
php -l examples/EmailVariables/AppServiceProvider.php
php -l examples/EmailVariables/BatchExport.php
```

**Commit:**

```bash
git add docs/email-system.md README.md examples/EmailVariables
git commit -m "docs(email): document host personalization and admin batch export"
```

---

### Task 8: Full verification and two-stage review

**Objective:** Prove the feature is complete, secure, backwards compatible, and limited to the intended package boundary.

**Step 1:** `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php`

**Step 2:** `vendor/bin/phpunit`

**Step 3:** `composer validate --strict` and `php -l` on interpolator, registry, renderer, mailable, batch exporter, batch controller.

**Step 4–5:** Spec-compliance review, then code-quality/security review (fresh MiniMax M3 workers). Trace token → definition → value → formatter → substitution → sanitization/`safeUrl` → CID → zip; RoleGate on batch; no value leakage in exceptions; theme-token picker untouched.

**Step 6:** `git status --short`, `git diff --check`, `git log --oneline -10`.

---

## MiniMax M3 execution strategy

```bash
hermes --provider minimax-oauth -m minimax-m3 --worktree --skills test-driven-development -z "<self-contained task prompt>"
```

1. One fresh worker per task/commit; no unstated chat context.
2. Isolated worktrees; parent cherry-picks after tests pass.
3. Do not run concurrent workers that edit `EmailRenderer.php`, `HeisenbergServiceProvider.php`, `EditorController.php`, or `PostPolicy.php`.
4. Every production change starts with a focused failing test.
5. Commit only authored paths — never `git add -A`.
6. After each implementation commit: spec reviewer, then quality/security reviewer.
7. If MiniMax fails, report the error; do not silently substitute another model.

Recommended sequence: Task 0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8.

---

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Final-string replacement bypasses escaping/URL checks | Resolve into copied block attributes before `BlockRenderer`. |
| Rich-text variable injects markup | HTML-escape formatter output; custom types cannot return trusted HTML. |
| Dynamic `javascript:`/`data:` URL | Substitute before `safeUrl()`; test rejection. |
| Host typo silently ships a broken batch | Strict aggregated exception; all-or-nothing zip. |
| Preview shows a real user | GET preview/export = samples only; runtime maps only on mailable/renderer/admin POST. |
| Treating RoleGate as a recipient list | Exporter never calls `rolesOf()` to build N; N = `count(recipients)`. |
| Author triggers mass export | `email.generate` defaults to `admin`; tests for author 403. |
| SMTP config creeps in | No mailer settings; EML still uses existing `mail.from.address` only as MIME From, same as today’s single EML export. |
| Missing FR translation labeled as French | Fail that locale pair. |
| Theme-token picker breakage | New `email-variable-menu`; do not edit `variable-menu.blade.php`. |
| `src/Email/` namespace drift | Types under `src/Mail/VariableTypes/`. |
| Zip bombs / DoS | `batch_max_recipients`; published-only; editor middleware. |
| Queue serialization captures models | Document scalars/DTOs; no implicit lookup. |
| Overengineering campaigns | No recipient tables, schedulers, tracking, or send service. |

---

## Completion criteria

- [x] Host can register arbitrary variable keys and a custom formatter/data type.
- [x] Editor lists only host-registered variables on email documents; theme-token menu unchanged. (Task 5 safe metadata/gating tests; original theme picker has no diff.)
- [x] Author can insert into subject, rich text, ordinary text, and compatible URL fields. (Task 5 dynamic triggers, target filtering, text-node/setRangeText insertion, and existing input persistence path.)
- [x] Tokens persist unchanged in the stored email document. (Task 2 — `EmailVariableInterpolator` never writes back into the input tree; see `test_interpolate_blocks_does_not_mutate_input` and `test_inner_blocks_are_recursively_copied_without_mutating_inputs`.)
- [x] Two recipient value maps produce two different safe HTML/text/subject results. (Task 3 renderer and mailable tests exercise Ada/Ben contexts across all channels.)
- [x] Missing, unknown, invalid, or incompatible values prevent a real send and a batch zip. (Tasks 2–4 enforce the interpolator, renderer/mailable, and author-facing GET paths. Task 6's `EmailBatchExporterTest` exercises the batch-zip-prevented paths: unregistered-key 422, over-cap 422, missing-translation 422, per-recipient resolution 422, and the all-or-nothing cleanup that leaves no partial zip on disk.)
- [x] Preview, size, and single HTML/EML export use safe samples. (Task 4 — `tests/Email/EmailPreviewControllerTest.php` and `tests/Email/EmailExportControllerTest.php` cover preview, size, HTML export, EML export with both sample substitution AND query-string isolation.)
- [x] Admin (`email.generate`) can batch-export exactly N × requested locales files; author/editor/viewer cannot by default. (Task 6 `EmailBatchExportControllerTest` auth matrix: anonymous / author / editor / draft-email / non-email-post / admin happy-path; `editor-remaps-email-generate-to-include-them` proves host config overrides.)
- [x] Batch does not send mail and does not read Heisenberg users as recipients. (Task 6 — no `Mail::send`, no SMTP config, no `RoleGate::rolesOf()` enumeration as a recipient source; N is the admin-supplied list length and every recipient key must be registered.)
- [x] Default batch locales are `LocaleConfig::locales()`; missing translations fail that pair. (Task 6 `EmailBatchExporterTest::test_default_locales_are_all_locale_config_locales` and `::test_missing_translation_for_a_requested_locale_aggregates_a_failure`; no silent en fallback to fr label.)
- [x] Existing no-variable email behavior and MIME/CID behavior remain green. (Tasks 3, 4 and 6 preserve two-argument/three-position calls, byte-equivalent no-token output, CID embeds, and size accounting. `vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php` → `OK (202 tests, 582 assertions)`.)
- [x] No recipient values persisted, leaked to editor HTML, or included in exceptions. (Task 4 covers the no-leakage-into-render and no-leakage-into-exception paths via `tests/Email/EmailPreviewControllerTest.php::test_show_by_slug_does_not_accept_runtime_values_from_query_string` and `tests/Email/EmailExportControllerTest.php::test_422_body_never_includes_formatter_exception_message_or_stack_trace`. Task 5 closes the editor-picker loop, Task 6's `EmailBatchExportControllerTest::test_runtime_resolution_failure_leaves_no_zip_in_storage` and the DTO's narrow property set (path / fileCount / recipientCount / locales — verified by reflection in `EmailBatchExporterTest::test_result_dto_carries_path_file_count_recipient_count_and_locales_only`) closes the admin-batch loop.)
- [x] No campaign/subscriber/scheduling/SMTP/analytics feature enters the package. (Out-of-scope guard across all tasks; no Mail::send, no SMTP config, no recipient tables, no RoleGate::rolesOf() enumeration as a recipient source.)
- [x] Focused and full PHPUnit suites pass. The full-repo single-process run (PHP 8.4.22 / PHPUnit 11.5.56, memory_limit=1G, --no-progress) completed in 25:04 wall-clock with **1539 tests, 6121 assertions, 1 skipped, 0 failures, 0 errors**. The single skip is a pre-existing `BlockPersistenceTest` graceful skip when MySQL is unreachable (the run uses the in-memory SQLite default per `phpunit.xml.dist`'s own comment); the rest of the suite is deterministic green. A grouped broken-down run (memory_limit=512M, --no-progress) showed: `tests/Email` 184/526, picker+wiring 35/124, `tests/Comments` 42/138, `tests/Taxonomy`+`tests/Translation` 84/225, `tests/Engine`+`tests/Persistence` 274/950, `tests/Public`+`tests/Support`+`tests/Templates` 91/319, `tests/Media` 66/297, `tests/Ai`+`tests/Mcp`+`tests/Seo`+`tests/M0`+`tests/M1` 191/1023. Run logs at `C:/Users/Tedy Donel/AppData/Local/Temp/hb-phpunit-grouped.log`.
- [x] Spec and quality/security reviews approve the final commits. Fresh MiniMax-M3 spec + code-quality/security reviewers (2026-08-26 ~30 minutes each) returned PASS with **no P0 spec blockers** and **no blocking security/quality defects**.
- [x] Host-side usage guide ships in `docs/email-personalization.md` (8-section how-to + 3 appendices: public-surface cheat sheet, why-no-SMTP, verification commands). README + `docs/file-structure.md` cross-link it. Cross-references added to `docs/email-system.md` §7. The P1-P3 findings the reviewers left are either accepted plan risks (controller 404-before-Gate, sampleLocale fallback, key insertion order, batch export iterates the full matrix for aggregation, `MimeLogicException` brittleness in `exportEml::toString()`, picker `hb:refresh` rewalks) or already-applied parent corrections. The two reviewer verdicts are: "implementation matches the spec's locked decisions, completion criteria, and locked-out product surface" and "Ready to ship."

### Task 8 — full verification and two-stage review (GREEN)
- **Two-stage fresh MiniMax-M3 review** (final batch): spec-compliance + code-quality/security reviewers returned simultaneously on 2026-08-26 (delegation `deleg_08c11178`, ~30 minutes each, both PASS). See the prior completed-task bullets for their prioritized findings.
- **Full-repo grouped PHPUnit run**: see the completion-criteria checkbox above. The single-process full run completed after the grouped run; final authoritative count is **1539 tests, 6121 assertions, 1 skipped, 0 failures, 0 errors in 25:04** (memory_limit=1G, --no-progress).
- **`git diff --check`**: clean across the shared dirty tree.
- **No commits made**, per the original "Commit only if the user asked; if they did, use the plan's commit messages and path lists. Never `git add -A`." guard.

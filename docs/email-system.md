# Email system — design & build plan

Status: **as-built** (2026-08-12 — all four waves landed and verified; full package suite green; MIME output verified end-to-end in a reference install). Companion docs: `docs/block-schema.md` (contracts
grow an email surface), `docs/content-translation.md` (emails translate like posts).

## 1. The decision: one builder, two render targets

Emails are authored in THE SAME editor with the same block engine — a separate email builder would
duplicate the inspector, media library, AI assistant, revisions, translations and Code view for no
authoring benefit. What differs is the OUTPUT: email clients (Outlook renders with Word's engine) allow
no flexbox/grid, no CSS custom properties, no external stylesheets, no animations — table-based markup
with inline styles at ~600px is the only reliable target. So the system is: a restricted, email-safe
block palette feeding a dedicated `EmailRenderer`, beside the existing web pipeline.

## 2. Self-contained output (owner decision)

A built email **embeds everything — no URL paths for assets**:

- **Images ride as CID MIME attachments** (`cid:` references), never remote URLs and never base64
  data-URIs (Gmail/Outlook strip those). The render result carries an embeds manifest; the bundled
  Mailable attaches each part. Embedded images display even with remote-image blocking on and make no
  callback to the host.
- The renderer embeds the email-appropriate **variant** of each image (the widest ≤600px variant,
  falling back to the original only when no variant exists) — originals would bloat the message and
  Gmail clips large mails.
- **Fonts cannot be attached**: theme font tokens resolve to email-safe stacks
  (`Arial, Helvetica, sans-serif` class of fallbacks derived from the theme's families).
- **All CSS is inlined**; the only `<style>` block is a small head section for client hacks/dark-mode
  hints that inlining cannot express.
- **Hyperlinks are not assets**: buttons/anchors keep their `href`s. Only loaded resources are embedded.

## 3. Email documents

An email is a post row with `type = 'email'` (new `type` string column on the posts table, default
`'post'`). That buys revisions, autosave, locking, translations (split-row, shared slug) and AI
authoring for free. Consequences, enforced in code:

- Emails NEVER appear in: the sitemap, the public translations API's guest surface only-if-published
  logic still applies but hosts querying posts for blogs must scope — the package adds
  `scopePosts($q)` / `scopeEmails($q)` and uses `posts()` itself everywhere IT lists content
  (sitemap, MCP `list_posts` gains a `type` arg defaulting to 'post').
- Lifecycle: same statuses; "published" for an email simply means "ready to send" — sending is the
  host's act, not a Heisenberg state.
- Comments/TOC/SEO panels are meaningless for emails; the editor hides them for `type = 'email'`
  (wave E3).

## 4. Contract surface: `email` render section

A block opts into the email palette by declaring an `email` section in its contract:

```jsonc
"email": {
  "template": { /* table-based render tree, same substitution engine as render.template */ }
}
```

- Presence of `email` = the block appears in the email palette; absence = it does not. Initial
  email-safe set: heading, paragraph, image, button, separator, group (as a full-width table section),
  columns/column (rendered as table cells, capped at 2–3 columns), list, quote. Excluded: embed, icon
  (webfont/SVG dependency — revisit), and every animation/hover capability (ignored by the email
  renderer even if authored).
- `BlockContractValidator` validates the section (template shape identical to `render.template`
  rules); `BlockRegistryService` exposes surface filtering (`contractsFor('email')`).

## 5. `EmailRenderer` (beside `BlockRenderer`, never replacing it)

`render(Post $email, string $locale): EmailRenderResult` where the result is
`{html, text, subject, embeds: [{cid, path, mime}], sizeBytes}`:

1. Renders each block's `email.template` through the SAME substitution/sanitization engine.
2. Resolves every theme token to its literal value (no `var()` in output); fonts to stacks (§2).
3. Wraps content in the canonical shell: 100%-width background table → centered 600px content table,
   theme background/text colors applied literally.
4. Rewrites every image source to a `cid:` reference and records the embed (variant selection per §2).
5. Inlines all styles; leaves only the minimal head `<style>` (§2).
6. Generates the plain-text alternative from the block tree (headings, paragraphs, list markers,
   button label + URL in parentheses).
7. `subject` = the email's `title($locale)`.

## 6. Host seam (Heisenberg renders; the HOST sends)

Same posture as users/comments-before-native: no subscriber lists, no campaign scheduling, no SMTP
config in Heisenberg. Shipped: the `EmailRenderResult` service API and a bundled
`HeisenbergMailable` (constructed from a post id + locale) that sets subject/html/text and attaches
every embed via the mailer — drop it into `Mail::to(...)->send(...)` and it works. Hosts with other
mailers consume the result object directly. An MCP surface note: AI authors emails through the same
canvas path; `create_post` gains the `type` arg (draft emails only, same posture).

**E5 (landed)** does not change that send boundary. It adds host-defined template variables and an
admin batch **file** export. `RoleGate` decides who may generate the zip (`email.generate` →
canonical role `admin` by default). It does **not** decide who receives the email: Heisenberg has
no user directory, and `HeisenbergUser` is the editor actor only. The host (or the admin request
body) supplies exactly N value maps; Heisenberg writes N × locale files and stops. The host's
existing role map (`admin` / `editor` / `author` / `viewer`) already drives PostPolicy and
lifecycle for who authors and who publishes an email document (`published` = ready to generate).

### 6.2 Host variable registry (E5 — landed)

E5 Tasks 1–7 are now landed. Tasks 1–6 ship the host-extensible registry, strict interpolation, per-recipient
renderer/mailable seams, sample-only preview/export, the email-only picker, and admin batch ZIP
generation. Task 7 ships this docs pass plus three runnable copy-paste examples under
[`examples/EmailVariables/`](../examples/EmailVariables/) — a host custom formatter
(`MoneyEmailVariableType.php`), an `AppServiceProvider` boot snippet that registers types + definitions,
and a `BatchExport.php` admin command that consumes `EmailBatchExporter::export()` and the
`EmailBatchExportResult` DTO. Task 8's two-stage fresh-MiniMax-M3 review PASSED with no P0
spec blockers and no blocking security defects; the full-repo single-process PHPUnit run is
green at 1539 tests / 6121 assertions / 1 skipped / 0 failures / 0 errors (25:04 wall-clock).

**The contract a host codes against today** (everything else in this package keeps working
unchanged — Task 1 is purely additive):

```php
use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;

public function boot(EmailVariableRegistry $variables): void
{
    // 1) Register a custom formatter type first. Targets must be a non-empty,
    //    unique list containing only text, url, and/or email.
    $variables->registerType(new MoneyEmailVariableType());

    // 2) Register any number of definitions keyed by `dotted.key` (single-segment
    //    keys like `unsubscribe_url` are also allowed). A definition carries
    //    label, optional group / description, the formatter `type`, a SAFE
    //    non-secret `sample` (used by editor preview, size, and single-document
    //    HTML/EML export), and any formatter-specific `options`.
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
        sample: Money::NGN(250000),     // host's own value object — non-scalar OK
        group: 'Account',
        options: ['currency' => 'NGN'],
    ));
}
```

**Shipped built-in formatter types** (auto-registered when the registry singleton is resolved;
hosts register their own types beside them):

| Key      | Targets      | Notes                                                        |
|----------|--------------|--------------------------------------------------------------|
| `text`   | `text`       | Stringifies scalars; non-stringables throw strict.            |
| `url`    | `url`        | String URL; the existing `BlockRenderer::safeUrl()` enforces scheme policy. |
| `email`  | `email`, `url` | String email; compatible with `mailto:` URL attributes.     |
| `number` | `text`       | int/float, `options['decimals']` overrides decimal count.     |
| `boolean`| `text`       | `options['format']` ∈ `code` / `toggle` / `word`.             |
| `date`   | `text`       | `DateTimeInterface` / timestamp / ISO-8601; `options['format']` is a PHP `date()` pattern. |

**Where each piece lives:**

| Piece                                       | Path                                                  |
|---------------------------------------------|-------------------------------------------------------|
| Formatter contract                          | `src/Contracts/EmailVariableType.php`                 |
| Definition value object                     | `src/Support/EmailVariableDefinition.php`             |
| Runtime/sample immutable flat-map           | `src/Support/EmailVariableContext.php`                |
| Registry singleton + central validation      | `src/Services/EmailVariableRegistry.php`              |
| Built-in formatters (six)                   | `src/Mail/VariableTypes/{Text,Url,EmailAddress,Number,Boolean,Date}EmailVariableType.php` |
| Service-provider binding (singleton)        | `src/HeisenbergServiceProvider.php` `registerEngine()`|
| Focused registry test (16 cases)             | `tests/Email/EmailVariableRegistryTest.php`           |

**Central validation the registry enforces:**

- `$key` matches `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` — conservative (no whitespace, no
  leading/trailing/double dots, no leading digit, segments joined by single dots). Single-segment
  keys like `unsubscribe_url` are allowed because the plan's own `Target public usage` block
  registers one; multi-segment keys like `user.first_name` still work.
- Reserved BlockRenderer roots (`id`, `name`, `attributes`, `supports`, `lang`) and every dotted
  descendant of those roots are rejected.
- `$type` resolves to a registered formatter at `register()` time — an unknown type fails there,
  not silently at render time.
- Definition labels must be non-empty. Formatter targets must be a non-empty, unique list drawn
  only from `text`, `url`, and `email`.
- Duplicate `$key` and duplicate formatter `$key` both throw `InvalidArgumentException` — silent
  override is rejected so two host providers cannot accidentally claim the same key.

**Editor metadata surface** (`EmailVariableRegistry::editorMetadata()`): a JSON-serializable
list of `{ key, label, type, targets, group, description, options, sample }` — the `sample`
field is the cached formatted STRING produced when metadata is requested, never the formatter
object, the closure, the host class, or the raw value. The email-only insertion UI (Task 5)
consumes this; runtime values, types, and host code stay out of editor HTML.

**Sample context** (`EmailVariableContext::samples($registry)`): every author-facing GET —
preview, size, single-document HTML/EML export — substitutes registered samples, never runtime
values. The runtime path (`EmailVariableContext::runtime([...])`) retains the exact flat
host-supplied map and tags it `runtime`; explicit `null` remains distinguishable from a missing
key so a custom formatter may decide whether null is valid. The interpolator (§6.3) fails
loud on missing or unknown tokens.

### 6.3 Context-aware, strict interpolation (E5 — landed)

Task 2 of `.hermes/plans/2026-08-25_190059-email-template-variables.md` ships the
interpolation pipeline. The interpolator is registered as a singleton next to the
existing renderer/registry bindings (`src/HeisenbergServiceProvider.php`
`registerEngine()`), and Task 3 wires it into production `EmailRenderer::render()`.

**What it does.** Given a copied subject string and a deep-copied block tree, the
interpolator resolves every `{{ dotted.key }}` token through the registry's
definition + the supplied `EmailVariableContext`, runs the resolved value through
the definition's formatter for the substitution target (`text` / `url` / `email`),
and substitutes the formatted string back into the copy. The original input — the
Eloquent model, the persisted JSON payload, whatever the caller handed in — is
returned untouched.

**What it never does.** It does not mutate inputs, it does not persist anything, it
does not look up users or models, it does not call into `BlockRenderer`, and it does
not know whether the caller is a renderer, a mailable, or a batch exporter. The
block renderer's existing `substitute()` / `resolveAttributes()` /
`sanitizeRichText()` / `safeUrl()` methods are unchanged.

**Substitution ordering (security-critical).** Per the plan's "Interpolation
algorithm" §4–6:

- Rich-text contract attributes (`type: "rich-text"`) get an `htmlspecialchars()`
  pass on the formatted replacement BEFORE the block renderer sees the attribute.
  The renderer's `sanitizeRichText()` then sees a plain-text token, not raw markup
  — `<script>` payloads arrive as `&lt;script&gt;`.
- URL contract attributes (`type: "url"`) get the raw formatted URL. The block
  renderer's existing `safeUrl()` gate enforces scheme policy downstream, so a
  formatter that returns `javascript:alert(1)` is still rejected — the interpolator
  substitutes it raw, `safeUrl()` strips it.
- Translatable string attributes (`translatable: true`, not rich-text) — alt text,
  `titleAttr`, list content, etc. — substitute raw; the existing attribute escaping in
  `BlockRenderer::buildAttributes()` is the gate that turns `<` into `&lt;`.
- Everything else (`anchor`, `extraClasses`, `class`, `style`, `supports.*`,
  unknown contract types) is left alone. A `{{ key }}` token that happens to sit
  inside a CSS identifier slot passes through unchanged.

**Which attributes are token-aware is discovered from the contract.** No block-name
or attribute-name list is hardcoded — the same mechanism that powers the editor's
inspector (`BlockRegistryService::getBlock($name)['attributes'][$name]`) drives
the interpolation decision, so a host that adds a new block with its own
translatable / rich-text / URL attributes inherits the right escaping without
changes to the interpolator.

**Token regex.** `/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/`.
Optional surrounding whitespace is accepted, including repeated spaces or tabs. Invalid dotted
identifiers such as `{{ user.}}` and an unclosed `{{ user.key }` remain untouched. The key charset mirrors
`EmailVariableRegistry::KEY_PATTERN` exactly so the same key a host registered
matches the same key here.

**Subject interpolation.** `EmailVariableInterpolator::interpolateSubject(string,
EmailVariableContext, string): string` resolves the subject through the `text`
target as raw plain text. Symfony Mailer receives that string as a MIME subject;
HTML consumers such as `EmailRenderer::wrapShell()` escape it at their own boundary.
Target compatibility is checked before the host formatter is invoked, so an
incompatible formatter cannot run side effects.

**Aggregated failure path.** Every resolution failure — unknown token, missing
runtime value, formatter exception, formatter target incompatibility — is collected
into ONE `EmailVariableResolutionException` (under
`src/Support/EmailVariableResolutionException.php`). The exception carries the
list of `{key, reason}` failures and exposes:

- `getKeys(): list<string>` — distinct keys that failed (insertion order),
- `getFailures(): list<{key, reason}>` — every failure in detail,
- `getKey(): string` and `getReason(): string` — the FIRST failure, for callers
  that want one key per exception,
- `getMessage(): string` — a deterministic, value-free summary like
  `Email variable resolution failed for 3 token(s): user.first_name: missing value; user.unknown: unknown token; user.missing: unknown token`.

Reason constants are `REASON_UNKNOWN_TOKEN`, `REASON_MISSING_VALUE`,
`REASON_FORMATTER_FAILED`, `REASON_INCOMPATIBLE_TARGET`.

**No value leakage.** The interpolator catches every `Throwable` from a formatter
and discards the wrapped exception's message at the throw site — a host formatter
that throws `\RuntimeException('formatter-internal-secret: <value>')` results in a
`{key, REASON_FORMATTER_FAILED}` entry; the runtime value never reaches the
exception's `getMessage()` or any log / HTTP body built from it. A `null` value
the host explicitly set (i.e. `has($key)` returns true) reaches the formatter;
the formatter decides whether null is acceptable.

**Byte-for-byte no-variable preservation.** A block whose `attributes` contain no
`{{ ... }}` token is copied as-is. The interpolator short-circuits on
`! preg_match(self::TOKEN_PATTERN, $value)` before allocating any failure entry,
so an email without variables renders byte-for-byte through the existing
pipeline (Task 0 baseline is preserved).

**Where each piece lives:**

| Piece                                       | Path                                                  |
|---------------------------------------------|-------------------------------------------------------|
| Interpolator                                | `src/Services/EmailVariableInterpolator.php`          |
| Aggregated resolution exception             | `src/Support/EmailVariableResolutionException.php`    |
| Service-provider binding (singleton)        | `src/HeisenbergServiceProvider.php` `registerEngine()`|
| Focused interpolator test (28 cases)         | `tests/Email/EmailVariableInterpolatorTest.php`       |

**Status of the production pipeline.** Tasks 3–5 are green and documented in
§6.4–§6.6. The interpolator runs in `EmailRenderer::render()` (Task 3), so
runtime maps now produce per-recipient subject, HTML, plain text, URLs, CID output,
and size totals. The author-facing render paths (Task 4) — `showBySlug`, size, single
HTML export, single EML export, and the id-scoped redirects that delegate to those —
pass `EmailVariableContext::samples(app(EmailVariableRegistry::class))` explicitly.
Runtime values are NEVER accepted from query strings, request bodies, headers, or
public/editor GET routes. Task 5 adds the email-only authoring picker and Task 6 adds the
admin-only JSON POST batch ZIP route. Task 7 closes the docs/examples loop with three
host-side examples under `examples/EmailVariables/`.

### 6.5 Sample-only author-facing GETs (E5 Task 4 — landed)

The renderer's fourth parameter (`?EmailVariableContext $variables`) defaults to a
**strict empty runtime context** when omitted — a missing token throws an aggregated
`REASON_MISSING_VALUE`, never a silent sample substitution. Tasks 3 calls into the
renderer with runtime maps for real sends; **Task 4 threads the sample context through
every author-facing GET** so the editor and "view in browser" links see a non-secret
placeholder, never a recipient's data.

The shape is explicit at the controller boundary, not buried in a default:

```php
// Every render call site in EmailPreviewController:
$context = EmailVariableContext::samples(app(EmailVariableRegistry::class));
$result  = $this->renderer->render($model, $locale, preview: true, variables: $context);
```

The four render call sites in `src/Http/Controllers/EmailPreviewController.php`
are all wired this way:

| Method                              | Purpose                                       | Surface                |
|-------------------------------------|-----------------------------------------------|------------------------|
| `showBySlug`                        | `GET /{prefix}/{slug}` — built email          | preview HTML (real URLs) |
| `size`                              | `GET /editor/{post}/email-size` — size chip   | cid-embedded real bytes |
| `exportBySlug` → `exportHtml`       | `GET /{prefix}/{slug}/export?format=html`     | preview HTML, attachment |
| `exportBySlug` → `exportEml`        | `GET /{prefix}/{slug}/export?format=eml`      | cid-embedded MIME     |

The id-scoped editor routes (`/editor/{post}/email-preview`, `/editor/{post}/email-export`,
`/editor/{post}/email-size`) redirect to the slug URL — the redirect carries `locale` and
`format` through, and the slug URL is where the sample-substituted render happens. The
sample substitution therefore reaches every author-facing GET end-to-end.

**Strict-runtime default preserved.** `EmailRenderer::render()` does NOT default to
samples. A direct caller that omits the fourth parameter (the legacy three-positional
form or the HeisenbergMailable seam) still gets a
strict empty runtime context — the controller is the boundary that explicitly opts
author-facing GETs into the sample path.

**No runtime values from query / body / headers.** `?variables[user.first_name]=Ada`
on a slug GET is silently ignored — the controller never reads it. Runtime values
reach the renderer only through `HeisenbergMailable`'s third constructor argument
(host mailer integration), or the admin batch endpoint's JSON body (Task 6).

**422 controlled failure path.** An unregistered token in the document, a formatter
exception, or a target/type
incompatibility surfaces as a single HTTP 422 carrying keys and safe reasons only:

```json
{
  "message": "Email variable resolution failed for 1 token(s): user.unknown: unknown token",
  "failures": [{"key": "user.unknown", "reason": "unknown token"}],
  "keys": ["user.unknown"]
}
```

The interpolated `EmailVariableResolutionException::getMessage()` is already
deterministic and value-free by design (Task 2 discarded formatter internals at
the throw site) — the controller surfaces `getMessage()`, `getFailures()`, and
`getKeys()` directly. No runtime values, no formatter exception messages, no stack
traces, no raw `{{ ... }}` tokens ever appear in the response body.

**Compatibility invariants preserved.**

- `PostPolicy::view` continues to gate every author-facing GET.
- The id-scoped routes still 302-redirect to the slug URL, carrying `locale` and `format`.
- Locale resolution (`?locale=fr`, validated against `LocaleConfig::isValid`) is unchanged;
  the sample context is threaded through the same locale the renderer was already given.
- The HTML export keeps `Content-Type: text/html; charset=UTF-8`, the `Content-Disposition`
  attachment, and the existing absolute-image-URL rewriting for `cid:` references.
- The EML export keeps `Content-Type: message/rfc822`, the `mail.from.address` 422 path
  for an unconfigured From, the deterministic `text/plain` + `text/html` + inline `image/*`
  MIME structure, and the same `<slug>-<locale>.{html,eml}` filename rule.
- A token-free email renders byte-for-byte on the new sample path (no registered
  definitions → empty `samples()` context → interpolator short-circuits on no-token
  strings → identical bytes to the legacy empty-runtime path).
- MIME subject carries the interpolated subject as raw plain text (RFC 822),
  exactly as Task 3 set up.
- `sizeBytes` still reports the cid-embedded render's HTML length + attachment bytes.

### 6.6 Email-only variable picker (E5 Task 5 — landed)

The picker is a separate component at
`resources/views/components/live/pickers/email-variable-menu.blade.php`. The Style-panel
theme-token picker at `resources/views/components/live/pickers/variable-menu.blade.php`
is unchanged: it still owns color/number tokens and the `varselect` `{name, value}` event.

`EditorController` mounts the email picker only when the document is an email, the registry
has definitions, and the actor may update the document. It serializes only editor-safe metadata:
`key`, localized `label`, `group`, `description`, `type`, validated `targets`, formatter
`options`, and the formatted sample string. Runtime values, raw non-scalar samples, formatter
objects, closures, and host classes never enter editor HTML.

At runtime the picker adds triggers beside the canvas subject, inspector subject mirror,
eligible text/URL settings, and the selected rich-text block toolbar. Subject/rich/plain fields
filter to `text`; URL fields filter to `url` (including the built-in email formatter's URL
compatibility). Anchor, class/style/support, chips, select/enum, boolean, number/range, and
structural controls are excluded.

Insertion writes the literal `{{ dotted.key }}` as text via `setRangeText()` or a Range text
node—never `innerHTML`—then dispatches the existing bubbling `input` event. The established
title mirror, block runtime, autosave, save payload, and locale-specific attribute paths persist
the token unchanged. Search covers label/key/group; keyboard navigation, Escape, outside close,
focus/caret restoration, empty state, `hb:refresh`, and locale-change rewiring are included.

### 6.7 Admin batch generate and export (E5 Task 6 — landed)

For admins (tier `email.generate`, default `['admin']`): a single JSON POST produces a zip
of N host-supplied recipient value maps × every requested locale. Heisenberg renders and zips;
the host still sends.

```php
use Heisenberg\Services\EmailBatchExporter;

$zip = app(EmailBatchExporter::class)->export(
    $publishedEmailPost,
    [
        'format' => 'eml',                                  // or 'html'
        'locales' => ['en', 'fr'],                          // default: LocaleConfig::locales()
        'recipients' => [
            ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => 'https://…/u1']],
            ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => 'https://…/u2']],
        ],
    ],
);

// $zip is an EmailBatchExportResult: { path, fileCount, recipientCount, locales }
// — no values, no recipient keys, no formatter internals.
```

The zip layout is exactly `{slug}/{locale}/{id}.{html|eml}` — locale-major, recipient-minor,
with exactly N×requested-locales files from one call. Heisenberg's current translation model is
single-row: `title_<locale>` and suffixed block attributes live on the same post. The exporter
uses `TranslationStatusService` and refuses any locale whose persisted content is incomplete
(`EmailBatchTranslationMissingException` → HTTP 422), never silently labeling fallback content.

Validation is strict and aggregated:

- `format` ∈ `{'html','eml'}`; everything else → 422.
- `locales` defaults to `LocaleConfig::locales()`; anything outside it → 422.
- `recipients` length 1..`config('heisenberg.email.batch_max_recipients')` (default 100);
  empty or over the cap → 422.
- Recipient `id` filename-safe (`[A-Za-z0-9._-]`) and unique within a batch; otherwise → 422.
- Every `values` key must be a registered variable; otherwise → 422.
- Values stay `mixed`: custom Money/DTO/array-like values are handed to their registered formatter.
- Missing/unknown/formatter/target failures across every recipient×locale pair aggregate to one
  value-free `EmailVariableResolutionException` before a zip is allocated.

The HTTP shape is `POST /editor/email/{post}/batch-export` (authenticated editor stack,
`heisenberg.middleware.editor`, gated AGAIN by `PostPolicy::generateEmailBatch` —
`LocalDevRoleGate` + `email.generate` tier + `$post->type === 'email'` + `$post->status === 'published'`).
`application/json` body only; success is `application/zip` with `Content-Disposition: attachment` and a
`BinaryFileResponse` whose `deleteFileAfterSend(true)` unlinks the temp zip in the same
finally block that streams it. Author/editor/viewer get 403; a draft email gets 403 even
from an admin; a non-email post gets 404.

Config:

```php
// config/heisenberg.php
'email' => [
    'routes'               => true,
    'route_prefix'         => 'emails',
    'batch_max_recipients' => 100,                       // host-overridable
],

'roles' => [
    // ...
    'email.generate' => ['admin'],                       // host-overridable; e.g. add 'editor'
],
```

Heisenberg still does not own SMTP. EML export reads `mail.from.address` (and optionally
`mail.from.name`) only for the `From:` header of each generated message — exactly the
single-document export's posture — and surfaces a 422 if it isn't configured. Recipients
are never `HeisenbergUser` rows; `RoleGate::rolesOf()` is never called to derive N. N is
the admin-supplied list length.

### 6.8 Host seam, roles, and personalization lifecycle (E5 Task 7 — landed)

This section is the host integration cheat sheet: every point a host
touches when wiring up variables, render/send, and admin batch — and
every point Heisenberg deliberately does NOT touch.

#### Registration

The host registers variable keys and formatter types in its own
`AppServiceProvider::boot(...)`. The registry is a Heisenberg singleton,
already bound in `HeisenbergServiceProvider::registerEngine()`, so
resolving `EmailVariableRegistry` in `boot(...)` returns the shared
instance:

```php
public function boot(EmailVariableRegistry $variables): void
{
    // 1) custom formatter types FIRST (the registry validates
    //    `targets()` at register time — a typo fails at boot, not at
    //    render time);
    $variables->registerType(new \App\Mail\VariableTypes\MoneyEmailVariableType());

    // 2) then every definition. Each carries a NON-SECRET `sample` —
    //    the editor preview, public preview, size measurement, and
    //    single-document HTML/EML export ALL substitute samples, never
    //    runtime values. A definition without a sample is a host bug
    //    (sample is the public placeholder shown to authors and guests).
    $variables->register(new EmailVariableDefinition(
        key: 'user.first_name',
        label: 'First name',
        type: 'text',
        sample: 'Tedy',
        group: 'User',
    ));
    // ...
}
```

A runnable end-to-end copy is in `examples/EmailVariables/AppServiceProvider.php`,
the host custom formatter in `examples/EmailVariables/MoneyEmailVariableType.php`.

#### Built-in formatters and custom formatters

Six built-in formatters auto-register when the registry is constructed
(`text`, `url`, `email`, `number`, `boolean`, `date`) — see §6.2's
table. A host registers its own additional types via
`EmailVariableRegistry::registerType(EmailVariableType $type)`. The
contract is exactly three methods: `key(): string`, `targets(): list<'text'|'url'|'email'>`,
and `format(mixed $value, EmailVariableDefinition $definition, string $locale): string`.
Custom formatters may accept non-scalar samples (Money, value objects,
anything the formatter understands); the registry treats the `sample`
as opaque and only formats it when `editorMetadata()` is requested.

#### Mandatory non-secret samples

Every `EmailVariableDefinition` MUST carry a `sample`. The four
author-facing surfaces — preview, size, single HTML export, single EML
export — all substitute samples via
`EmailVariableContext::samples($registry)`. The runtime path
(`EmailVariableContext::runtime([...])`) carries per-recipient values
to the renderer/mailable/batch and never falls back to samples. A host
that forgets to set `sample` ships an editor with no preview string
and a runtime map that has nothing to substitute on the preview path.

The sample is also what the editor's picker shows. Runtime values, raw
non-scalar samples, formatter objects, closures, and host classes
never enter editor HTML — `editorMetadata()` formats each sample
through the registered formatter once and caches the literal STRING.

#### Exact flat runtime maps with arbitrary custom value objects

A runtime map is an EXACT FLAT `array<string, mixed>` (or a pre-built
`EmailVariableContext::runtime([...])`). Keys MUST be registered. Values
may be anything the registered formatter accepts — scalars, host value
objects, anything:

```php
$result = app(\Heisenberg\Services\EmailRenderer::class)->render(
    $email,
    'en',
    false,
    \Heisenberg\Support\EmailVariableContext::runtime([
        'user.first_name'  => 'Ada',                              // built-in `text`
        'account.balance'  => new \App\Domain\Money(250_000, 'NGN'), // host `money` formatter
        'unsubscribe_url'  => \Illuminate\Support\Facades\URL::signedRoute(
            'unsubscribe', ['user' => $user->getKey()],
        ),
    ]),
);
```

The interpolator (Task 2) reads the value as-is. The block renderer
never sees an Eloquent model, a host Money object, or a closure — only
the formatter output (a plain string) reaches the substitution site.
Heisenberg does NOT introspect objects, derive paths, or call methods;
this is locked decision §2.

#### Renderer/mailable host-SMTP seam vs Heisenberg admin batch zip (no SMTP)

| Path | What | SMTP? |
|---|---|---|
| `app(EmailRenderer::class)->render(...)` | Per-recipient subject/HTML/text/embeds. Host runs mailer. | **No — host runs its own.** |
| `Mail::to($addr)->send(new HeisenbergMailable($id, $locale, $map))` | Bundled single-recipient Mailable — subject/html/text set, embeds attached via `withSymfonyMessage()`. Host mailer integrates this into its own transport. | **No — host runs its own.** |
| `POST /editor/email/{post}/batch-export` | Admin-only JSON POST → `BinaryFileResponse` of one zip of N × locale personalized `.html` or `.eml`. | **No — Heisenberg never sends.** |
| `app(EmailBatchExporter::class)->export(...)` | Same service the HTTP route wraps; host jobs can call directly. | **No — Heisenberg never sends.** |

Heisenberg reads `mail.from.address` (and optionally `mail.from.name`)
only to set the `From:` header of generated `.eml` files — exactly the
single-document EML export posture. With those unconfigured, the
exporter surfaces a controlled `InvalidArgumentException` ("configure
mail.from.address"); no SMTP connection is attempted, no transport is
instantiated, no envelope is built.

#### RoleGate tiers, host remappable `email.generate`

The bundled gate exposes four canonical tiers used by Heisenberg
policies (`config('heisenberg.roles')`):

|| Actor       | Author/publish an email (`PostPolicy::view` / `update` / `lifecycle`) | Editor variable picker (`can view` / `can update`) | Sample preview / single HTML/EML export (`PostPolicy::view`) | Admin batch export (`PostPolicy::generateEmailBatch`) |
||-------------|---|---|---|---|
|| `admin`     | Yes (full + delete)                | Yes | Yes | **Yes (default)** |
|| `editor`    | Author + publish (`published`)     | Yes | Yes | No unless host remaps `email.generate` |
|| `author`    | Own drafts; cannot publish         | Yes on emails they may `update` | Yes if they may `view` | No |
|| `viewer` / guest | Published slug "view in browser" only | No picker | No editor export | No |

`PostPolicy::generateEmailBatch` is a single three-part check —
`email.generate` tier + `$post->type === 'email'` + `$post->status === 'published'`.
The flat tier key is `config('heisenberg.roles')['email.generate']` and
defaults to `['admin']`. A host that wants editors to also batch-export
overrides that entry without touching any policy class — that's the
"remappable flat `email.generate`" decision.

#### Recipients are explicit maps, not Heisenberg users

`HeisenbergUser` is the editor/auth actor (`getAuthIdentifier()` only).
There is no Heisenberg user directory, no `HeisenbergUser::subscribed`
flag, no `RoleGate::rolesOf()` enumeration as a recipient source. A
batch's N is the literal `count($recipients)` the admin (or the host
job calling `EmailBatchExporter`) passed in:

```php
$zip = app(\Heisenberg\Services\EmailBatchExporter::class)->export($email, [
    'format'     => 'eml',
    'recipients' => [
        ['id' => 'u1', 'values' => ['user.first_name' => 'Ada', 'unsubscribe_url' => $url1]],
        ['id' => 'u2', 'values' => ['user.first_name' => 'Ben', 'unsubscribe_url' => $url2]],
    ],
]);
// N = 2. count($recipients) is the whole truth.
```

A recipient is an EXPLICIT flat `{id, values}` map — keys registered,
values supplied. N is what the admin passed in, capped at
`config('heisenberg.email.batch_max_recipients')` (default 100).

#### One-call default: every configured locale

`locales` is OPTIONAL. Omitting it tells the exporter to use every
locale the host has installed — `LocaleConfig::locales()` (default
`['en', 'fr']`):

```php
$zip = app(\Heisenberg\Services\EmailBatchExporter::class)->export($email, [
    'format'     => 'eml',
    'recipients' => [/* ... */],
    // 'locales' intentionally omitted → defaults to LocaleConfig::locales()
]);
```

A host that wants only one language passes `['en']` explicitly. Anything
outside `config('heisenberg.locales')` is rejected with a controlled
`InvalidArgumentException`.

Heisenberg's current translation model is single-row
(`title_<locale>` and localized block attributes on the same post
row). One export call produces the full N × requested-locales matrix.
If the persisted row does NOT have complete content for one of the
requested locales, the exporter raises
`EmailBatchTranslationMissingException` (→ 422 in the HTTP route,
exit 1 in a queued host job) BEFORE any file is rendered. An admin
never receives an English body mislabeled as French inside the zip.

#### Strict unknown / missing / formatter / target failures

Real send, mailable construction, and admin batch all run the same
strict interpolator (Task 2). Every failure aggregates to one
`EmailVariableResolutionException` carrying `list<{key, reason}>`
pairs and `getKeys()`. Reason constants are
`REASON_UNKNOWN_TOKEN`, `REASON_MISSING_VALUE`,
`REASON_FORMATTER_FAILED`, `REASON_INCOMPATIBLE_TARGET`. NEVER a
runtime value, never a formatter internals string. The interpolator
discards every `Throwable` message at the throw site — a host
formatter that throws `\RuntimeException('host-secret: <value>')`
results in `{key, REASON_FORMATTER_FAILED}` with the host's secret
nowhere in the exception message.

Author-facing GETs (`/emails/{slug}`, size, single HTML export,
single EML export, id-scoped redirects that delegate to those)
surface the same `{message, failures, keys}` body as a controlled 422
on any of those four failures (§6.5). Token-free calls stay
byte-for-byte identical to the legacy path.

#### Interpolation-before-rich-text / safeUrl

The interpolator runs on a DEEP-COPIED subject string and block tree,
BEFORE the existing block renderer's sanitizers (§6.3):

- Rich-text contract attributes (`type: "rich-text"`) get an
  `htmlspecialchars()` pass on the formatted replacement BEFORE the
  block renderer. `<script>...</script>` arrives as
  `&lt;script&gt;...&lt;/script&gt;`. `sanitizeRichText()` then
  sees a plain-text token, never trusting markup.
- URL contract attributes (`type: "url"`) get the RAW formatted URL.
  The block renderer's existing `safeUrl()` gate enforces scheme
  policy downstream — a `javascript:alert(1)` value is rejected at
  the URL gate, not at the formatter. Substitution is raw, scheme
  policy is the block renderer's.
- Translatable string attributes (`translatable: true`, not rich-
  text) substitute raw; the existing attribute escaping in
  `BlockRenderer::buildAttributes()` is the gate that turns `<` into
  `&lt;`.
- Everything else (`anchor`, `extraClasses`, `class`, `style`,
  `supports.*`, unknown contract types) is left alone — a `{{ key }}`
  token that happens to sit inside a CSS identifier slot passes
  through unchanged.

Which attributes are token-aware is discovered from the contract via
`BlockRegistryService::getBlock($name)['attributes'][$name]` — no
hardcoded list of heading/paragraph/button fields. A host that adds a
new block with its own translatable / rich-text / URL attributes
inherits the right escaping without changes to the interpolator.

#### Sample-only preview / single export

`showBySlug`, `size`, `exportHtml`, `exportEml` (and the id-scoped
redirects that delegate to those) all pass
`EmailVariableContext::samples($registry)` explicitly — see §6.5.
Runtime values are NEVER accepted from query strings, request bodies,
or headers; `?variables[user.first_name]=Ada` is silently dropped at
the boundary. The renderer default is a strict empty runtime context,
not samples; the controller is the boundary that opts author-facing
GETs into the sample path.

#### Signed personalized browser links are host responsibility

Heisenberg never inserts an `unsubscribe` link. A host that wants one
either:

1. Authors the link token directly into the email body — e.g. a
   `button` block whose `href` is `{{ unsubscribe_url }}` — and passes
   the signed URL through the runtime map at render time:
   ```php
   'unsubscribe_url' => URL::signedRoute('unsubscribe', ['user' => $user->getKey()]),
   ```
   The interpolator substitutes the raw URL into the button `href`,
   `BlockRenderer::safeUrl()` enforces scheme policy downstream. The
   host owns signing, expiry, and the matching route — Heisenberg only
   sees the formatted string.

2. Or authors a "view in browser" link the same way — a registered
   variable carrying a `URL::signedRoute('emails.show', ['post' => $id])`
   value the host builds for each recipient. Same shape, same seam.

The editor's preview / single-export sample for either is a non-secret
placeholder URL the host registers at boot (`'https://example.test/unsubscribe/sample'`).

#### Queued mailable: queue-safe values, no implicit lookup

`HeisenbergMailable` is not `ShouldQueue`. A host that queues it
(`Mail::queue(new HeisenbergMailable(...))` or a subclass that adds
the interface) should:

- Construct ONE INSTANCE PER RECIPIENT. The third constructor argument
  is consumed synchronously by the constructor itself — the queued
  object carries the already-rendered recipient-specific
  `EmailRenderResult`, not the original value map. The queued instance
  is never re-used to discover or re-render another recipient in the
  worker.
- Use QUEUE-SAFE SCALARS / DTOs at construction time. A value object
  the queue serializer can't restore (e.g. an un-restorable host
  Money) belongs in the runtime map for THIS recipient's already-
  rendered instance — or, simpler, a scalar at the boundary.

Heisenberg does no implicit model lookup at construction. The host
passes the final values; the renderer turns them into a string;
the queued object carries that string. The mailer envelope (`From`,
`To`, `Subject`) is the host's concern, as it always was.

#### No recipient migration / database / product surface

E5 ships NO migration for variables or recipients. Definitions are
host code (the host's `AppServiceProvider::boot(...)`); per-recipient
values are runtime-only on the flat map (mailable) or one-shot on the
admin batch request body. Batch output is a download (HTTP) or a
returned DTO (service call), not a new table. There is no subscriber
model, no campaign model, no scheduling, no queue-as-product, no SMTP
config, no open/click analytics, no unsubscribe persistence inside
this package. Sending is the host's job. Mass file generation (E5
Task 6) is in scope; delivering those files through a mailer is
explicitly NOT.

### 6.4 Personalized renderer and mailable (E5 Task 3 — landed)

`EmailRenderer` keeps its existing first three positional arguments and adds the
context fourth:

```php
$result = app(EmailRenderer::class)->render(
    $email,
    'en',
    false,
    EmailVariableContext::runtime([
        'user.first_name' => 'Ada',
        'unsubscribe_url' => 'https://example.test/unsubscribe/ada',
    ]),
);
```

An omitted or `null` fourth argument means a strict empty runtime context. It does
not fall back to samples: token-bearing content fails with a value-free aggregated
`EmailVariableResolutionException`. Token-free calls remain compatible.

The host SMTP seam remains optional and explicit:

```php
Mail::to($recipient)->send(new HeisenbergMailable(
    $email->getKey(),
    'en',
    [
        'user.first_name' => 'Ada',
        'unsubscribe_url' => 'https://example.test/unsubscribe/ada',
    ],
));
```

The mailable's third argument accepts a flat array or an existing
`EmailVariableContext`; arrays become strict runtime contexts and explicit contexts
retain their mode. `HeisenbergMailable` is still not `ShouldQueue`. A queued instance
contains one already-rendered recipient-specific result, so construct one instance per
recipient and use queue-safe scalars/DTOs at construction time. Heisenberg still does
not configure SMTP or discover recipients.

## 6.1 One address: a built email is served at its own slug

An email document is a post row, but it is not a page — so it does not live on the post surface.
Everything that renders one lives under its own route group (`routes/email.php`, opt-out via
`heisenberg.email.routes`, gated by `heisenberg.middleware.email`):

- **`GET /{email.route_prefix}/{slug}`** (default `/emails/{slug}`) — the built email itself. Same
  `preview: true` render described above; `X-Robots-Tag: noindex, nofollow` on the response, since
  an email is not web content (the sitemap excludes `type = 'email'` for the same reason). Sent as
  a header rather than injected into the markup, so the bytes a reader receives are byte-identical
  to what a mailer would send.
- **`GET /{prefix}/{slug}/export?format=html|eml`** — the two downloads below, from that same
  address.
- Both take **`?locale=`** (validated against `heisenberg.locales`; anything else is ignored) —
  which translation to render, subject included. Without it they fall back to the app locale, which
  is the UI language: the editor's locale dropdown is client state and never touches it, so an
  author working on the French version used to preview and export the English one. The topbar
  sends `locale` on Preview, on both export formats, and on the footer's size chip (which
  re-measures on a locale switch, since translations differ in length). A host building a "view in
  browser" link should pass the recipient's language the same way.

And nothing else renders one:

- `GET /editor/{post}/preview` — the POST preview — **404s** for `type = 'email'`. Rendering an
  email there would dress it in the post page's shell (SEO head, hreflang, comments thread) and
  hand it a second public address.
- `GET /editor/{post}/email-preview` and `/email-export` — the topbar's buttons, which know a post
  id but not a slug the author may still be editing — resolve, authorize, and **redirect** to the
  slug URL (`format` carried through). The author's tab therefore lands on the real, shareable
  address, and there is one route to reason about when asking who can read a built email.
- Every one of them 404s for a non-email post, and `GET /{prefix}/{slug}` is scoped to
  `type = 'email'`, so a post's slug is never reachable there. The slug lookup prefers the active
  locale's row (the posts table's unique index is `['locale', 'slug']`, so one slug legitimately
  exists per locale).

`heisenberg.middleware.email` defaults to `['web']` — a recipient following a "view in browser"
link is not an authenticated editor — and that is deliberately not the access control: every entry
point runs the same PostPolicy `view` check the editor does, so a DRAFT email 403s for a visitor
however open that stack is. A published email is readable at its slug by anyone who has the link,
which is what a "view in browser" URL is for; a host that wants otherwise tightens
`middleware.email` or sets `heisenberg.email.routes` false and renders through `EmailRenderer`
itself.

## 6.2 …and authored at its own address too

The same rule one level up: an email is a different kind of document, so it is edited at a
different URL. `routes/editor.php`:

- **`GET /editor/email`** — a blank email. (`GET /editor?type=email`, the old form, redirects here:
  a query param is a poor way to say what kind of thing someone is authoring, and it left the type
  invisible in the address bar.)
- **`GET /editor/email/{post}`** — an existing email document.
- `GET /editor/{post}` redirects an email to the surface above, and `GET /editor/email/{post}`
  redirects a plain post back to `/editor/{post}`. Each document therefore has exactly one
  authoring URL whichever link points at it — redirects rather than 404s, because these are links
  people already hold (a bookmark, a row in a host's admin list). Authorization runs before either
  surface decides where to send the request, so a redirect never confirms an id exists to someone
  who may not read it.
- The topbar's post-create URL rewrite is per type, so a new email's first save lands on
  `/editor/email/{id}` rather than a `/editor/{id}` that only redirects back.

What the split buys is a **Post tab shaped for an email** rather than a post's panel with pieces
switched off. `documentType` already gated the palette (email-safe blocks only), the 600px canvas,
the SEO/Social panel, Featured image, Discussion and Table of contents; with a surface of its own
it also drops Categories/Tags and Page layout (taxonomy organizes a listing an email never appears
in; the padding sliders move the `.hb-page` sheet an email is not rendered into) and the "stick to
the top of the blog" toggle. What remains is what an email actually has: subject/title, status and
send date, revisions, translations, trash — and the slug, relabelled **Email address** and shown as
`/emails/{slug}`, because on this document type that field IS the link the author is about to send.

### Getting a built email OUT of the editor

Heisenberg renders and the host sends — but before a host is ready to wire up its own mailer, or
for the common case of pasting a built email straight into an ESP (Mailchimp, Klaviyo, …), the
editor also exports. `EmailPreviewController`, gated exactly like the served email above (PostPolicy
`view`) plus a 404 for a non-email post:

- **`GET /emails/{slug}/export?format=html`** — the ESP paste/upload case. Renders through
  the SAME `preview: true` path the browser preview uses: images are absolute, publicly-fetchable
  URLs, never `cid:` references, because a platform ingesting raw HTML has no MIME parts to
  resolve them against. Downloads as `<slug>-<locale>.html` (`Content-Disposition: attachment`).
- **`GET /emails/{slug}/export?format=eml`** — the self-contained case. Builds a real
  RFC-822 message with Symfony Mime directly (`Symfony\Component\Mime\Email`) from the REAL,
  cid-embedded render — subject, a `text/plain` part, a `text/html` part, and every embed
  re-attached as an inline part keyed to the exact `cid` already in the HTML, the same pairing
  `HeisenbergMailable` does for a live send. Downloads as `<slug>-<locale>.eml`. `From` is set
  only when `mail.from.address` is actually configured — never fabricated.
- An unrecognized or missing `format` defaults to `html`.

The crucial difference: the HTML export references images by public URL, so it only displays
correctly for as long as those files stay reachable on the host's uploads disk — it is NOT
self-contained. The .eml embeds every image as a MIME part instead, so it is a real, standalone
message file (openable in Outlook/Mail.app/Thunderbird, or re-imported by another tool) — at the
cost of the same size the size chip already reports (§2's "Gmail clips very large mail" applies to
the .eml file itself, not just a live send).

The topbar exposes both formats as a download menu beside Preview, email documents only, disabled
until the document has been saved once (`emailExportUrlTemplate`, seeded the same __ID__-template
way as the preview/size URLs — those id routes redirect to the slug, §6.1).

## 7. Waves

- **E1+E2 (foundation, one agent)**: `type` column + scopes + list exclusions; contract `email`
  section + validator + registry filtering + the initial email templates for the safe set;
  `EmailRenderer` + result object + Mailable; tests.
- **E3 (editor)**: creating/opening email documents (palette filtered, irrelevant panels hidden,
  email-width canvas hint, size indicator), after the current inspector work lands.
- **E4 (bench)**: reference install demo — author an email, render it, send via the log mailer,
  verify the MIME parts.
- **E5 (host variables + admin batch export — Tasks 0–8 landed and verified):** see
`.hermes/plans/2026-08-25_190059-email-template-variables.md`. Host-registered merge tags
(`{{ dotted.key }}`), typed formatters, interpolation **before** sanitization, sample preview,
per-recipient `EmailRenderer` / optional `HeisenbergMailable` maps, and an **admin-only** zip
of N personalized HTML/EML files × `LocaleConfig::locales()`. Heisenberg still does not own
SMTP. Recipients are not `HeisenbergUser` rows; N is the admin-supplied list length. Auth
stays on `RoleGate` (`email.generate` defaults to `admin`; authors/editors keep the existing
PostPolicy/lifecycle for authoring and publishing). **Status:** Tasks 0–8 are GREEN. Tasks 0–6 (baseline + host variable registry + formatter contract
+ central validation + six built-in types; context-aware interpolation before sanitization;
+ renderer/mailable threading; sample-only author-facing preview/size/single export; email-only
+ picker; admin batch export endpoint and file factory) ship in `src/` and are exercised by the
+ full PHPUnit suite. Task 7 ships this docs section plus three runnable examples under
+ `examples/EmailVariables/`. Task 8 — the two-stage fresh-MiniMax-M3 spec + code-quality/security
+ review plus the full-repo single-process PHPUnit run (`OK 1539 tests / 6121 assertions /
+ 1 skipped / 0 failures / 0 errors in 25:04`) — is the final verification, recorded in the
+ plan's Progress / Completion criteria.
The theme-token picker (`resources/views/components/live/pickers/variable-menu.blade.php`) is
untouched by E5.

## 7. Out of scope (recorded) & cross-references

Subscriber management, campaign **sending**/scheduling/tracking, SMTP configuration inside
Heisenberg, open/click analytics, MJML interop, per-client conditional comments beyond the
minimal Outlook shims the shell needs. Mass **file generation** (E5) is in scope; delivering
those files through a mailer is the host's job.

For the host-side how-to (registering variables, authoring tokens, sending per-recipient
emails, running the admin batch ZIP, authorization and configuration), see the dedicated
**[`docs/email-personalization.md`](email-personalization.md)** usage guide. The full system
spec lives in [`.hermes/plans/2026-08-25_190059-email-template-variables.md`](../.hermes/plans/2026-08-25_190059-email-template-variables.md) and the compile-checked host examples in
[`examples/EmailVariables/`](../examples/EmailVariables/).

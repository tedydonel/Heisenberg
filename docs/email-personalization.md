# Heisenberg email personalization — host usage guide

> **Status:** Wave **E5** ships. Tasks 0–8 are GREEN (full PHPUnit suite: **1539 tests, 6121 assertions, 1 skipped, 0 failures, 0 errors** in ~25:04, plus a fresh two-stage MiniMax-M3 spec + code-quality/security review). This guide is the host-side how-to. The exhaustive design + build plan lives in [`.hermes/plans/2026-08-25_190059-email-template-variables.md`](../.hermes/plans/2026-08-25_190059-email-template-variables.md) and the full system reference in [`docs/email-system.md`](email-system.md).

This guide is the one a host developer reads end-to-end. It covers:

1. What Heisenberg ships vs. what the host owns.
2. Registering variables and custom formatters.
3. Authoring tokens in the email editor.
4. Sending personalized emails from the host mailer.
5. Producing a one-call ZIP of N × locales from the admin route (or service).
6. Authorizing, configuring, and validating everything.
7. The compile-checked copy-paste examples under `examples/EmailVariables/`.
8. A worked end-to-end recipe.

---

## 1. Heisenberg ships, host owns

Heisenberg ships a **single-row Post** model that already carries every locale's content on one row (`title_<locale>`, suffixed block attributes) plus an email-specific variant of the existing block renderer. It does **not** ship:

- a user directory, mailing list, or subscriber table
- a campaign scheduler or queue worker
- SMTP configuration or any mailer facade
- analytics, open/click tracking, MJML interop

You (the host) own the **recipients** (an explicit flat `id => values` map per send), the **transport** (your mailer), the **delivery** (your queue, your sender reputation), and the **user directory** (your `users` table; Heisenberg only ever reads `Authenticatable::getAuthIdentifier()` on the editor actor).

Heisenberg provides:

| Need you have | Heisenberg's answer |
|---|---|
| Author-time merge-tag picker on email documents | `Heisenberg\Services\EmailVariableRegistry` + `resources/views/components/live/pickers/email-variable-menu.blade.php` |
| Per-recipient subject, HTML, text, and CID embeds | `Heisenberg\Services\EmailRenderer::render($email, $locale, false, EmailVariableContext::runtime([...]))` |
| Per-recipient Laravel mailable | `Heisenberg\Mail\HeisenbergMailable($emailId, $locale, $values)` |
| Strict safe substitution (rich-text escape before sanitization, URL gate, no silent en fallback) | `Heisenberg\Services\EmailVariableInterpolator` |
| Public preview, size measurement, and single HTML/EML export | `Heisenberg\Http\Controllers\EmailPreviewController` (`showBySlug`, `size`, `exportHtml`, `exportEml`) — samples only |
| Admin batch ZIP of personalized files | `POST /editor/email/{post}/batch-export` → `Heisenberg\Services\EmailBatchExporter::export()` → `Heisenberg\Support\EmailBatchExportResult` |

---

## 2. Register your variables

### 2.1 Where registration happens

In **your host's** `AppServiceProvider::boot(Heisenberg\Services\EmailVariableRegistry $variables)` (or a dedicated `App\Providers\HeisenbergEmailVariablesServiceProvider` if you prefer small providers). The registry is a Heisenberg singleton already bound by `Heisenberg\HeisenbergServiceProvider::registerEngine()`.

### 2.2 Order matters

```php
public function boot(EmailVariableRegistry $variables): void
{
    // 1) Register custom FORMATTER TYPES first. The registry validates each
    //    formatter's `targets()` at register time — a typo fails at boot,
    //    not at render time.
    $variables->registerType(new \App\Mail\VariableTypes\MoneyEmailVariableType());

    // 2) Register each DEFINITION. Each definition carries a NON-SECRET
    //    `sample` (the editor preview, public preview, size measurement,
    //    and single-document HTML/EML export ALL substitute samples, never
    //    runtime values).
    $variables->register(new EmailVariableDefinition(
        key: 'user.first_name',
        label: 'First name',
        type: 'text',
        sample: 'Tedy', // non-secret; shown to authors and guests
        group: 'User',
    ));
}
```

### 2.3 What you can put in a definition

```php
new EmailVariableDefinition(
    key: string,         // ^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$
    label: string,       // shown in the picker; non-empty
    type: string,        // a registered formatter key ('text', 'url', 'email',
                        //   'number', 'boolean', 'date', or your custom key)
    sample: mixed,       // non-secret placeholder; scalars or your own value objects
    group: string|null,  // picker group label (e.g. 'User', 'Account')
    description: string|null, // optional tooltip
    options: array|null, // opaque to Heisenberg; read by your formatter
)
```

Reserved keys (BlockRenderer roots + their dotted descendants) are rejected at `register()` time: `id`, `name`, `attributes`, `supports`, `lang`.

### 2.4 Built-in formatters

| Key | Targets | Notes |
|---|---|---|
| `text`   | `text`     | Stringifies scalars; non-stringables throw strict. |
| `url`    | `url`      | String URL; `BlockRenderer::safeUrl()` enforces scheme policy. |
| `email`  | `email`, `url` | String email; compatible with `mailto:` URL attributes. |
| `number` | `text`     | `int`/`float`; `options['decimals']` overrides decimal count. |
| `boolean`| `text`     | `options['format']` ∈ `code` / `toggle` / `word`. |
| `date`   | `text`     | `DateTimeInterface` / timestamp / ISO-8601; `options['format']` is a PHP `date()` pattern. |

A host registers its own additional types via `EmailVariableRegistry::registerType(Heisenberg\Contracts\EmailVariableType $type)`.

### 2.5 The custom-formatter contract

A formatter implements exactly three methods:

```php
namespace App\Mail\VariableTypes;

use Heisenberg\Contracts\EmailVariableType;
use Heisenberg\Support\EmailVariableDefinition;

final class MoneyEmailVariableType implements EmailVariableType
{
    public function key(): string
    {
        return 'money';
    }

    /** @return list<'text'|'url'|'email'> */
    public function targets(): array
    {
        return ['text'];
    }

    public function format(mixed $value, EmailVariableDefinition $definition, string $locale): string
    {
        if (! $value instanceof \App\Domain\Money) {
            throw new \InvalidArgumentException(sprintf(
                "Email variable '%s' (type 'money') expects a Money value; got %s.",
                $definition->key, get_debug_type($value)
            ));
        }
        return sprintf('%s %s', $value->currency, number_format($value->amount, 2, '.', ','));
    }
}
```

The runtime value can be any PHP value your formatter accepts — scalars, your own value objects, anything. The interpolator hands the value verbatim to your formatter; Heisenberg never introspects objects, calls methods, or derives paths.

Implementations MUST throw on unsupported values. Heisenberg catches the throwable, discards the wrapped message at the throw site (so a formatter that throws `\RuntimeException('host-secret: <value>')` results in a `{key, REASON_FORMATTER_FAILED}` entry with the runtime value nowhere in the error), and aggregates the failure into `EmailVariableResolutionException`.

---

## 3. Author tokens in the editor

The email-only picker mounts automatically on email documents when:

1. the post type is `email`,
2. the registry has at least one definition,
3. the actor may update the document (per `PostPolicy`).

Triggers appear beside:

- the canvas subject input,
- the inspector subject mirror,
- eligible text/URL settings controls (`text`, `url`, `rich-text`),
- the selected rich-text block toolbar.

Anchor, class/style/support, chips, select/enum, boolean, number/range, and structural fields are excluded. Insertion writes the literal `{{ dotted.key }}` as **text** via `setRangeText()` (input/textarea) or `Range.deleteContents() + createTextNode + insertNode` (contenteditable). The picker **never** assigns `innerHTML`. The existing bubbling `input` event persists the token unchanged through the editor's autosave path.

Search covers label/key/group. Keyboard navigation, Escape, outside-close, focus restoration, empty state, `hb:refresh`, and locale-change rewiring are package-native vanilla JS — no build step.

The original theme-token picker at `resources/views/components/live/pickers/variable-menu.blade.php` is **byte-unchanged** by E5.

---

## 4. Send personalized emails from the host mailer

### 4.1 One recipient at a time, your mailer

```php
use Heisenberg\Mail\HeisenbergMailable;
use Heisenberg\Support\EmailVariableContext;
use Illuminate\Support\Facades\Mail;

Mail::to($user->email)->send(new HeisenbergMailable(
    $emailPost->getKey(),
    $user->preferred_locale ?? 'en',
    [
        'user.first_name' => $user->first_name,
        'unsubscribe_url' => URL::signedRoute('unsubscribe', ['user' => $user->getKey()]),
        'account.balance' => new \App\Domain\Money($user->balance_cents / 100, 'USD'),
    ],
));
```

The third argument accepts:

- an `array<string, mixed>` — normalized to a strict runtime context.
- an existing `Heisenberg\Support\EmailVariableContext` — used verbatim, including its mode (`runtime` or `sample`).

`HeisenbergMailable` is not `ShouldQueue`. A host that queues it (`Mail::queue(new HeisenbergMailable(...))` or a subclass that adds the interface) should:

- construct **one instance per recipient** — the constructor consumes the third argument synchronously; the queued object carries the already-rendered recipient-specific `EmailRenderResult`, never the original value map.
- use **queue-safe scalars / DTOs** at construction time.

### 4.2 Render directly (without mailing)

```php
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\EmailVariableContext;

$result = app(EmailRenderer::class)->render(
    $emailPost,
    'en',
    false, // preview mode
    EmailVariableContext::runtime([
        'user.first_name' => 'Ada',
        'unsubscribe_url' => 'https://example.test/unsubscribe/ada',
    ]),
);

// $result->subject, $result->html, $result->text, $result->embeds
```

Omitting the fourth argument (or passing `null`) means a **strict empty runtime context**: every referenced token throws `REASON_MISSING_VALUE`, never a silent sample substitution. Token-free calls stay byte-for-byte identical to the legacy path.

---

## 5. Admin batch ZIP — one call, N × locales

### 5.1 The HTTP route (admin-only)

```
POST /editor/email/{post}/batch-export
Content-Type: application/json

{
  "format": "html",                     // or "eml"
  "locales": ["en", "fr"],              // optional; defaults to LocaleConfig::locales()
  "recipients": [
    { "id": "u1", "values": { "user.first_name": "Ada",  "unsubscribe_url": "https://e.test/u1" } },
    { "id": "u2", "values": { "user.first_name": "Ben",  "unsubscribe_url": "https://e.test/u2" } }
  ]
}
```

Authorization is enforced **before** validation:

1. Anonymous → **403** (no logged-in user is a 403, not a 422 — the Gate runs first).
2. Author / editor / viewer without `email.generate` → **403**.
3. Admin (or a host that remapped `email.generate` to include editors) → allowed.
4. Draft email (even from an admin) → **403** (must be `status = published`).
5. Non-email post → **404** (so a forged body never reaches the policy for the wrong document type).
6. Successful response: `Content-Type: application/zip`, `Content-Disposition: attachment`, and the temp file is unlinked in the same `finally` that streamed it (`BinaryFileResponse::deleteFileAfterSend(true)`).
7. Validation / translation / resolution failure → **422** with a structured body — see [§6.3](#63-failure-bodies).

### 5.2 The service API (host jobs, cron, queue)

Same service the HTTP route wraps; you can call it from a queued host job:

```php
use Heisenberg\Services\EmailBatchExporter;
use Heisenberg\Support\EmailBatchExportResult;
use Heisenberg\Support\EmailBatchTranslationMissingException;
use Heisenberg\Support\EmailVariableResolutionException;

/** @var EmailBatchExportResult $result */
try {
    $result = app(EmailBatchExporter::class)->export($publishedEmail, [
        'format' => 'eml',
        // 'locales'   => ['en'], // default = LocaleConfig::locales()
        'recipients' => [
            ['id' => 'u1', 'values' => ['user.first_name' => 'Ada',  'unsubscribe_url' => $url1]],
            ['id' => 'u2', 'values' => ['user.first_name' => 'Ben',  'unsubscribe_url' => $url2]],
        ],
    ]);
} catch (EmailBatchTranslationMissingException $e) {
    // structural: this post is missing complete persisted content for one or
    // more requested locales. The exception's `$locales` is the missing list,
    // `$postLocale` is the row's home locale. NEVER a runtime value.
} catch (EmailVariableResolutionException $e) {
    // aggregated per-recipient × per-locale failures. `getFailures()` returns
    // list<{key, reason}> where `key` is `<recipientId>/<locale>/<variable>`
    // and `reason` is one of REASON_UNKNOWN_TOKEN, REASON_MISSING_VALUE,
    // REASON_FORMATTER_FAILED, REASON_INCOMPATIBLE_TARGET. NEVER a runtime
    // value, NEVER a formatter internals string. NO zip is on disk at this point.
}

// $result->path, $result->fileCount, $result->recipientCount, $result->locales
```

### 5.3 ZIP layout

```
{slug}/{locale}/{id}.{html|eml}
```

One file per (recipient, locale). For `en`+`fr` locales and 2 recipients you get exactly 4 files.

- `{slug}` is the email post's own slug (`Post::$slug`, sanitized via `Str::slug`).
- `{locale}` is one of `LocaleConfig::locales()` (or whatever you passed in `locales`).
- `{id}` is the admin-supplied `id` — must match `^[A-Za-z0-9][A-Za-z0-9._-]*$` and be unique within the batch.
- `{html|eml}` is the format you chose.

### 5.4 Single-row translation completeness

Heisenberg's current Post model is **single-row**: `title_<locale>` and locale-suffixed block attributes live on the same row. One `EmailBatchExporter::export()` call generates the full N × requested-locales matrix.

If a requested locale does **not** have complete persisted content (title plus every block's translatable attributes plus, when an excerpt is authored elsewhere, the excerpt for that locale), the exporter raises `EmailBatchTranslationMissingException`. The HTTP route returns that as **422** with `{ message, locales: [...] }`. **No zip is allocated.** An admin never receives an English body mislabeled as French.

---

## 6. Authorizing, configuring, validating

### 6.1 The flat tier key

`PostPolicy::generateEmailBatch` is a single three-part check:

1. `email.generate` tier (resolved via the bundled `ConfigRoleGate` or your own `RoleGate` binding)
2. `$post->type === 'email'`
3. `$post->status === 'published'`

The flat tier key is `config('heisenberg.roles')['email.generate']` and defaults to `['admin']`. A host that wants editors to also batch-export overrides that entry without touching any policy class:

```php
// config/heisenberg.php
'roles' => [
    // ... your existing entries
    'email.generate' => ['admin', 'editor'], // example: add editor
],
```

### 6.2 Other configuration

```php
// config/heisenberg.php
'email' => [
    'routes'               => true,
    'route_prefix'         => 'emails',
    'batch_max_recipients' => 100, // host-overridable; cap per batch
],

'roles' => [
    // ...
    'email.generate' => ['admin'],
],

// In your own published mail config:
// config/mail.php
'from' => [
    'address' => env('MAIL_FROM_ADDRESS', 'no-reply@example.test'),
    'name'    => env('MAIL_FROM_NAME', 'Example'),
],
```

`mail.from.address` is the **only** mailer setting Heisenberg reads, and only to set the `From:` header on generated `.eml` files (same as the single-document EML export). With it unconfigured and a batch request asking for `eml`, the exporter raises a controlled `InvalidArgumentException` (HTTP 422) — Heisenberg does not connect to any SMTP server.

### 6.3 Failure bodies

Every failure mode on the admin batch route returns a structured JSON body.

```jsonc
// 422 — unknown / missing / formatter / target failure (aggregated)
{
  "message":  "Email variable resolution failed for 2 token(s): user.first_name: missing value; user.unknown: unknown token",
  "failures": [
    { "key": "user.first_name", "reason": "missing value" },
    { "key": "user.unknown",    "reason": "unknown token" }
  ],
  "keys": ["user.first_name", "user.unknown"]
}

// 422 — invalid format / over cap / unregistered key / non-JSON / etc.
{
  "message": "Batch export body must use application/json."
}

// 422 — incomplete translation for a requested locale
{
  "message": "Cannot export email for the requested locale(s) (fr): this post is missing complete persisted content for those locales (home locale: \"en\").",
  "locales": ["fr"]
}

// 403 — Gate::authorize failed (anonymous, author, editor, viewer; or a draft email even from admin)
```

No runtime values, no formatter exception messages, no stack traces, no raw `{{ ... }}` tokens appear in any of these bodies.

### 6.4 Sample-only preview / single export

Every author-facing GET (`/{prefix}/{slug}`, `/{prefix}/{slug}/export?format=html|eml`, and the id-scoped editor redirects that delegate to those) passes `EmailVariableContext::samples(app(EmailVariableRegistry::class))` explicitly. Runtime values are **never** accepted from query strings, request bodies, or headers; `?variables[user.first_name]=Ada` is silently dropped at the controller boundary. The renderer default is a strict empty runtime context — the controller is the only point that opts author-facing GETs into samples.

---

## 7. Compile-checked host examples

Three files under [`examples/EmailVariables/`](../examples/EmailVariables/) ship as copy-paste starting points. All three are `php -l` clean and were runtime-loaded during Task 8 verification (the `Money` formatter correctly produced `NGN 2,500.00` from a `new Money(250000, 'NGN')` runtime value).

- [`examples/EmailVariables/MoneyEmailVariableType.php`](../examples/EmailVariables/MoneyEmailVariableType.php) — host custom `money` formatter implementing `Heisenberg\Contracts\EmailVariableType` exactly, with a `Host\Money` placeholder value object clearly labeled so the file is self-contained.
- [`examples/EmailVariables/AppServiceProvider.php`](../examples/EmailVariables/AppServiceProvider.php) — `boot(EmailVariableRegistry $variables)` injecting the Heisenberg singleton, registering a custom `money` type and a mix of built-in (`text`, `url`, `email`) definitions, with non-scalar `Money` samples and `https://example.test/unsubscribe/sample` placeholders.
- [`examples/EmailVariables/BatchExport.php`](../examples/EmailVariables/BatchExport.php) — `email:export-batch` artisan command calling `app(EmailBatchExporter::class)->export()` with the exact `{id, values}` recipients shape, consuming `EmailBatchExportResult` (`path`/`fileCount`/`recipientCount`/`locales`), handling `EmailBatchTranslationMissingException` and `EmailVariableResolutionException` separately. No `Mail::send`.

Drop them into your app (swap the namespaces for yours; replace `Host\Money` with your own value type), and you have working editor metadata, sample preview, the mailable seam, and the admin batch export.

---

## 8. End-to-end recipe

A typical 10-step setup, in order:

1. **Decide your variables and their types.** Start with the universal `user.first_name` (`text`) and `unsubscribe_url` (`url`). Add anything else — a `money` balance, an account number, a tier label, a custom `subject_line`.
2. **Write the formatter types.** Start with the built-in `text`, `url`, `email`. Add a custom type only when a value isn't a string or needs locale-aware formatting. Always throw on unsupported values.
3. **Register everything in your provider.** Custom types first, then definitions with non-secret samples.
4. **Author one email post.** In `/editor`, switch to `email` document type and pick from your registered variables via the picker. Tokens persist unchanged.
5. **Translate.** The block-renderer already handles locale-suffixed block attributes; the picker and editor work in every `LocaleConfig::locales()` language.
6. **Publish.** The post's `status = published` gate on the batch endpoint will reject anything else.
7. **Decide your delivery channel.** A queued Laravel mailable is the smallest integration; an artisan command reading a CSV and calling the exporter directly is the largest. Both share the same renderer and registry.
8. **Authorize.** Default: `admin` only. Remap `heisenberg.roles.email.generate` if you want other roles.
9. **Test with the bundle.** `php -l examples/EmailVariables/*.php`, then run `vendor/bin/phpunit` (full suite: 1539/6121/1 skip) and the focused `tests/Email` suite (184/526).
10. **Add your own coverage.** Every host variable your team ships deserves at least one focused test — registered, formatter-invoked, and rendered. The plan's Task 2/3/4/6 test files are the model.

---

## Appendix A. Public surface cheat sheet

| Symbol | Path | Purpose |
|---|---|---|
| `Heisenberg\Contracts\EmailVariableType` | `src/Contracts/EmailVariableType.php` | Custom-formatter contract. |
| `Heisenberg\Support\EmailVariableDefinition` | `src/Support/EmailVariableDefinition.php` | Value object the host builds once per variable. |
| `Heisenberg\Support\EmailVariableContext` | `src/Support/EmailVariableContext.php` | Runtime/sample immutable flat-map. |
| `Heisenberg\Services\EmailVariableRegistry` | `src/Services/EmailVariableRegistry.php` | Host-registered singleton. |
| `Heisenberg\Services\EmailVariableInterpolator` | `src/Services/EmailVariableInterpolator.php` | Token-aware attribute classification + escaping-before-sanitization. |
| `Heisenberg\Services\EmailRenderer::render(Post, string, bool = false, ?EmailVariableContext = null): EmailRenderResult` | `src/Services/EmailRenderer.php` | The renderer. |
| `Heisenberg\Mail\HeisenbergMailable::__construct(int\|string, ?string = null, array\|EmailVariableContext = [])` | `src/Mail/HeisenbergMailable.php` | Per-recipient mailable. |
| `Heisenberg\Services\EmailBatchExporter::export(Post, array): EmailBatchExportResult` | `src/Services/EmailBatchExporter.php` | One-call N×locales ZIP factory. |
| `Heisenberg\Http\Controllers\EmailPreviewController` | `src/Http/Controllers/EmailPreviewController.php` | Public preview + single export. |
| `Heisenberg\Http\Controllers\EmailBatchExportController` | `src/Http/Controllers/EmailBatchExportController.php` | Admin batch ZIP HTTP route. |
| `resources/views/components/live/pickers/email-variable-menu.blade.php` | `resources/views/components/live/pickers/email-variable-menu.blade.php` | Email-only authoring picker. |

## Appendix B. Why Heisenberg does not own SMTP

Three locked decisions from `.hermes/plans/2026-08-25_190059-email-template-variables.md` that this guide respects and you should not try to undo:

1. **No recipient discovery from users / roles / HeisenbergUser.** N is `count($recipients)` on the admin-supplied list. Capped at `config('heisenberg.email.batch_max_recipients')` (default 100).
2. **No SMTP configuration in Heisenberg.** EML output reads only `mail.from.address` (and optionally `mail.from.name`) to set the `From:` header, same posture as the single-document EML export. Sending is the host's job.
3. **No campaign / subscriber / scheduling product.** The README's "Heisenberg has no users" promise is the same boundary. Mass **file generation** (this wave) is in scope; mass **mailing** is not.

If you want any of those, build them on top of `Heisenberg\Services\EmailRenderer` and `Heisenberg\Services\EmailBatchExporter` — those are the package's contract surface for "produce the files; you do the rest".

---

## Appendix C. Verification commands

```bash
# Compile-checked host examples
php -l examples/EmailVariables/MoneyEmailVariableType.php
php -l examples/EmailVariables/AppServiceProvider.php
php -l examples/EmailVariables/BatchExport.php

# Focused E5 suites
vendor/bin/phpunit tests/Email tests/Editor/EmailEditorWiringTest.php \
                 tests/Editor/EmailVariablePickerTest.php \
                 tests/Editor/EmailVariablePickerWiringTest.php
# Expected: OK (219 tests, 650 assertions)

# Full package suite (allow ~25 minutes)
php -d memory_limit=1G vendor/bin/phpunit --no-progress
# Expected: OK (1539 tests, 6121 assertions, 1 skipped, 0 failures, 0 errors)
```

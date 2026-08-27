<p align="center">
  <img src="resources/img/heisenberg-logo.svg" alt="Heisenberg" width="96">
</p>

<h1 align="center">Heisenberg</h1>

<p align="center">
  A block-based content engine and bilingual blog backend for Laravel.<br>
  Drop a full Gutenberg-style editor, media library, taxonomy, post templates and an AI writing assistant into any Laravel app.
</p>

<p align="center">
  <a href="https://packagist.org/packages/heisenberg/heisenberg"><img src="https://img.shields.io/packagist/v/heisenberg/heisenberg" alt="Latest version"></a>
  <a href="https://packagist.org/packages/heisenberg/heisenberg"><img src="https://img.shields.io/packagist/php-v/heisenberg/heisenberg" alt="PHP version"></a>
  <img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-red" alt="Laravel">
  <img src="https://img.shields.io/badge/license-Apache--2.0-blue" alt="License">
</p>

---

Heisenberg has **no users, no theme lock-in and no frontend framework**. Your app keeps its users, its routes and its pages — Heisenberg brings the editor at `/editor`, the content model, and narrow contracts you bind to make everything yours.

## Installation

```bash
composer require heisenberg/heisenberg
php artisan migrate
php artisan storage:link   # public media URLs (the uploads link is pre-registered)
```

That's it — open **`/editor`**. The service provider is auto-discovered, migrations load automatically, and every seam ships a working default. On a machine where `APP_ENV=local`, everything works anonymously out of the box; real deployments authorize through your own users (below).

Optional but recommended:

```bash
composer require intervention/image:^3.9   # responsive image variants (v4 is NOT compatible)
```

## Connecting your users

Heisenberg never creates users. Your existing users get abilities through the `RoleGate` contract, with four canonical roles — WordPress-familiar:

| Role | Can |
|---|---|
| `admin` | everything, including AI/provider settings |
| `editor` | publish, schedule, archive; manage anyone's media |
| `author` | write and draft; upload media; edit own files |
| `viewer` | browse and pick media, read-only |

The bundled gate reads either **Spatie permissions** (`getRoleNames()`) or a plain **`role` string column** on your user model:

```php
Schema::table('users', fn (Blueprint $t) => $t->string('role')->nullable());
// then: $user->role = 'editor';
```

Different role names in your app? Remap them in `config/heisenberg.php` under `roles`, or bind your own `RoleGate` implementation entirely. Production apps should also wrap the route groups in their own auth middleware (`heisenberg.middleware.editor` / `.media` / `.ai`, all default `['web']`).

## CSP nonce (Content Security Policy)

If your app enforces a CSP with `nonce-based` style/script sources (e.g. via Laravel's `Vite::useCspNonce()`), Heisenberg's inline `<style>` and `<script>` blocks will be blocked by the browser unless they carry the same nonce. Heisenberg automatically reads the nonce from `Vite::useCspNonce()` — you just need to set it in a service provider:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Vite;

public function boot(): void
{
    Vite::useCspNonce();
    // or: Vite::useCspNonce('your-static-nonce') if you manage nonces yourself
}
```

That's it. Heisenberg picks it up and adds `nonce="..."` to every inline `<style>`, `<script>`, and `<link rel="stylesheet">` tag. No `'unsafe-inline'` needed.

If you don't use CSP nonces (most dev environments), Heisenberg works without this — the nonce attribute is simply omitted.

## Email personalization and batch files

Email documents use the same editor and block renderer, with host-registered `{{ dotted.key }}` variables for per-recipient personalization.

### Quick start

1. **Register your variables** in `AppServiceProvider::boot()`:

```php
use Heisenberg\Services\EmailVariableRegistry;
use Heisenberg\Support\EmailVariableDefinition;

public function boot(EmailVariableRegistry $variables): void
{
    $variables->register(new EmailVariableDefinition(
        key: 'user.first_name',
        label: 'First name',
        type: 'text',
        sample: 'Tedy',
        group: 'User',
    ));

    $variables->register(new EmailVariableDefinition(
        key: 'unsubscribe_url',
        label: 'Unsubscribe URL',
        type: 'url',
        sample: 'https://example.test/unsubscribe/sample',
        group: 'Links',
    ));
}
```

2. **Author an email post** — switch to `email` document type in `/editor`, use the variable picker to insert tokens.

3. **Send via your mailer**:

```php
use Heisenberg\Mail\HeisenbergMailable;

Mail::to($user->email)->send(new HeisenbergMailable(
    $emailPost->getKey(),
    $user->preferred_locale ?? 'en',
    [
        'user.first_name' => $user->first_name,
        'unsubscribe_url' => URL::signedRoute('unsubscribe', ['user' => $user->getKey()]),
    ],
));
```

4. **Or render without mailing**:

```php
use Heisenberg\Services\EmailRenderer;
use Heisenberg\Support\EmailVariableContext;

$result = app(EmailRenderer::class)->render(
    $emailPost,
    'en',
    false,
    EmailVariableContext::runtime([
        'user.first_name' => 'Ada',
        'unsubscribe_url' => 'https://example.test/unsubscribe/ada',
    ]),
);

// $result->subject, $result->html, $result->text, $result->embeds
```

### Admin batch ZIP

Export N recipients × locales in one call (admin-only by default):

```
POST /editor/email/{post}/batch-export
{
  "format": "html",
  "locales": ["en", "fr"],
  "recipients": [
    { "id": "u1", "values": { "user.first_name": "Ada", "unsubscribe_url": "..." } },
    { "id": "u2", "values": { "user.first_name": "Ben", "unsubscribe_url": "..." } }
  ]
}
```

### Configuration

```php
// config/heisenberg.php
'email' => [
    'routes'               => true,
    'route_prefix'         => 'emails',
    'batch_max_recipients' => 100,
],

'roles' => [
    'email.generate' => ['admin'], // who may run batch export
],
```

Heisenberg **does not configure SMTP or send campaigns** — it renders the files; you send them. Full guide in [`docs/email-personalization.md`](docs/email-personalization.md); system reference in [`docs/email-system.md`](docs/email-system.md); copy-paste examples in [`examples/EmailVariables/`](examples/EmailVariables/).

## Publishing content with your own templates

Heisenberg renders block content; **you own the page around it**. A post template is a JSON contract declaring which chrome capabilities the page has — featured image, authored table of contents, reading time, breadcrumbs, share buttons, comments, and more:

```php
// config/heisenberg.php (php artisan vendor:publish --tag=heisenberg-config)
'template_root' => resource_path('heisenberg-templates'),
```

```jsonc
// resources/heisenberg-templates/mysite/mysite.json
{
  "name": "heisenberg/mysite",
  "render": { "view": "blog.show" },   // YOUR Blade view
  "capabilities": {
    "featuredImage":   { "enabled": true, "source": "post-attribute", "context": "hero" },
    "tableOfContents": { "enabled": true, "source": "entries" },
    "comments":        { "enabled": true, "allowGuests": true, "sortOrder": "newest" }
  }
}
```

Validate with `php artisan templates:verify`. In your controller, resolve `PostTemplateRegistryService` from the container, read the contract, and render the body exactly like the built-in preview does (`BlockRenderer::renderBlocks()` plus the block/theme stylesheets). The full schema — all 11 capabilities and the render-vs-adapter decision for each — is in [`docs/post-template-schema.md`](docs/post-template-schema.md).

Data Heisenberg doesn't own arrives through **provider contracts** with null defaults — bind yours in the published config:

```php
'post_template' => [
    'comments_provider' => App\Support\MyCommentProvider::class,  // implements PostCommentProvider
    // post_views_provider, related_posts_provider, seo_meta_provider
],
```

## What the editor gives your authors

- **Twelve block types** — headings, paragraphs, images, buttons, quotes, lists, icons, separators, embeds, and nestable groups/columns — each defined by a JSON contract, validated server-side, rendered through a sanitizing pipeline.
- **Full-page authoring chrome** — inspector, floating toolbar, navigator tree, undo/redo, revisions, autosave with optimistic locking, drag & drop, dark mode, `en`/`fr` UI.
- **Post management** — status lifecycle (draft → review → published/scheduled/archived, tier-gated), categories & tags, featured image, authored table of contents, page layout and discussion settings.
- **Media library** — drag-drop uploads with per-file progress, responsive variants, bilingual alt/caption metadata, virus-scan seam (`VirusScanner` contract), collision-safe naming (`photo(1).jpg`), role-scoped permissions.
- **Visual ⇄ Code view** — the whole document round-trips through a compact shortcode dialect (see [`docs/code-view.md`](docs/code-view.md)).
- **AI writing assistant** — bring your own provider (Anthropic, OpenAI, or any OpenAI-compatible endpoint; keys stored write-only and encrypted). The assistant writes to the live canvas through a validated tool call, streams its reasoning, and remembers conversations. Works with MCP in both directions: connect external MCP servers to the assistant, and/or expose Heisenberg itself as an MCP server so external agents can author drafts via bearer token.

## Configuration surface

`php artisan vendor:publish --tag=heisenberg-config` gives you `config/heisenberg.php`: table names and model classes (all swappable), role map, lifecycle transitions, media rules (size caps, allowed extensions, virus scanner), template root, AI provider settings, and the middleware stacks for each route group. Every contract (`RoleGate`, `MediaResolver`, `VirusScanner`, `AuditSink`, `IconProvider`, the four template providers) is a config-named binding with a working default.

**Publishing the config is optional** — Heisenberg works fully off its own shipped defaults with nothing published at all. If you *do* publish it: any new nested setting a later version of this package adds (a new provider default, a new role ability, a new lifecycle edge) is merged into your published file automatically at boot, at any depth — you never lose a new default just because you already had a sibling key set. What stays entirely yours, forever, on every upgrade: every value you already set, **including the contents of lists** (`roles`, `lifecycle.transitions.draft`, `middleware.editor`, and the like are copied whole, never merged element-by-element) — so if an upgrade changes what one of *your* published lists should contain (not just adds a new key beside it), you still have to edit that list by hand; nothing can detect a value that's merely gone stale versus one you meant to customize. Run `php artisan heisenberg:config-diff` after upgrading a host with a published config — it shows every key where your file differs from the package default, side by side, so you can tell "I meant to override this" from "this one's stale" at a glance.

## Security posture

- Every content write funnels through one validated, sanitizing pipeline (HTML Purifier at the XSS boundary); nothing bypasses it — including AI- and MCP-authored content.
- Media uploads: extension allowlist, size caps, scan-before-write, no PHP in the public read path (see [`docs/media-library-backend-blueprint.md`](docs/media-library-backend-blueprint.md) for the web-server hardening snippets).
- The anonymous local-dev convenience is structurally incapable of activating outside `APP_ENV=local`.
- The inbound MCP server is disabled by default and draft-only when enabled.

## Documentation

| Doc | What it covers |
|---|---|
| [`docs/BLUEPRINT.md`](docs/BLUEPRINT.md) | The full specification — every class, column, contract key and security gate |
| [`docs/block-schema.md`](docs/block-schema.md) | Writing block contracts |
| [`docs/post-template-schema.md`](docs/post-template-schema.md) | Writing post templates |
| [`docs/code-view.md`](docs/code-view.md) | The shortcode dialect |
| [`docs/media-library-backend-blueprint.md`](docs/media-library-backend-blueprint.md) | The media subsystem, end to end |
| [`docs/ai-mcp-plan.md`](docs/ai-mcp-plan.md) | The AI assistant and MCP integration |
| [`docs/email-personalization.md`](docs/email-personalization.md) | **Host usage guide for E5:** register variables, author tokens, send per-recipient emails, run the admin batch ZIP, authorize, configure, validate |
| [`docs/email-system.md`](docs/email-system.md) | Email rendering, host variables, sample preview, and admin batch ZIP export (as-built system reference) |

## Requirements

PHP ^8.2 · Laravel 11 / 12 / 13 · Livewire ^4.3

## License

[Apache-2.0](LICENSE)

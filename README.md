# Heisenberg

A block-based content engine and bilingual blog backend for Laravel — a
Gutenberg-style system where content is an ordered list of typed blocks, each
validated against a JSON **contract** and compiled to safe, bilingual HTML by a
hardened renderer.

Heisenberg is a clean-room reconstruction of the GTC `Blog` module, extracted as
a standalone Composer package with zero host couplings. The full specification —
every class, column, contract key, and security gate — lives in
[`docs/BLUEPRINT.md`](docs/BLUEPRINT.md).

## The three layers

1. **Block engine** — the reusable jewel: block types, contracts, registry,
   validators, renderer, sanitizer. Knows nothing about posts.
2. **Blog domain** — posts, taxonomy, revisions, publishing lifecycle.
3. **Couplings** — narrow contracts (`MediaResolver`, `RoleGate`, `AuditSink`,
   `IconProvider`, `HeisenbergUser`, `VirusScanner`) that keep layers 1–2 free of
   any host class. Every one ships a working default.

## Status

Usable for **authoring**: install it, run the migrations, and `/editor` gives you
a working block editor that saves posts, blocks, categories and tags to your
database.

Not yet usable for **publishing**: the package does not ship a public-facing
route that renders a saved post. `BlockRenderer` produces the HTML and post
templates describe the page chrome, but wiring them into a URL is currently the
host's job — see [Rendering content](#rendering-content-in-your-app).

| Area | State |
|------|-------|
| Block engine — contracts, registry, validator, sanitizer, renderer | ✅ |
| Persistence — posts, blocks, optimistic concurrency, autosave, soft deletes | ✅ |
| Editor (`/editor`) — canvas, inspector, toolbar, navigator, media, themes | ✅ |
| Taxonomy — categories + tags, both many-to-many, wired into the editor | ✅ |
| Media library — upload, variants, virus-scan seam, policy | ✅ |
| Authorization — 4 policies over `RoleGate` + config tiers | ✅ |
| Localization — `en`/`fr` editor UI, per-locale content columns, switcher | ✅ |
| Post template contracts — schema, validator, registry, `templates:verify` | ✅ |
| **Public rendering** — a route that serves a post through a template | ⬜ |
| **Block library** — only `heading` + `paragraph` ship today | ⬜ |
| Revisions — model + table exist; nothing snapshots on save | ⬜ |
| Production asset serving — editor CSS is concatenated per request, `no-store` | ⬜ |

The working plan and its open items live in [`TODO.md`](TODO.md).

## Requirements

- PHP ^8.2
- Laravel 11 or 12
- Livewire ^4.3 (the media library is a Livewire component)

Optional: `intervention/image` for responsive image variants on upload — without
it, uploads still work but ship no derivatives.

---

# Installing into a Laravel app

## 1. Require the package

Once published to Packagist:

```bash
composer require heisenberg/heisenberg
```

While developing against a local checkout, add a path repository to the host
app's `composer.json` first:

```json
{
    "repositories": [
        { "type": "path", "url": "../Heisenberg", "options": { "symlink": true } }
    ]
}
```

```bash
composer require heisenberg/heisenberg:@dev
```

`HeisenbergServiceProvider` is registered through Laravel's package
auto-discovery — there is nothing to add to `bootstrap/providers.php`.

## 2. Run the migrations

Migrations are loaded from the package (not published), so a plain migrate picks
them up:

```bash
php artisan migrate
```

This creates `heisenberg_posts`, `heisenberg_blocks`, `heisenberg_public_files`,
`heisenberg_categories`, `heisenberg_tags`, `heisenberg_post_tag`,
`heisenberg_category_post` and `heisenberg_post_revisions`. Every table name is
config-driven — if you want different names, publish the config **before**
migrating and edit `heisenberg.tables`.

The `uploaded_by` foreign key on the media table is only added when the table
named by `heisenberg.users_table` already exists, so ordering against your own
user migration is not a concern.

## 3. Publish the config

```bash
php artisan vendor:publish --tag=heisenberg-config
```

Then point the package at your user model in `.env` (it defaults to
`App\Models\User`):

```dotenv
HEISENBERG_USER_MODEL="App\Models\User"
```

Your user model should implement `Heisenberg\Contracts\HeisenbergUser`. It is a
marker interface over `getAuthIdentifier()` — Laravel's `Authenticatable` already
satisfies the method, so implementing it is a one-line `implements` change:

```php
use Heisenberg\Contracts\HeisenbergUser;

class User extends Authenticatable implements HeisenbergUser
{
    // ...
}
```

## 4. Set up media storage

The provider registers an `uploads` disk (`storage/uploads`, public URL
`/uploads`) **only if your app has not already defined one** — a package must not
clobber a host's `filesystems.php`.

`php artisan storage:link` does not know about that pair by default, so add it to
`config/filesystems.php`:

```php
'links' => [
    public_path('storage') => storage_path('app/public'),
    public_path('uploads') => storage_path('uploads'),   // ← add this
],
```

```bash
php artisan storage:link
```

Uploaded media is then served by your web server with zero PHP in the read path.
To move media to S3 or a CDN instead, point `heisenberg.media.disk` at any other
configured disk — every URL on `PublicFile` is disk-aware and follows.

> In `local`, the package also serves `/uploads/{path}` through a controller as a
> convenience so the editor works before you create the symlink. That route is
> for development only.

## 5. Open the editor

```
GET  /editor            # new document
GET  /editor/{post}     # open an existing post
GET  /editor/components # component gallery (development reference)
```

The editor is vanilla JS — no bundler, no build step, no `npm install`. Its CSS
is served by the package at `/heisenberg-assets/editor.css`.

If you prefer to serve the assets statically, publish them:

```bash
php artisan vendor:publish --tag=heisenberg-assets
```

---

# Configuration

## Routes and middleware — read this before deploying

Both route groups are **enabled and gated only by `web` out of the box**, so a
fresh install works in development without any auth wiring. That posture is not
safe in production. Widen the middleware in `config/heisenberg.php`:

```php
'middleware' => [
    'editor' => ['web', 'auth'],           // /editor and its JSON API
    'media'  => ['web', 'auth'],           // media library upload/update/destroy
],
```

Or turn the package's routes off entirely and mount its controllers under your
own admin routes:

```php
'editor' => ['routes' => false, 'locales' => ['en', 'fr']],
'media'  => [/* ... */ 'routes' => false],
```

There is also a local-only authorization bypass, on by default, so a fresh
install is not 403ing on itself before you have wired up auth:

```dotenv
HEISENBERG_ALLOW_ANONYMOUS_IN_LOCAL=false
```

It requires **both** the flag and `app()->environment('local')`, re-checked on
every authorization call — there is no way to widen it to another environment
through config alone. Turn it off once real auth exists.

## Roles

Authorization runs through tiers, not literal role names, so the package never
hard-codes your roles. Heisenberg's own canonical vocabulary is WordPress-familiar
— `admin`, `editor`, `author`, `viewer` — and the bundled default map already
resolves the tiers to it. Remap the tiers to whatever role strings your app
actually uses:

```php
'roles' => [
    'super'   => ['admin'],
    'admins'  => ['admin'],
    'editors' => ['admin', 'editor'],
    'authors' => ['admin', 'editor', 'author'],
],
```

The bundled `ConfigRoleGate` reads roles from Spatie's `getRoleNames()` when
present, and falls back to a plain `role` string column. If your app stores roles
some other way, bind your own gate:

```php
'role_gate' => \App\Support\HeisenbergRoleGate::class,
```

`ConfigRoleGate::systemActor()` returns `null` — bind your own gate if you need
scheduled publishing to act as a specific user.

Publishing authority is separate from the tier map and lives in
`heisenberg.lifecycle.role_permissions` (by default, `authors` may submit for
review; only `editors` may publish, schedule or archive).

## Virus scanning

The default `NullVirusScanner` reports everything clean. Bind a real scanner in
production:

```php
'media' => [
    'virus_scanner' => \App\Support\ClamAvScanner::class,
    'scan' => ['fail_open' => false],   // default: reject uploads if the scanner is down
],
```

Extension allow-listing is enforced inside `MediaLibraryService`, not only at the
form-request layer, so it holds no matter which entry point uploads a file.

## Locales

The editor ships English and French. The switcher lives in the editor footer and
persists the choice in the session; `EditorLocaleMiddleware` is pushed onto the
`web` group so the locale applies app-wide for hosts that share a session.

```php
'editor' => ['locales' => ['en', 'fr']],
```

Adding a third locale is a coordinated change across `heisenberg.editor.locales`,
`LocaleController::LOCALES` and `EditorLocaleMiddleware::LOCALES`, plus a
`resources/lang/<locale>/` directory. Note that **post content** is stored in
per-locale columns (`excerpt_en`/`excerpt_fr`, `rendered_html_en`/`_fr`), so a
third content language is a migration, not just a config entry.

## Swapping models and tables

Every model and table name is config-driven:

```php
'models' => [
    'post'        => \App\Models\BlogPost::class,   // extend Heisenberg\Models\Post
    'block'       => \Heisenberg\Models\Block::class,
    'public_file' => \Heisenberg\Models\PublicFile::class,
    'category'    => \Heisenberg\Models\Category::class,
    'tag'         => \Heisenberg\Models\Tag::class,
],
```

If you swap a model class, register your own policy for it — Laravel resolves
policies from the exact class of the instance passed to `authorize()`, so the
package's `Gate::policy(Post::class, PostPolicy::class)` will not cover a
subclass.

## The coupling contracts

Each is a singleton bound to a config key, with a working no-op default. Bind
your own to integrate with your app.

| Contract | Config key | Default | Bind your own to… |
|---|---|---|---|
| `RoleGate` | `role_gate` | `ConfigRoleGate` | answer role questions from your own store |
| `MediaResolver` | `media_resolver` | `NullMediaResolver` | resolve image URLs through your media library |
| `AuditSink` | `audit_sink` | `NullAuditSink` | log status transitions (e.g. Spatie activitylog) |
| `IconProvider` | `icon_provider` | `PhosphorIconProvider` | serve a different icon set |
| `VirusScanner` | `media.virus_scanner` | `NullVirusScanner` | scan uploads with ClamAV |
| `PostViewsProvider` | `post_template.post_views_provider` | `NullPostViewsProvider` | supply view counts to templates |
| `PostCommentProvider` | `post_template.comments_provider` | `NullPostCommentProvider` | supply comments to templates |
| `RelatedPostsProvider` | `post_template.related_posts_provider` | `NullRelatedPostsProvider` | supply related posts |
| `PostSeoMetaProvider` | `post_template.seo_meta_provider` | `NullPostSeoMetaProvider` | supply SEO meta |

The last four exist because those capabilities need storage this package does not
own. The other seven post-template capabilities (table of contents, featured
image, reading time, author box, share buttons, breadcrumbs, pagination) render
from data that already exists and need no binding.

---

# Rendering content in your app

Heisenberg stores each post's compiled HTML per locale, so the cheapest read path
is a column:

```php
$post = \Heisenberg\Models\Post::where('slug', $slug)->firstOrFail();

return view('blog.show', [
    'post' => $post,
    'html' => app()->getLocale() === 'fr'
        ? $post->rendered_html_fr
        : $post->rendered_html_en,
]);
```

To render from the block tree instead — after changing a contract, say — go
through the renderer:

```php
use Heisenberg\Services\BlockRenderer;

$html = app(BlockRenderer::class)->renderBlocks($blocks, locale: 'en');
```

`BlockRenderer` sanitizes through HTMLPurifier, enforces a nesting depth cap, and
emits classes under the `heisenberg.css_prefix` namespace (`hb-` by default).

**The gap:** there is no shipped route, controller or view that turns a `Post`
into a public page, and no column linking a post to a post template. Post
templates are validated contracts on disk today — `templates:verify` checks them,
and `PostTemplateRegistryService` reads them — but nothing renders one. Until
that lands, the chrome around `rendered_html_*` is yours to write.

# Blocks

Two contracts ship today: `heisenberg/heading` and `heisenberg/paragraph`. They
live at `resources/blocks/<name>/<name>.json` and are auto-discovered — there is
no allow-list to update.

Point the registry at your own directory to add blocks without forking:

```php
'block_root'   => resource_path('heisenberg/blocks'),
'block_prefix' => 'acme',
```

The contract schema is documented in [`docs/block-schema.md`](docs/block-schema.md).
Validate contracts on disk with:

```bash
php artisan blocks:verify
php artisan templates:verify
```

Invalid contracts are excluded from the registry rather than crashing the editor,
so these commands are the way to find out that one is broken.

# Post templates

A post template is a JSON contract declaring which page-chrome capabilities a
post's public page uses. Same shape, validator style and registry conventions as
block contracts, deliberately, so there is one mental model to learn. The schema
is documented in [`docs/post-template-schema.md`](docs/post-template-schema.md),
with `resources/templates/article` as the shipped reference.

```php
'template_root'   => resource_path('heisenberg/templates'),
'template_prefix' => 'acme',
```

---

# Package development

```bash
composer install
composer test          # or: vendor/bin/phpunit
```

The suite runs on Orchestra Testbench against in-memory SQLite, with
`failOnRisky` and `failOnWarning` enabled.

To run the editor against a persistent dev database:

```bash
vendor/bin/testbench migrate
vendor/bin/testbench serve
```

Further documentation lives in [`docs/`](docs/) — the blueprint, the block and
post-template schemas, the media-library backend design, the editor remediation
audit, and the i18n brief.

## License

Apache-2.0 — see [LICENSE](LICENSE).

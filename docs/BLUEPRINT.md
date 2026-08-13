# Heisenberg

### A Standalone Laravel Block‑Content Engine & Bilingual Blog Backend
### Reconstruction Blueprint — reverse‑engineered from the GTC `Blog` module

---

> **What this document is.** A faithful, exhaustive specification of the GTC platform's blog‑builder backend, written so that Heisenberg can be **rebuilt from scratch in a clean repository** without ever opening the GTC codebase again. Every class, method signature, database column, block‑contract key, security gate, and route in here was read out of the live `Modules/Blog` source — nothing is invented. Where the current code is welded to GTC‑specific things (its `User` model, its media model, its hard‑coded role names), this document records **both** what exists today **and** the clean‑room replacement Heisenberg should ship instead.
>
> **How to read it.** Two tags run throughout:
>
> - **`[AS‑BUILT]`** — exactly how the GTC `Blog` module does it today. Treat this as ground truth about proven, hardened logic you want to preserve.
> - **`[TARGET]`** — how Heisenberg should do it instead, once the GTC couplings are severed. This is the redesign, not the current reality.
>
> When a section has no `[TARGET]` note, the as‑built behaviour ports across unchanged.
>
> **Source of truth.** GTC `Modules/Blog` at commit-time of 2026‑06‑04. Block contracts additionally live in the **host app** at `resources/views/components/rm-ui/dashboard/blog-builder/blocks/` (see §3.4 — this is the single most surprising coupling).

---

## Table of Contents

- **Part 0 — Overview & Design Tenets**
  - 0.1 What Heisenberg is
  - 0.2 What Heisenberg is **not** (scope boundary)
  - 0.3 Design tenets
  - 0.4 The three layers: Engine · Domain · Couplings
  - 0.5 Glossary
- **Part 1 — Package Layout & Bootstrapping**
  - 1.1 Package definition (`composer.json`, `module.json`)
  - 1.2 Target directory layout
  - 1.3 The Service Provider
  - 1.4 The DI singleton graph (exact bind order)
  - 1.5 Registration of config, views, lang, migrations, policies, commands, schedules
- **Part 2 — Data Model**
  - 2.1 Entity overview
  - 2.2 Enums (`BlogPostStatus`, `BlockType`)
  - 2.3 The eleven models
  - 2.4 Tables & migrations (full column spec)
  - 2.5 Content storage types
  - 2.6 `[TARGET]` User decoupling at the data layer
- **Part 3 — The Block System (the crown jewel)**
  - 3.1 Two content paths: JSON‑contract vs legacy `{type,content}`
  - 3.2 The block‑contract JSON schema (full anatomy)
  - 3.3 A verbatim example contract
  - 3.4 `BlogBlockRegistryService` (discovery · hashing · localization)
  - 3.5 `BlogBlockContractValidator` (10 validators + rules)
  - 3.6 `BlogBlocksPayloadService` (instance validation vs live registry)
  - 3.7 `BlogBlockService` (persistence + the `_allow_raw` trust gate)
  - 3.8 `BlogComponentRegistry` (safe component allowlist)
  - 3.9 `BlogBuilderService` (editor/persistence orchestrator)
  - 3.10 `[TARGET]` internalizing contracts + configurable block prefix
- **Part 4 — The Renderer (`BlockRenderer`)**
  - 4.1 Pipeline overview
  - 4.2 Dispatch: JSON‑contract path vs legacy type path
  - 4.3 Contract template rendering
  - 4.4 Locale resolution
  - 4.5 Style / CSS sanitization tokens
  - 4.6 Rich‑text sanitization
  - 4.7 Lucide icon resolution
  - 4.8 Responsive images
  - 4.9 Per‑block render contracts (all 20)
  - 4.10 The renderer security model
  - 4.11 `[TARGET]` `MediaResolver` + `IconProvider` contracts
- **Part 5 — HTML Sanitization (`HtmlSanitizationService`)**
- **Part 6 — Patterns, Revisions, Taxonomy & Slugs**
- **Part 7 — Publishing Lifecycle & State Machine**
- **Part 8 — Events, Jobs, Listeners & Queues**
- **Part 9 — HTTP Surface**
- **Part 10 — Authorization & the Role Map**
- **Part 11 — SEO Meta & Localization**
- **Part 12 — Console Commands**
- **Part 13 — The Decoupling Layer (couplings → contracts)**
- **Part 14 — Configuration Reference (`heisenberg.php`)**
- **Part 15 — Rebuild Plan (milestones)**
- **Part 16 — Open Questions & Known Quirks**
- **Appendix A — File inventory (old → new mapping)**
- **Appendix B — GTC coupling index**

---

# Part 0 — Overview & Design Tenets

## 0.1 What Heisenberg is

Heisenberg is a **block‑based content engine** for Laravel: a Gutenberg‑style system where a piece of content (a blog post, a page, anything) is an **ordered list of typed blocks**, each block is a small JSON document validated against a **contract**, and the whole list is compiled to safe, bilingual HTML by a hardened **renderer**.

It carries, around that engine, a complete **bilingual blog domain**:

- Posts with EN/FR fields, a translation‑group mechanism (one logical article = two locale rows linked by a UUID), magazine‑layout metadata (eyebrow pills, title lines, byline, hero image), SEO meta, and a view/like/comment counter set.
- A **five‑state publishing lifecycle** (draft → pending_review → published / scheduled → archived) driven by a pure state machine and a single transition Action that fires the audit + event + revision pipeline.
- **Taxonomy** (hierarchical categories + flat tags), **revisions** (point‑in‑time block snapshots + restore), **reusable block patterns** (saved block groups, optionally shared library‑wide), and **comments** (registered + guest "letters", with editor replies and moderation).
- A full **admin/staff HTTP surface**: a JSON block‑editor API (registry, autosave with optimistic locking, transitions, patterns) plus server‑rendered builder dashboards.

The engine is the reusable jewel; the blog domain is the reference application built on top of it.

## 0.2 What Heisenberg is **not** (scope boundary)

The blueprint documents the **full backend** so the rebuild can choose its surface, but the recommended package draws three concentric rings (see §0.4) and ships them as **separable layers**:

- **Out of scope entirely:** the visual block editor frontend (the JavaScript that drives the editor UI). It is *not in the Blog module today* — there is no `resources/js|assets|css` in the module. Heisenberg ships the **server** half: the contract registry it feeds the editor, the validation/persistence/render the editor calls, and the blade render partials. The editor SPA is a host concern that talks to Heisenberg's API.
- **Not opinionated about identity:** Heisenberg never defines a `User` model. It binds to whatever `Authenticatable` the host configures (§2.6, §13).
- **Not opinionated about media:** Heisenberg never reads a filesystem or a media table. Image URLs flow through a `MediaResolver` contract the host implements (§4.11, §13).

## 0.3 Design tenets

1. **Block‑first.** Structured `blocks` (a JSON array, one row per block in `*_blocks`) are the single source of truth for content. Rendered HTML (`rendered_html_en/_fr`) is a **derived cache**, regenerated by a queued job — never authored directly.
2. **Contract‑driven rendering.** A block's shape, allowed attributes, style variables, and render template are declared in a JSON **contract**. The renderer prefers the contract template; legacy hard‑coded renderers are the fallback. The contract is the bridge between editor, validator, and renderer — and a console command (`blocks:verify`) proves the three stay in sync.
3. **Security by default, defence in depth.** Every user string is HTML‑escaped at render; URLs are scheme‑allow‑listed; CSS values are token‑validated against strict regexes; raw HTML is gated by a policy **and** re‑sanitized at render even though it was sanitized at write; a server‑only `_allow_raw` trust flag — strippable from the wire — guards the one path that emits unescaped markup.
4. **Bilingual everywhere.** Every content field has `_en`/`_fr` variants; the renderer resolves a locale with a documented fallback chain; the registry localizes contract labels through the translation namespace.
5. **Append‑only audit & history.** Status transitions write an immutable activity‑log entry and (optionally) a review note and a revision snapshot.
6. **Thin controllers, fat services.** Controllers validate (via FormRequests), authorize (via policies), and delegate to services/actions. No business logic in controllers.
7. **Fail closed.** Invalid block batches are rejected *before* the write transaction opens; unauthorized raw‑HTML throws; a missing system actor aborts scheduled publication loudly rather than silently.

## 0.4 The three layers: Engine · Domain · Couplings

Heisenberg is best understood as three rings. The rebuild can ship ring 1 alone, rings 1+2, or all three.

```
┌─────────────────────────────────────────────────────────────┐
│  RING 3 — HOST COUPLINGS (replaced by contracts in Heisenberg)│
│   User identity · Media resolution · Role gate · Audit sink   │
│   Icon provider · View/route host integration                 │
│  ┌───────────────────────────────────────────────────────┐  │
│  │  RING 2 — BLOG DOMAIN                                  │  │
│  │   Posts · Taxonomy · Comments · Revisions · Patterns  │  │
│  │   Publishing lifecycle · SEO meta · HTTP surface      │  │
│  │  ┌─────────────────────────────────────────────────┐  │  │
│  │  │  RING 1 — BLOCK ENGINE (the jewel)              │  │  │
│  │  │   BlockType · Contracts · Registry · Validators │  │  │
│  │  │   Payload service · Block persistence           │  │  │
│  │  │   BlockRenderer · HtmlSanitizationService       │  │  │
│  │  │   Component registry · Patterns engine          │  │  │
│  │  └─────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

- **Ring 1 (Block Engine)** has the *fewest* couplings — essentially only media (for the image block) and a role gate (for `html_raw`). It is the most reusable and should be the package's foundation.
- **Ring 2 (Blog Domain)** brings in identity (`author_id`), the role map (publishing authority), and the comment/letter system.
- **Ring 3 (Couplings)** is not code to port — it is the **set of interfaces** Heisenberg defines so rings 1–2 never name a GTC class. Part 13 specifies each.

## 0.5 Glossary

| Term | Meaning |
|---|---|
| **Block** | One unit of content: `{ id, type/name, attributes/content, supports, innerBlocks }`. Persisted one row per block. |
| **Contract** | A JSON file declaring a block type's attributes, supports, controls, style variables, and render template. |
| **Registry** | The in‑memory, hashed, localized catalogue of all valid contracts, served to the editor. |
| **JSON‑first block** | A block whose `name` is namespaced (`gtc/paragraph`) and which carries `attributes`/`supports`/`innerBlocks` — the new shape. |
| **Legacy block** | A block stored as `{ type, content }` where `content` is a flat associative array — the old shape. Both shapes coexist. |
| **Supports** | Style‑system toggles on a block (color, typography, spacing…), distinct from content `attributes`. Never localized. |
| **Translation group** | A UUID (`translation_group_id`) linking the EN and FR rows of one logical post. |
| **Surface** | `admin` vs `staff` — which subdomain/role context a builder request runs in. |
| **`_allow_raw`** | A server‑only trust flag the renderer requires before emitting an `html_raw` block's markup. |
| **Render cache** | `rendered_html_en/_fr` columns — derived HTML, regenerated by `RenderBlogPostJob`. |

> Throughout this blueprint, the GTC names (`Blog*`, `gtc/…`, `blog::…`) are quoted as‑built. Heisenberg renames are proposed in §3.10, §13, §14 — the recommended root namespace is `Heisenberg\…`, the block prefix `heisenberg/…` (configurable), and the view/lang/config namespace `heisenberg`.

---

# Part 1 — Package Layout & Bootstrapping

## 1.1 Package definition

**`[AS‑BUILT]`** The module is an nwidart/laravel‑modules package, not a standalone Composer library. Its metadata is thin:

`Modules/Blog/module.json`:
```json
{
  "name": "gtc/blog",
  "alias": "Blog",
  "description": "GTC Blog module",
  "keywords": [],
  "active": 1,
  "order": 0,
  "providers": [
    "Modules\\Blog\\Providers\\BlogServiceProvider",
    "Modules\\Blog\\Providers\\EventServiceProvider",
    "Modules\\Blog\\Providers\\RouteServiceProvider"
  ]
}
```

`Modules/Blog/composer.json` declares **no `require` block at all** — it inherits every dependency from the host app (Laravel, Spatie permission/activitylog, ezyang/htmlpurifier, mallardduck/blade-lucide-icons). PSR‑4 maps `Modules\Blog\` → `app/` (note the `app/` remap: files live under `app/` but are namespaced without it).

**`[TARGET]`** Heisenberg is a real Composer package with an explicit dependency contract. Proposed `composer.json`:

```json
{
  "name": "heisenberg/heisenberg",
  "description": "A block-based content engine and bilingual blog backend for Laravel.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.2",
    "illuminate/support": "^11.0 || ^12.0",
    "illuminate/database": "^11.0 || ^12.0",
    "illuminate/http": "^11.0 || ^12.0",
    "illuminate/bus": "^11.0 || ^12.0",
    "ezyang/htmlpurifier": "^4.17"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0",
    "phpunit/phpunit": "^11.0"
  },
  "suggest": {
    "spatie/laravel-activitylog": "Wire the AuditSink to Spatie activitylog (optional).",
    "spatie/laravel-permission": "If the host uses Spatie roles, the default RoleGate maps to it.",
    "mallardduck/blade-lucide-icons": "Default IconProvider for the icon picker and icon-bearing blocks."
  },
  "autoload": { "psr-4": { "Heisenberg\\": "src/" } },
  "extra": {
    "laravel": { "providers": ["Heisenberg\\HeisenbergServiceProvider"] }
  }
}
```

Key decoupling decisions encoded above:
- **Spatie packages move from hard dependency to `suggest`.** Roles (§10, §13.3) and audit (§8, §13.4) are accessed through Heisenberg contracts with default adapters; an app without Spatie still boots.
- **HTMLPurifier stays a hard `require`** — it is intrinsic to the security model (§5) and not replaceable by a contract.
- **Auto‑discovery** registers one provider; the Event and Route providers become internal `register()` calls (§1.3).

## 1.2 Target directory layout

```
heisenberg/
├── composer.json
├── config/heisenberg.php                     # the config that never existed in GTC (§14)
├── database/migrations/                       # ported from the HOST app db/migrations (§2.4)
├── resources/
│   ├── blocks/                                # block contracts INTERNALIZED here (§3.10)
│   │   ├── paragraph/paragraph.json
│   │   ├── heading/heading.json
│   │   └── …                                  # one dir per contract
│   ├── lang/{en,fr}/{blocks,comments,validation}.php
│   └── views/
│       ├── blocks/                            # render partials (image, cta, quote, …)
│       └── builder/                           # OPTIONAL server-rendered dashboards
├── src/
│   ├── HeisenbergServiceProvider.php
│   ├── Contracts/                             # the decoupling interfaces (§13)
│   │   ├── HeisenbergUser.php
│   │   ├── MediaResolver.php
│   │   ├── RoleGate.php
│   │   ├── AuditSink.php
│   │   └── IconProvider.php
│   ├── Enums/{PostStatus,BlockType}.php
│   ├── Models/                                # 11 models (§2.3)
│   ├── Services/                              # the engine + domain services
│   ├── Actions/PostTransitionAction.php
│   ├── Events/ · Jobs/ · Listeners/
│   ├── Http/{Controllers,Requests,Concerns}/
│   ├── Policies/
│   └── Console/Commands/
└── tests/
```

## 1.3 The Service Provider

**`[AS‑BUILT]`** `Modules\Blog\Providers\BlogServiceProvider extends Nwidart\Modules\Support\ModuleServiceProvider`. It:

- `register()` — registers the `EventServiceProvider` and `RouteServiceProvider`, then binds **eleven services as singletons** in a deliberate dependency order (§1.4), then registers two console commands.
- `boot()` — `registerCommands()`, `registerCommandSchedules()`, `registerConfig()`, `registerViews()`, `registerTranslations()`, `loadMigrations()`, `registerPolicies()`.
- Every path is built with `base_path("Modules/Blog/...")` rather than `__DIR__`/`module_path()` — a hard coupling to the nwidart layout.
- `registerConfig()` merges `base_path("Modules/Blog/config/gtc-blog.php")` **which does not exist** → a silent no‑op (§2.4 note, §14).
- `registerTranslations()`/`registerViews()` register the `blog` namespace (`$nameLower = "blog"`), so views resolve as `blog::…` and translations as `blog::…`.
- `loadMigrations()` points at `base_path("Modules/Blog/database/migrations")` **which also does not exist** — the migrations actually live in the host app's `database/migrations` (§2.4).
- `registerPolicies()` calls `Gate::policy()` four times (§10).
- `configureSchedules()` schedules `blog:publish-scheduled` `everyMinute()->withoutOverlapping()->runInBackground()`.

**`[TARGET]`** `Heisenberg\HeisenbergServiceProvider extends Illuminate\Support\ServiceProvider`. Same singleton graph, but:
- All paths use `__DIR__ . '/../resources/...'` and `__DIR__ . '/../database/migrations'`.
- `register()` merges the real `config/heisenberg.php` (§14) and binds the five decoupling contracts (§13) to their default adapters.
- `boot()` publishes config, migrations, views, lang, and the **block contracts** (so a host can override a contract), registers the `heisenberg` view/lang namespace, loads migrations, registers policies against the configured models, and registers commands + schedule.
- Event wiring (§8) moves into the provider's `boot()` via `Event::listen(...)` instead of a separate `$listen` provider.

## 1.4 The DI singleton graph (exact bind order)

**`[AS‑BUILT]`** Bind order matters because later singletons depend on earlier ones; binding explicitly (rather than relying on auto‑resolution) guarantees the singleton instances are shared. Reproduce this graph exactly:

```php
// 1. Pure, no deps
$this->app->singleton(BlogPostStateMachine::class, fn () => new BlogPostStateMachine());
$this->app->singleton(BlogBlockService::class,      fn () => new BlogBlockService());
$this->app->singleton(BlogBlockContractValidator::class, fn () => new BlogBlockContractValidator());
$this->app->singleton(SlugService::class,           fn () => new SlugService());

// 2. Depends on the validator
$this->app->singleton(BlogBlockRegistryService::class,
    fn ($app) => new BlogBlockRegistryService($app->make(BlogBlockContractValidator::class)));

// 3. Depends on the registry
$this->app->singleton(BlogBlocksPayloadService::class,
    fn ($app) => new BlogBlocksPayloadService($app->make(BlogBlockRegistryService::class)));

// 4. Depends on the state machine + block service
$this->app->singleton(BlogPostTransitionAction::class,
    fn ($app) => new BlogPostTransitionAction(
        $app->make(BlogPostStateMachine::class),
        $app->make(BlogBlockService::class),
    ));

// 5. Depends on slug
$this->app->singleton(TaxonomyService::class,
    fn ($app) => new TaxonomyService($app->make(SlugService::class)));

// 6. Depends on slug + payload + registry
$this->app->singleton(BlogPatternService::class,
    fn ($app) => new BlogPatternService(
        $app->make(SlugService::class),
        $app->make(BlogBlocksPayloadService::class),
        $app->make(BlogBlockRegistryService::class),
    ));

// 7. Depends on renderer + block service + slug
$this->app->singleton(BlogBuilderService::class,
    fn ($app) => new BlogBuilderService(
        $app->make(BlockRenderer::class),
        $app->make(BlogBlockService::class),
        $app->make(SlugService::class),
    ));
```

Services **not** explicitly bound but auto‑resolved on demand: `BlockRenderer` (constructor needs `HtmlSanitizationService`), `HtmlSanitizationService` (no deps), `BlogRevisionService` (needs `BlogBlockService`), `BlogComponentRegistry` (no deps). `BlogBlockService` lazily `app()`‑resolves `BlogBlocksPayloadService` and `HtmlSanitizationService` to support no‑DI legacy tests.

**`[TARGET]`** Same graph, plus the five contract bindings:
```php
$this->app->singleton(MediaResolver::class, fn () => new NullMediaResolver());      // pass-through
$this->app->singleton(RoleGate::class,      fn ($app) => new ConfigRoleGate(config('heisenberg.roles')));
$this->app->singleton(AuditSink::class,     fn () => new NullAuditSink());           // or SpatieAuditSink
$this->app->singleton(IconProvider::class,  fn () => new LucideIconProvider());      // optional dep
// HeisenbergUser is not bound — resolved as config('heisenberg.user_model').
```

> **As-built drift note (2026-08-05):** the listing above is the original M0 scope only. The real
> `HeisenbergServiceProvider::registerContracts()` additionally binds the four post-template
> adapter contracts (`PostViewsProvider`, `PostCommentProvider`, `RelatedPostsProvider`,
> `PostSeoMetaProvider`), and `registerMedia()` binds `VirusScanner` + `MediaLibraryService`.
> The provider source is the authority for the current full graph.
`BlockRenderer` and `BlogBuilderService` constructors gain a `MediaResolver` and (renderer) an `IconProvider`; `BlogBlockService`/`PostTransitionAction` gain a `RoleGate`; `LogPostAuditEvent` writes through `AuditSink`.

## 1.5 Registration of config, views, lang, migrations, policies, commands, schedules

| Concern | `[AS‑BUILT]` GTC | `[TARGET]` Heisenberg |
|---|---|---|
| Config | merge `Modules/Blog/config/gtc-blog.php` (missing → no‑op) | merge + publish `config/heisenberg.php` |
| Views | `blog::` from `Modules/Blog/resources/views` | `heisenberg::` from `resources/views`, publishable |
| Lang | `blog::` from `Modules/Blog/resources/lang` | `heisenberg::` from `resources/lang`, publishable |
| Migrations | `loadMigrationsFrom(Modules/Blog/database/migrations)` (missing → real ones live in host) | `loadMigrationsFrom(__DIR__/../database/migrations)` + publish |
| Block contracts | host app `resources/views/components/rm-ui/dashboard/blog-builder/blocks` | `resources/blocks/` inside the package, publishable for host overrides |
| Policies | `Gate::policy` ×4 against `Modules\Blog\Models\*` | `Gate::policy` ×4 against `config('heisenberg.models.*')` |
| Commands | `PublishScheduledBlogPosts`, `VerifyBlogBlocksCommand` | `PublishScheduledPosts`, `VerifyBlocksCommand` |
| Schedule | `blog:publish-scheduled` every minute, no‑overlap, background | identical, command renamed |

---

# Part 2 — Data Model

## 2.1 Entity overview

Eleven Eloquent models over (at final migration state) **fourteen live tables** plus two dropped tables that must **not** be ported. The post is the aggregate root; blocks, revisions, comments, TOC entries, likes, review notes, and SEO meta hang off it; categories/tags are independent taxonomies; patterns are author‑owned and post‑independent.

```
                         ┌──────────────┐
              category_id │ BlogCategory │ (self-parent tree)
            ┌────────────►│  (no SD)     │
            │             └──────────────┘
┌───────────┴───┐  N:M (blog_post_tag)   ┌──────────┐
│   BlogPost    │◄──────────────────────►│ BlogTag  │ (no SD)
│  (SoftDelete) │                         └──────────┘
│  author_id ──────────────► [HOST User]
└───┬───┬───┬───┬───┬───┬────┘
    │   │   │   │   │   └── seoMeta (MorphOne → SeoMeta, polymorphic 'able')
    │   │   │   │   └────── tocEntries (HasMany BlogPostTocEntry)
    │   │   │   └────────── likers (N:M → [HOST User] via blog_post_likes / BlogPostLike pivot)
    │   │   └────────────── comments (HasMany BlogComment; guest "letters" + editor replies)
    │   └────────────────── revisions (HasMany BlogRevision; content_blocks snapshot)
    └────────────────────── blocks (HasMany BlogBlock, ordered) ── reviewNotes, relatedPosts(self N:M)

BlogPattern (SoftDelete, author_id → [HOST User]) — standalone, holds a blocks array.
```

`SD` = SoftDeletes. The four content tables (`blog_posts`, `blog_blocks`, `blog_comments`, `blog_post_revisions`) participate in a **cascade soft‑delete/restore** keyed by a shared `deleted_batch_id` UUID (§2.3, `BlogPost::delete()`).

**`[TARGET]`** Rename `Blog*` → drop the prefix (`Post`, `Block`, `Category`, `Tag`, `Revision`, `Comment`, `Pattern`, `ReviewNote`, `PostLike`, `PostTocEntry`, `SeoMeta`) under `Heisenberg\Models`. Table names become configurable with a default `heisenberg_` prefix (e.g. `heisenberg_posts`); the GTC names (`blog_posts`, …) are documented per‑table below so a migrating host can keep them.

## 2.2 Enums

### `BlogPostStatus : string` — `[AS‑BUILT]` `Modules\Blog\Enums\BlogPostStatus`

| Case | Value |
|---|---|
| `DRAFT` | `draft` |
| `PENDING_REVIEW` | `pending_review` |
| `PUBLISHED` | `published` |
| `SCHEDULED` | `scheduled` |
| `ARCHIVED` | `archived` |

Methods:
- `static values(): array` — `array_column(self::cases(), 'value')`.
- `isTerminal(): bool` — true for `PUBLISHED`, `ARCHIVED`.
- `isPubliclyVisible(): bool` — true **only** for `PUBLISHED` (note: `SCHEDULED` is explicitly *not* public).
- `label(): string` — raw English (`Draft`, `Pending Review`, …); **not** an `__()` key.

No transitions map and no `color()` live on the enum — the transition graph lives in the state‑machine service (§7.2). **Quirk:** `BlogPost` does **not** cast `status` to this enum — `status` is a raw string column and the transition action mutates/compares it as a string literal (§7.3, §16).

### `BlockType : string` — `[AS‑BUILT]` `Modules\Blog\Enums\BlockType` (20 cases)

`paragraph, heading, image, quote, list, cta, gallery, video, faq, separator, html_raw, testimonial, stat, accordion, button, columns, section_head, takeaway, data_row, component`.

Methods: `static values(): array`, `static isValid(string): bool`, `label(): string`, `icon(): string` (a Lucide slug per type). `BlogBlock.type` is a raw `varchar(50)`, not cast to this enum.

> **Critical gap (carry into §3, §12):** only **9** of these 20 have JSON contracts on disk (`paragraph, heading, image, quote, list, cta, separator, button, section_head`). The other 11 — including `html_raw, gallery, video, columns, component, faq, testimonial, stat, accordion, takeaway, data_row` — exist **only** in the legacy `{type,content}` validator path and the renderer's hard‑coded methods. The `blocks:verify` command (§12) exists precisely to detect drift between enum, contracts, and renderer methods.

**`[TARGET]`** Rename to `Heisenberg\Enums\PostStatus` and `BlockType`. Make `Post::status` cast to `PostStatus` to end the string/enum drift (§16, item 8). The block prefix in `BlockType` slugs stays internal; the contract `name` prefix (`gtc/` → `heisenberg/`) is configurable (§3.10).

## 2.3 The eleven models

Conventions: all live under `Modules\Blog\Models` (`[AS‑BUILT]`). `SD` = `SoftDeletes`. **No model uses `$guarded`** — every one declares `$fillable` (satisfying GTC rule R01‑03). `[HOST User]` marks the only host‑app Eloquent coupling.

### 2.3.1 `BlogPost`
- Parent `Model`; traits `HasFactory`, `SoftDeletes`. Table `blog_posts`. Factory `…Database\Factories\BlogPostFactory`.
- **`$fillable`** (verbatim): `translation_group_id, author_id, locale, title_en, title_fr, slug, excerpt_en, excerpt_fr, eyebrow_pills_en, eyebrow_pills_fr, title_lines_en, title_lines_fr, rendered_html_en, rendered_html_fr, status, published_at, scheduled_at, featured_image, hero_image_alt_en, hero_image_alt_fr, hero_image_caption_en, hero_image_caption_fr, hero_image_location, hero_image_credit, category_id, reading_time, format, series_label_en, series_label_fr, is_featured, is_pinned, byline_name, byline_role_en, byline_role_fr, byline_city, view_count, like_count, comment_count, content_version`.
- **`$casts`**: `eyebrow_pills_en|fr → array`, `title_lines_en|fr → array`, `published_at|scheduled_at → datetime`, `is_featured|is_pinned → boolean`, `view_count|like_count|comment_count|content_version → integer`.
- **Boot hooks:** `creating` → assign `translation_group_id` = UUID if empty; auto‑slug from `title_en ?: title_fr` if empty. `updating` → re‑slugify if `slug` dirty and original non‑null.
- **Relationships:** `author()` BelongsTo `[HOST User]` (`author_id`); `category()` BelongsTo `BlogCategory`; `tags()` BelongsToMany `BlogTag` (pivot `blog_post_tag`); `reviewNotes()` HasMany `BlogReviewNote` `->latest()`; `revisions()` HasMany `BlogRevision` (desc); `comments()` HasMany `BlogComment` `whereNull parent_id`; `approvedComments()` HasMany (`status='approved'` + top‑level); `blocks()` HasMany `BlogBlock` (`post_id`, `->ordered()`); `seoMeta()` MorphOne `SeoMeta` (morph `able`); `tocEntries()` HasMany `BlogPostTocEntry` (ordered); `relatedPosts()` BelongsToMany self (pivot `blog_post_related`, `withPivot('order')`); `likers()` BelongsToMany `[HOST User]` (pivot `blog_post_likes`, `withTimestamps`); `sibling()` BelongsTo self via `translation_group_id`, opposite locale, `withDefault()`; `siblings()` HasMany self via `translation_group_id`, `id != current`.
- **Scopes:** `published` (`status='published'`), `inGroup($g)`, `forLocale($l)`.
- **Accessors:** `getTitleAttribute` (locale‑aware FR→EN fallback), `getExcerptAttribute` (locale‑aware), `getRenderedHtmlAttribute` (FR or EN, no fallback).
- **Helpers:** `isPublished()`, `isDraft()`, `isPendingReview()`, `isArchived()` (string compare).
- **Overridden lifecycle (the cascade soft‑delete mechanism):**
  - `delete()` — wraps in `DB::transaction`; stamps a shared `deleted_batch_id` UUID onto active `blocks`, `revisions`, comments, soft‑deletes them, then `parent::delete()`.
  - `restore()` — restores parent, then restores only children sharing that `deleted_batch_id` (via `withTrashed()`).
  - `bumpContentVersion()` — atomic `increment('content_version')` (optimistic‑lock counter for autosave; mismatch → HTTP 409, §9).
- **`[TARGET]` flags:** remove `content_version` from `$fillable` (it must be server‑only — bump via `bumpContentVersion()` only); cast `status` to `PostStatus`; replace `author()`/`likers()` `[HOST User]` with the configured user model.

### 2.3.2 `BlogBlock`
- `Model` + `HasFactory` + `SD`. Table **`blog_blocks`** (explicit). `$fillable`: `post_id, type, content, order`. `$casts`: `content → array`, `order → integer`, `deleted_at → datetime`. Relationship `post()` BelongsTo `BlogPost` (`post_id`). Scope `ordered` (`orderBy('order')`). **No host coupling.** This is the per‑block row; `content` is a **JSON** column and the single source of truth for structured content.

### 2.3.3 `BlogCategory`
- `Model` + `HasFactory` (**no SD**). Table `blog_categories`. `$fillable`: `parent_id, name_en, name_fr, slug, description_en, description_fr, order`. `$casts`: `order → integer`. Boot `creating` → auto‑slug from `name_en ?: name_fr`. Relationships: `parent()`/`children()` (self, `parent_id`), `posts()` HasMany, `publishedPosts()` (`posts()->published()`). Accessors `getNameAttribute` → `name_en`, `getDescriptionAttribute` → `description_en`. Helpers `getAncestorIds()`, `getDescendantIds()` (cycle‑safety for parent assignment, §6). No host coupling.

**`[TARGET]` DEVIATION (2026‑08‑03):** the above (`posts()` HasMany, `blog_posts.category_id`) documents the legacy GTC host's ORIGINAL single‑category shape, ported as‑built. Heisenberg's editor now offers a Gutenberg‑style multi‑select Categories checklist, which a single FK column cannot back — `Category::posts()`/`Post::categories()` are BelongsToMany via a new `heisenberg_category_post` pivot (mirroring §2.3.1's `tags()`/`blog_post_tag` shape exactly), and `category_id` no longer exists on the posts table. See migrations `2026_08_03_000001`/`_000002` and the models' own docblocks. A GTC host migrating in place and relying on the original single‑category `category_id` column should be aware this package no longer carries it.

### 2.3.4 `BlogTag`
- `Model` + `HasFactory` (**no SD**). Table `blog_tags`. `$fillable`: `name_en, name_fr, slug`. Boot `creating` → auto‑slug. `posts()` BelongsToMany (pivot `blog_post_tag`), `publishedPosts()`. Accessor `getNameAttribute` → `name_en`. No host coupling.

### 2.3.5 `BlogRevision`
- `Model` + `HasFactory` + `SD`. Table **`blog_post_revisions`** (explicit). `$fillable`: `blog_post_id, content_blocks, rendered_html_en, rendered_html_fr, title_en, title_fr, excerpt_en, excerpt_fr, author_id, revision_type`. `$casts`: `content_blocks → array`, `deleted_at → datetime`. Const `TYPES = ['manual','auto_save','restore']` (column default `'manual'`). Relationships `post()` BelongsTo `BlogPost`; `author()` BelongsTo `[HOST User]`. The `content_blocks` JSON is an intentional point‑in‑time snapshot (kept even though live blocks moved to `blog_blocks`).

### 2.3.6 `BlogComment`
- `Model` + `HasFactory` + `SD`. Table `blog_comments`. `$fillable`: `blog_post_id, user_id, parent_id, content, status, meta, guest_name, guest_email, guest_city, guest_country, letter_number, letter_kind, is_editor_pick, is_featured, postmark_origin, postmark_date, postmark_mark, editor_user_id, editor_reply_body, editor_reply_signature, editor_replied_at`. `$casts`: `meta → array`, `is_editor_pick|is_featured → boolean`, `editor_replied_at → datetime`, `deleted_at → datetime`. Const `STATUSES = ['pending','approved','spam','trash']`. Relationships: `post()`; `user()` BelongsTo `[HOST User]` (nullable — guest letters); `editor()` BelongsTo `[HOST User]` (`editor_user_id`); `parent()`/`replies()` (self); `approvedReplies()`. Accessors `getDisplayNameAttribute` (`user?->name ?? guest_name ?? 'Anonymous'`), `getDisplayLocationAttribute` (`city · country`). Helpers `hasEditorReply()`, `isApproved/Pending/Spam/Trash()`. The "letter" fields (`letter_number`, `postmark_*`) power a print‑correspondence presentation; the `editor_*` fields hold an official reply.

### 2.3.7 `BlogPattern`
- `Model` + `HasFactory` + `SD`. Table **`blog_patterns`** (explicit). `$fillable`: `author_id, name, description, slug, blocks, is_shared` (note `usage_count` **not** fillable — server‑bumped). `$casts`: `blocks → array`, `is_shared → boolean`, `usage_count → integer`. Relationship `author()` BelongsTo `[HOST User]`. The only model using a top‑of‑file `use App\Models\User;` import (the rest reference it inline as `\App\Models\User::class`).

### 2.3.8 `BlogReviewNote`
- `Model`, **no traits** (no factory, no SD). Table **`blog_review_notes`** (explicit). `$fillable`: `blog_post_id, user_id, action, note`. Relationships `post()`, `user()` BelongsTo `[HOST User]`. Written by the transition action when a reviewer approves/requests‑changes (§7.3).

### 2.3.9 `BlogPostLike`
- Extends `Illuminate\Database\Eloquent\Relations\Pivot` (not `Model`). Table **`blog_post_likes`**. `$incrementing = false`, `$timestamps = false`. `$fillable`: `blog_post_id, user_id, created_at`. `$casts`: `created_at → datetime`. Pure pivot for `BlogPost::likers()`.

### 2.3.10 `BlogPostTocEntry`
- `Model`, **no traits**. Table `blog_post_toc_entries`. `$fillable`: `blog_post_id, target, label_en, label_fr, num, order`. `$casts`: `order → integer`. `post()` BelongsTo; scope `ordered`. Anchor‑link table‑of‑contents rows for the article page.

### 2.3.11 `SeoMeta`
- `Model` + `HasFactory`. Table **`seo_meta`** (explicit), **polymorphic**. `$fillable`: `able_type, able_id, meta_title_en, meta_title_fr, meta_description_en, meta_description_fr, og_image, canonical_url, robots, schema_type, schema_data`. `$casts`: `schema_data → array`. Relationship `able()` MorphTo. Helpers `getMetaTitle($locale)`, `getMetaDescription($locale)`, `getJsonLd($locale, $url=null)` (builds schema.org JSON‑LD). No host coupling. **`[TARGET]`** the polymorphic `seo_meta` is generic — keep it, but namespace `able_type` values to the configured `Post` model.

## 2.4 Tables & migrations (full column spec)

> **`[AS‑BUILT]` location quirk — critical for the rebuild.** Despite `BlogServiceProvider::loadMigrations()` pointing at `Modules/Blog/database/migrations`, **that directory does not exist**. All blog migrations live in the **host app's** `database/migrations/`. They must be located there and re‑homed into the Heisenberg package. The schema below is the *final* state after all incremental migrations are applied.

Legend: `PK` primary key, `AI` auto‑increment, `FK→` foreign key, `SD` soft‑deletes column present, `[HOST]` FK references host `users` table.

### `blog_categories`  (no SD)
| column | type | null | default | notes |
|---|---|---|---|---|
| id | bigint unsigned PK AI | no | | |
| parent_id | bigint unsigned | yes | | FK→blog_categories.id `nullOnDelete` |
| name_en | varchar(255) | no | | |
| name_fr | varchar(255) | yes | | (made nullable) |
| slug | varchar(255) | no | | **UNIQUE** |
| description_en | text | yes | | |
| description_fr | text | yes | | |
| order | int unsigned | no | 0 | |
| created_at/updated_at | timestamp | yes | | |
Indexes: `parent_id`, `slug`, `order`; unique `slug`.

### `blog_tags`  (no SD)
`id` PK AI · `name_en` varchar(255) NOT NULL · `name_fr` varchar(255) NULL · `slug` varchar(255) NOT NULL **UNIQUE** · timestamps. Index `slug`.

### `blog_posts`  (SD)
| column | type | null | default | notes |
|---|---|---|---|---|
| id | bigint unsigned PK AI | no | | |
| translation_group_id | char(36) uuid | yes | | |
| author_id | bigint unsigned | no | | FK→users `cascadeOnDelete` `[HOST]` |
| locale | enum('en','fr') | no | 'en' | |
| title_en | varchar(255) | no | | |
| title_fr | varchar(255) | yes | | |
| slug | varchar(255) | no | | unique is composite `(slug, deleted_at)` |
| content_version | bigint unsigned | no | 0 | optimistic lock |
| excerpt_en / excerpt_fr | text | yes | | |
| eyebrow_pills_en / _fr | json | yes | | array cast |
| title_lines_en / _fr | json | yes | | array of `{text, em?:bool}` |
| rendered_html_en / _fr | longtext | yes | | **derived render cache** |
| status | enum('draft','pending_review','published','scheduled','archived') | no | 'draft' | |
| published_at | timestamp | yes | | |
| scheduled_at | timestamp | yes | | |
| featured_image | varchar(255) | yes | | |
| hero_image_alt_en / _fr | varchar(255) | yes | | |
| hero_image_caption_en / _fr | text | yes | | |
| hero_image_location | varchar(255) | yes | | |
| hero_image_credit | varchar(255) | yes | | |
| byline_name | varchar(255) | yes | | |
| byline_role_en / _fr | varchar(255) | yes | | |
| byline_city | varchar(255) | yes | | |
| category_id | bigint unsigned | yes | | FK→blog_categories `nullOnDelete` |
| reading_time | int unsigned | yes | | |
| format | varchar(32) | yes | | |
| series_label_en / _fr | varchar(255) | yes | | |
| is_featured | boolean | no | false | |
| is_pinned | boolean | no | false | |
| view_count | int unsigned | no | 0 | |
| like_count | int unsigned | no | 0 | |
| comment_count | int unsigned | no | 0 | |
| created_at/updated_at | timestamp | yes | | |
| deleted_at | timestamp | yes | | SD |
| deleted_batch_id | char(36) uuid | yes | | indexed; cascade SD key |
**Dropped (do not port):** `content_blocks` (json — superseded by the `blog_blocks` table), `bookmark_count` (int).
Indexes: `translation_group_id`, `author_id`, `locale`, `slug`, `status`, `published_at`, `scheduled_at`, composite `(status, scheduled_at)`, composite `(translation_group_id, locale)`, `is_featured`, `is_pinned`, `format`, `deleted_batch_id`. Unique: `(slug, deleted_at)`. FKs: `author_id`→users cascade, `category_id`→blog_categories null.

### `blog_post_tag`  (pivot, no timestamps)
`blog_post_id` FK→blog_posts cascade · `blog_tag_id` FK→blog_tags cascade · PK `(blog_post_id, blog_tag_id)`.

### `blog_blocks`  (SD)
| id | bigint unsigned PK AI |
| post_id | bigint unsigned NOT NULL — FK→blog_posts cascade |
| type | varchar(50) NOT NULL — `BlockType` value (raw string) |
| content | **json** NOT NULL — array cast (the block payload) |
| order | int unsigned NOT NULL default 0 |
| timestamps · deleted_at (SD) · deleted_batch_id char(36) uuid indexed |
Index: composite `(post_id, order)` + `deleted_batch_id`.

### `blog_post_revisions`  (SD)
`id` PK · `blog_post_id` FK→blog_posts cascade · `content_blocks` **json** (snapshot) · `rendered_html_en/_fr` longtext · `title_en/_fr` varchar · `excerpt_en/_fr` text · `author_id` FK→users cascade `[HOST]` · `revision_type` varchar(255) default 'manual' · timestamps · `deleted_at` (SD) · `deleted_batch_id` char(36) indexed. Indexes `blog_post_id`, `author_id`, `deleted_batch_id`.

### `blog_comments`  (SD)
| column | type | null | default | notes |
|---|---|---|---|---|
| id | bigint unsigned PK AI | no | | |
| blog_post_id | bigint unsigned | no | | FK→blog_posts cascade |
| user_id | bigint unsigned | yes | | FK→users `nullOnDelete` `[HOST]` (nullable for guests) |
| guest_name | varchar(255) | yes | | |
| guest_email | varchar(255) | yes | | |
| guest_city | varchar(255) | yes | | |
| guest_country | varchar(64) | yes | | |
| parent_id | bigint unsigned | yes | | FK→blog_comments cascade |
| content | text | no | | |
| status | enum('pending','approved','spam','trash') | no | 'pending' | |
| is_editor_pick | boolean | no | false | |
| is_featured | boolean | no | false | |
| letter_number | varchar(8) | yes | | |
| letter_kind | varchar(32) | yes | | |
| postmark_origin | varchar(255) | yes | | |
| postmark_date | varchar(32) | yes | | |
| postmark_mark | varchar(64) | yes | | |
| editor_user_id | bigint unsigned | yes | | FK→users `nullOnDelete` `[HOST]` |
| editor_reply_body | text | yes | | |
| editor_reply_signature | varchar(255) | yes | | |
| editor_replied_at | timestamp | yes | | |
| meta | json | yes | | array cast |
| timestamps · deleted_at (SD) · deleted_batch_id char(36) indexed |
**Dropped (do not port):** `rxn_inspired_count`, `rxn_saved_count`, `rxn_booking_count`, `rxn_curious_count`. Indexes: `blog_post_id`, `user_id`, `parent_id`, `status`, `(blog_post_id, is_featured)`, `(blog_post_id, is_editor_pick)`, `letter_kind`, `deleted_batch_id`.

### `seo_meta`  (polymorphic, no SD)
`id` PK · `able_type` varchar(255) + `able_id` bigint unsigned (from `morphs('able')`, composite index) · `meta_title_en/_fr` varchar(255) · `meta_description_en/_fr` varchar(255) · `og_image` varchar(255) · `canonical_url` varchar(255) · `robots` varchar(255) default `'index, follow'` · `schema_type` varchar(255) · `schema_data` json · timestamps.

### `blog_post_toc_entries`  (no SD)
`id` PK · `blog_post_id` FK cascade · `target` varchar(64) (anchor) · `label_en` varchar(255) NOT NULL · `label_fr` varchar(255) · `num` varchar(8) · `order` int unsigned default 0 · timestamps. Index `(blog_post_id, order)`.

### `blog_post_related`  (self‑pivot, no timestamps)
`blog_post_id` FK cascade · `related_post_id` FK cascade · `order` tinyint unsigned default 0 · PK `(blog_post_id, related_post_id)` · index `(blog_post_id, order)`.

### `blog_post_likes`  (pivot)
`blog_post_id` FK cascade · `user_id` FK→users cascade `[HOST]` · `created_at` timestamp `useCurrent()` · PK `(blog_post_id, user_id)` · index `user_id`. No `updated_at`.

### `blog_review_notes`  (no SD)
`id` PK · `blog_post_id` FK cascade · `user_id` FK→users cascade `[HOST]` · `action` varchar(60) · `note` text · timestamps.

### `blog_patterns`  (SD)
`id` PK · `author_id` FK→users cascade `[HOST]` · `name` varchar(160) · `description` text · `slug` varchar(180) · `blocks` **longtext** (array cast — note: longtext, *not* json, unlike `blog_blocks.content`) · `is_shared` boolean default false · `usage_count` int unsigned default 0 · timestamps · `deleted_at` (SD). Indexes: `(author_id, is_shared)`; unique `(author_id, slug, deleted_at)`.

### Dropped tables — **do not recreate**
- `blog_post_bookmarks` (had `blog_post_id` + `user_id` PK, `created_at`).
- `blog_letter_reactions` (had `blog_comment_id` FK, nullable `user_id`, `session_hash`, `kind` enum, uniqueness on user/session+kind).

Neither has a live model; both were removed late in GTC's life. They are noise — skip them.

## 2.5 Content storage types

| Store | Column | Type | Role |
|---|---|---|---|
| Live block payload | `blog_blocks.content` | **json** | single source of truth, one row per block |
| Render cache | `blog_posts.rendered_html_en/_fr` | longtext | derived HTML, regenerated by job |
| Revision snapshot | `blog_post_revisions.content_blocks` | **json** | point‑in‑time blocks array |
| Pattern blocks | `blog_patterns.blocks` | **longtext** | saved block group (note type inconsistency vs `blog_blocks`) |
| Magazine arrays | `blog_posts.eyebrow_pills_*`, `title_lines_*` | json | layout metadata |
| Comment/SEO meta | `blog_comments.meta`, `seo_meta.schema_data` | json | |

**`[TARGET]`** Normalize `heisenberg_patterns.blocks` to `json` too (the `longtext` was an accident of history); the array cast hides the difference but a `json` column gives DB‑level validation.

## 2.6 `[TARGET]` User decoupling at the data layer

The **only** host Eloquent coupling in all eleven models is `App\Models\User`, referenced by six models (`BlogPost::author/likers`, `BlogRevision::author`, `BlogComment::user/editor`, `BlogReviewNote::user`, `BlogPattern::author`) and by seven FK constraints to the `users` table.

Heisenberg severs this with **two knobs** (full spec in §13.1):

1. **`config('heisenberg.user_model')`** — the relationships resolve the related class from config instead of naming `App\Models\User`:
   ```php
   public function author(): BelongsTo
   {
       return $this->belongsTo(config('heisenberg.user_model'), 'author_id');
   }
   ```
2. **`config('heisenberg.users_table')`** (default `'users'`) — migrations reference this for the FK target, so a host with a non‑standard users table still wires up:
   ```php
   $table->foreignId('author_id')->constrained(config('heisenberg.users_table'))->cascadeOnDelete();
   ```

The host's user model should implement the marker interface `Heisenberg\Contracts\HeisenbergUser` (§13.1) so type‑hints across services reference the contract, not a concrete class. Role checks on that user (`hasRole`, etc.) move behind the `RoleGate` contract (§10, §13.3) — the model layer itself never calls a role method.

---

# Part 3 — The Block System (the crown jewel)

This is what makes Heisenberg worth extracting. A block is a small JSON document; a **contract** declares what shape that document may take; a **registry** catalogues the contracts and serves them to the editor; a **payload service** validates a block instance against the live contract; a **block service** persists blocks with a hardened trust gate for raw HTML; and the **renderer** (Part 4) compiles blocks to HTML, preferring the contract's render template.

## 3.1 Two content paths: JSON‑contract vs legacy `{type,content}`

The system supports **two on‑the‑wire block shapes simultaneously**, and every service is shape‑aware:

**A — JSON‑first block (the new shape):**
```json
{
  "id": "b-1a2b",
  "name": "gtc/paragraph",
  "schemaVersion": "1.0.0",
  "attributes": { "content": "Bonjour le monde", "content_en": "Hello world" },
  "supports": { "color": { "text": "var(--accent-1)" }, "spacing": { "margin": "1rem" } },
  "innerBlocks": []
}
```
- `name` is namespaced (`gtc/<slug>`); validated against the **live contract** of the same name; `schemaVersion` must equal the contract's `version`.
- Rendered by the renderer's **contract‑template path** (§4.3).

**B — Legacy block (the old shape):**
```json
{ "type": "paragraph", "content": { "text": "Hello", "text_fr": "Bonjour", "styles": {} } }
```
- `type` is a bare `BlockType` value; `content` is a flat associative array; validated by per‑type closures (§3.6); rendered by the renderer's **hard‑coded method path** (§4.2).

**Persistence reconciles both:** whatever shape arrives, `BlogBlockService` stores a row in `blog_blocks` as `{ type, content, order }`, where for a JSON‑first block the entire block object is nested under `content` (the `type` is the slug with `gtc/` stripped and `-`→`_`). The renderer then detects which shape a stored `content` carries and dispatches accordingly (§4.2). The `BlogBuilderService` round‑trips between the two (`storedBlockFromEditorBlock` / `editorBlockFromStoredBlock`, §3.9).

**`[TARGET]`** Keep both paths — they are load‑bearing (only 9 of 20 types have contracts). The legacy path is the *only* renderer for `html_raw`, `gallery`, `video`, `columns`, `component`, `faq`, `testimonial`, `stat`, `accordion`, `takeaway`, `data_row`. A v2 could migrate the remaining 11 to contracts, but that is post‑extraction work; the parity command (§12) tracks the gap.

## 3.2 The block‑contract JSON schema (full anatomy)

**`[AS‑BUILT]`** Contracts are **filesystem‑discovered JSON files**, one per block. A contract has **17 required top‑level keys** (enforced by `BlogBlockContractValidator`, §3.5):

`$schema, apiVersion, name, title, category, icon, description, keywords, version, attributes, supports, controls, style, render, innerBlocks, serialization, security`.

Nested structure, key by key:

### `attributes` — the content fields
`attributes.<name>` = an object:
| key | meaning |
|---|---|
| `type` | one of `string · boolean · integer · number · rich-text · array · object · media · url · token` |
| `default` | required unless `nullable: true` |
| `source` | `none · html · text · attribute · query` — where the value is read from in the editor DOM |
| `selector` | CSS selector; required when `source` ∈ {html,text,attribute,query} |
| `attribute` | the HTML attribute to read (e.g. `"src"`,`"href"`,`"alt"`); required when `source = attribute` |
| `sanitize` | one of `text · rich-text-inline · rich-text-block · url · color-token · color-token-or-transparent · size-token · integer · boolean · html-safe`; **required** for rich‑text/url/token types |
| `enum` | allowed values (e.g. heading `level` ∈ `[1..6]`, image `target` ∈ `["_self","_blank"]`) |
| `items` | required for `type:array` |
| `properties` | required for `type:object`/`media` |

### `supports` — the style‑system toggles (never localized)
Allowed groups only: `align` (array of `left|center|right|wide|full`), `color` (`text`/`background`/`custom` bools), `typography` (`fontFamily`/`fontSize`/`fontWeight`/`lineHeight`/`letterSpacing`/`textTransform` bools), `spacing` (`margin`/`padding`/`gap` bools), `border` (`color`/`style`/`width`/`radius`), `dimensions` (`height`/`width`/`minHeight`/`maxHeight`), `layout` (`contentSize`/`wideSize`/`flexWrap`).

### `controls` — editor UI field descriptors
`controls[]` = `{ id, type, label, attribute, section?, options?, min?, max?, step?, fields? }`. `type` ∈ `text · textarea · rich-text · select · toggle · range · number · media · link · button-group · repeater`. `attribute` **must** reference a declared attribute. `select`/`button-group` require `options:[{label,value}]`; `range` requires `min`+`max`; `repeater` requires `fields`. Seen `section` values: `"settings"`, `"hover"`.

### `style` — CSS bridge
`style.css` (safe relative `.css` path), `style.className` (non‑empty), and `style.variables` — a map of CSS custom properties to a `{ source, default, sanitize }` triple:
- `source` must match `^(attributes|supports)\.` (e.g. `"supports.color.text"`).
- `default` must **not** start with `#` (no raw hex — design tokens only, e.g. `var(--accent-1)`).
- `sanitize` is one of the sanitizer tokens; the renderer applies it when materializing the variable (§4.5).

### `render` — the renderer bridge
- `render.template` — a recursive node tree (anatomy below).
- `render.publicPartial` — a blade view name (`blogbuilder.blocks.<x>`).
- `render.script` — `null` or a safe relative `.js` path.

`render.template` node types (validated by `validateTemplateNode`, §3.5):
| node | shape |
|---|---|
| `element` (default when `tag` present) | `{ tag, class?, attributes?:{name:value}, children?[] }` — `tag`/`class`/attribute **values** interpolate `{{name}}`, `{{id}}`, `{{attributes.X}}` mustache tokens (e.g. `"tag":"h{{attributes.level}}"`, `"src":"{{attributes.url}}"`) |
| `text` | `{ type:"text", content:"…" }` — literal or interpolated string |
| `rich-text` | `{ type:"rich-text", attribute:"content", class? }` — the named attribute's sanitized HTML fills the node |
| `media` | `{ type:"media", attribute:"…", class? }` |
| `innerBlocks` | the container slot — **validator‑supported but unused by all 9 shipped contracts** (no contract enables inner blocks). The editor slot marker is `data-blog-builder-inner-blocks`. |

### `innerBlocks`, `serialization`, `security`
- `innerBlocks` = `{ enabled: bool }`; when `true` also requires `allowedBlocks[]`, `orientation` (vertical|horizontal), `templateLock:bool`, optional `parentBlocks[]`, `min`/`max`.
- `serialization` = `{ mode: "json" (must), saveAttributes, saveSupports, saveInnerBlocks, migrations:[] }`.
- `security` = `{ richText: string (e.g. "inline-basic"), allowRawHtml: bool, allowCustomCss: false (hard rule) }`. Image/cta/button additionally carry free‑form `allowedUrlProtocols`; image carries `allowedMediaSources:["public_media_library"]` (the validator ignores extra security keys).

## 3.3 A verbatim example contract (`gtc/paragraph`)

This is a real shipped contract, read from disk — the canonical reference for the schema above:

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1,
  "name": "gtc/paragraph",
  "title": "blog::blocks.paragraph.title",
  "category": "text",
  "icon": "pilcrow",
  "description": "blog::blocks.paragraph.description",
  "keywords": ["paragraph", "text", "body"],
  "version": "1.0.0",
  "attributes": {
    "content": {
      "type": "rich-text",
      "default": "",
      "source": "html",
      "selector": ".gtc-block-paragraph__text",
      "sanitize": "rich-text-block"
    }
  },
  "supports": {
    "align": ["left", "center", "right"],
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontFamily": true, "fontSize": true, "fontWeight": true, "lineHeight": true, "letterSpacing": true, "textTransform": true },
    "spacing": { "margin": true, "padding": true }
  },
  "controls": [
    { "id": "content", "type": "rich-text", "label": "Text", "attribute": "content", "section": "settings" }
  ],
  "style": {
    "css": "./paragraph.css",
    "className": "gtc-block-paragraph",
    "variables": {
      "--gtc-paragraph-color":       { "source": "supports.color.text",       "default": "var(--accent-1)",  "sanitize": "color-token" },
      "--gtc-paragraph-background":  { "source": "supports.color.background",  "default": "transparent",      "sanitize": "color-token-or-transparent" },
      "--gtc-paragraph-font-size":   { "source": "supports.typography.fontSize","default": "var(--text-md)",  "sanitize": "size-token" },
      "--gtc-paragraph-align":       { "source": "supports.align",             "default": "left",             "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "p",
      "class": "gtc-block gtc-block-paragraph",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "rich-text", "attribute": "content", "class": "gtc-block-paragraph__text" }
      ]
    },
    "publicPartial": "blogbuilder.blocks.paragraph",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```

The **9 shipped contracts** are: `gtc/paragraph`, `gtc/heading`, `gtc/image`, `gtc/button`, `gtc/list`, `gtc/cta`, `gtc/separator`, `gtc/quote`, `gtc/section-head`. Each `$schema` points at `docs/control/staff-admin-blog-builder-block-schema.md` (the human spec — port that doc too when rebuilding).

## 3.4 `BlogBlockRegistryService` — discovery, hashing, localization

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogBlockRegistryService`. Constructor: `(BlogBlockContractValidator $validator, ?string $blockRootPath = null)`. Const `SCHEMA_VERSION = 1`.

> **The coupling that matters most.** The default block root is `base_path('resources/views/components/rm-ui/dashboard/blog-builder/blocks')` — i.e. the **host app's** `resources/views`, *outside* the Blog module entirely. Contracts are not in the package today. (See §3.10 — Heisenberg internalizes them.)

The service recursively scans (`File::allFiles`) that root for `*.json`, path‑traversal‑guards each (`realpath` prefix check via `isPathInsideRoot`), `json_decode`s with `JSON_THROW_ON_ERROR`, validates via the contract validator, sorts by `name`, and returns the **registry envelope**:

```php
[
  'schemaVersion'   => 1,
  'registryHash'    => 'sha256:…',          // canonical hash of untranslated contracts
  'blocks'          => [ …translated contracts… ],
  'categories'      => [ …sorted distinct category values… ],
  'icons'           => [ …lucide slugs referenced by contracts… ],
  'iconUrlTemplate' => url('/api/blog/icons/__ICON__'),
  'generatedAt'     => '…ISO-8601…',
  'errors'          => [ …per-file validation failures… ],
]
```

Key behaviours:
- **Hashing** (`computeHash`): `'sha256:' . hash('sha256', json_encode($blocksWithoutPathKeys, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))`. Internal `_absolutePath`/`_relativePath` keys are stripped before hashing **and** before the contracts go into `blocks`. The hash is computed on the **untranslated** contracts so it's locale‑stable; the public `blocks` are localized.
- **Localization** (`localizeContract`): translates `title`/`description`/control `label`/`placeholder`/option `label` **only** when the value `str_starts_with('blog::blocks.')` → passes through `__()`. Everything else is verbatim.
- **Dedup:** duplicate `name` across files → an `errors[]` entry (not a fatal).
- Public API: `discover()` (raw scan, includes path keys), `registry()` (full envelope), `lucideIcons()` (svg basenames matching `^[a-z0-9-]+$` from the vendor svg dir), `lucideIconSvg(name)` (realpath‑confined file read), `getBlock(name)`, `isBlockKnown(name)`, `computeHash(?blocks)`, `getCategories(?blocks)`, `validatePath(path)`.

**`[TARGET]`** Block root defaults to `__DIR__ . '/../resources/blocks'` (internalized, §3.10), overridable via `config('heisenberg.block_root')` so a host can ship its own contract directory. The translation gate keys off the configured namespace (`heisenberg::blocks.`). `iconUrlTemplate` is built from a configurable route name, and `lucideIconSvg` delegates to the `IconProvider` contract (§4.11) rather than reading a hard vendor path. Hashing/localization/dedup logic ports unchanged.

## 3.5 `BlogBlockContractValidator` — the 10 validators + rules

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogBlockContractValidator`. Pure, stateless, **no constructor**. One public method:

```php
public function validate(array $contract): array;   // => ['valid' => bool, 'errors' => string[]]
```

It runs ten private validators in order: required‑keys → identity → attributes → supports → controls → style → render → innerBlocks → serialization → security. Allow‑lists are class constants: `REQUIRED_TOP_LEVEL_KEYS` (the 17), `ATTRIBUTE_TYPES` (10), `SOURCE_VALUES` (5), `SANITIZERS` (10), `SUPPORT_KEYS` (color/typography/spacing/border/dimensions/layout, with `align` special‑cased), `ALIGN_VALUES`, `CONTROL_TYPES` (11).

Load‑bearing rules:
- `apiVersion` must be integer `1`.
- `name` must match `^gtc\/[a-z0-9][a-z0-9-]*$` — **the `gtc/` namespace is hard‑enforced here.**
- `version` must be semver `^\d+\.\d+\.\d+$`.
- `style.variables.*.source` must reference `attributes.*`/`supports.*`; `default` must not start with `#`.
- `serialization.mode` must equal `json`; `security.allowCustomCss` must be `false`.
- Asset paths (`style.css`, `render.script`) rejected if absolute, protocol‑relative, containing `..`, or wrong extension.
- `validateTemplateNode` recurses `render.template.children`.

This validates contract **definitions**, not block instances. Returns the first‑class error list that the registry surfaces under `errors[]`.

**`[TARGET]`** The single change is the `name` regex: `^<prefix>\/[a-z0-9][a-z0-9-]*$` where `<prefix>` = `config('heisenberg.block_prefix', 'heisenberg')`. Everything else is generic and ports verbatim — this class has **zero** host couplings.

## 3.6 `BlogBlocksPayloadService` — instance validation vs live registry

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogBlocksPayloadService`. Constructor: `(BlogBlockRegistryService $registry)`. Every method returns the triple `['valid'=>bool, 'errors'=>string[], 'errorMap'=>array<dotPath,string[]>]`.

Public methods:
- `validatePayload(array $payload, ?array $registry = null): array` — validates the whole editor envelope: `schemaVersion === 1`, `registryHash` is a `sha256:`‑prefixed string, `blocks` is an array, `computedStyles` is a string, optional `autosave` bool. Then validates each block via `validateBlockInstance`, prefixing errors with `blocks.{i}`.
- `validateBlockInstance(array $block, array $contractsByName, string $basePath=''): array` — **the live‑contract gate.** Requires keys `id, name, schemaVersion, attributes, supports, innerBlocks`. `name` must be a string AND exist in `$contractsByName` (else `"Unknown block name: …"` + short‑circuit). `block.schemaVersion` must `===` the contract's `version`. `attributes` validated via `validateAttributesAgainstContract`; `supports`/`innerBlocks` must be arrays.
- `validateLegacyBlock(array $block, string $basePath=''): array` — shape‑router: (a) bare JSON‑first (`name` starts `gtc/`) → `validateBlockInstance`; (b) enveloped `{type, content:{name,…}}` → unwrap → `validateBlockInstance`; (c) true legacy `{type, content}` → `BlockType::isValid` + a per‑type closure from `legacyContentValidators()`.
- `validatePostBlockCollection($blocks): array` — bulk validator over a `Collection<BlogBlock>`; empty → "Cannot publish… without content blocks"; maps each model to `{type, content}` → `validateLegacyBlock`, prefixing `Block N (type): …`.

Internals: `validateAttributesAgainstContract` enforces only `type` (via `valueMatchesContractType`) and `enum` membership for **declared, present** attributes (missing attributes are OK — the renderer falls back to the contract `default`); `legacyContentValidators()` is a `map<type,callable>` of required‑field checks (e.g. `html_raw` requires `content.html`; `component` requires `content.component` + array `props`; `separator` has none).

**`[TARGET]`** Replace the `gtc/` literal in `validateLegacyBlock`'s shape detection with the configured prefix; everything else ports unchanged (it depends only on the registry + the `BlockType` enum).

## 3.7 `BlogBlockService` — persistence + the `_allow_raw` trust gate

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogBlockService`. Constructor `(?BlogBlocksPayloadService $payloadService = null)` (lazy `app()`‑resolved when null). Couples to `[HOST User]`, `BlogPost`, `BlogBlock`, `BlockType`, `Auth`, `Gate`, `DB`. This is the **single source of truth for block writes.**

The keystone method:
```php
public function persistBlocks(BlogPost $post, array $blockPayloads, ?User $actor = null): Collection;
```

Flow (in order):
1. **`assertBlocksValid($blockPayloads)`** — fail‑closed: each payload `validateLegacyBlock`'d (base path `blocks.{i}`); any errors → `ValidationException::withMessages($errorMap)`. Runs **before** the transaction, so a bad batch writes nothing.
2. Resolve `$actor`: if null, `Auth::user()` when it's a `User`, else null.
3. **Pre‑process every payload via `normalizeBlockPayload`** (the trust‑flag + html_raw gate) **before** opening the transaction — keeps the auth‑failure path cheap and prevents partial writes.
4. Inside `DB::transaction`: load existing blocks `keyBy('id')`. For each normalized payload, build a row `{ type, content, order: $index }` — **the array index is the authoritative `order`; any caller‑supplied `order` is ignored.** If `id` present and belongs to this post → `update()` in place (id/created_at preserved); else `create()`. After the loop, `whereNotIn('id', $keepIds)->delete()` removes editor‑removed blocks (a **full replace**). Returns `$persisted->values()`.
5. **Does not dispatch `BlogPostSaved`** — callers fire it post‑commit (§8, §9).

### The `_allow_raw` gate (`normalizeBlockPayload`) — the security keystone

```php
// STRIP _allow_raw unconditionally — it is a renderer-trust flag and
// must never originate from the wire.
unset($content['_allow_raw']);
if (isset($content['attributes']) && is_array($content['attributes'])) {
    unset($content['attributes']['_allow_raw']);
}

$isHtmlRaw = $type === BlockType::HTML_RAW->value || $name === 'gtc/html-raw';

if ($isHtmlRaw) {
    $this->authorizeHtmlRaw($actor);                 // throws AuthorizationException
    $sanitizer = app(HtmlSanitizationService::class);
    if (isset($content['attributes']['html'])) {
        $content['attributes']['html'] = $sanitizer->purifyForBlockType((string) $content['attributes']['html'], 'html_raw');
        $content['attributes']['_allow_raw'] = true;
    } else {
        $content['html'] = $sanitizer->purifyForBlockType((string) ($content['html'] ?? ''), 'html_raw');
    }
    $content['_allow_raw'] = true;                    // the flag the renderer reads
}
```
- `authorizeHtmlRaw(?User $actor)`: null actor → `AuthorizationException('Unauthenticated callers may not write html_raw blocks.')`; else `Gate::forUser($actor)->denies('useHtmlRaw', BlogPost::class)` → `AuthorizationException('Only Super Admins may author html_raw blocks.')`.
- **Who sets `_allow_raw`:** only the server, only after `useHtmlRaw` passes AND HTMLPurifier sanitizes. **Who reads it:** the renderer (§4.10), which *re‑sanitizes* at render time anyway — it never trusts stored HTML.

This three‑layer defence (strip‑from‑wire → policy gate → re‑sanitize at render) is the single most important security pattern in the whole engine. Preserve it byte‑for‑byte.

Also: a bare JSON‑first block `{id, name:'gtc/xxx', attributes,…}` with no `content` array is wrapped to legacy `{id, type: <name minus gtc/, '-'→'_'>, content: <whole block>}` before storage, so the stored row is always `{type, content, order}`.

**`[TARGET]`** Replace the `Gate::forUser($actor)->denies('useHtmlRaw', …)` with `RoleGate::can($actor, 'useHtmlRaw')` (§13.3); replace `App\Models\User` type‑hints with the `HeisenbergUser` contract; replace the `'gtc/html-raw'` literal with the configured prefix. The flow, the strip, and the re‑sanitize stay identical.

## 3.8 `BlogComponentRegistry` — safe component allowlist

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogComponentRegistry`. No constructor. A **hardcoded** protected `$components` map (not discovered, not config) keying a component **key** → `{ label, description, category, blade, props[] }`. The `blade` value is the safe view name the legacy `component` block renders. Four entries:

| key | blade | props |
|---|---|---|
| `article_card` | `rm-ui.blocks.article-card` | title, excerpt, url, image, date |
| `feature_cta` | `rm-ui.blocks.feature-cta` | title, text, cta_label, cta_url |
| `info_card` | `rm-ui.blocks.info-card` | title, content, icon |
| `faq_block` | `rm-ui.blocks.faq` | title, description |

Methods: `all()`, `getComponent(key)`, `hasComponent(key)`. This is the gate that maps a user‑supplied component key to a fixed, vetted blade name — preventing arbitrary view resolution from a `component` block (§4.9).

**`[TARGET]`** Move the allowlist to `config('heisenberg.components')` so a host registers its own safe components without editing the package. The renderer's `component` path looks the key up there. The `rm-ui.*` blade names are host views — in Heisenberg they become host‑registered view names referenced only via config, so the package ships no `component` blades of its own.

## 3.9 `BlogBuilderService` — editor/persistence orchestrator

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogBuilderService`. Constructor `(BlockRenderer $renderer, BlogBlockService $blockService, SlugService $slugs)`. Couples to `[HOST User]` + the Blog models + `DB`/`Str`. It is the controller‑facing façade for the builder.

Public methods:
- `dashboardPayload(User $user, string $surface): array` — metrics (a single GROUP BY status count + separate missing‑translations / pending‑comments counts) + recent / review‑queue / scheduled collections. `surface==='staff'` and non‑admin → scoped to own `author_id`.
- `paginatePosts(User, string $surface, array $filters=[]): LengthAwarePaginator` — filterable list (search across `title_en/fr,slug,excerpt_en/fr`; status incl. special `trashed`→`onlyTrashed()`; locale, category, tag, author, featured, scheduled, missing‑translation, date range), 12/page.
- `postStatusCounts(User, string): array`.
- `createPost(User $author, array $data): BlogPost` — transaction; **forces `status='draft'`, null published/scheduled** (Rule 07); creates post, `syncTags`, `syncBlocksFromJson`, `renderPost`.
- `updatePost(BlogPost, array $data, ?User $editor=null): BlogPost` — optional revision snapshot; updates; re‑syncs tags/blocks; re‑renders.
- `editorState(?BlogPost): array` — the editor envelope `{ schemaVersion:1, registryHash:null, blocks:[…editorBlockFromStoredBlock…], computedStyles:'', autosave:false }` (note `registryHash:null` — the live hash is supplied client‑side).
- `previewHtml(BlogPost): string`, `categories()`, `tags()`.

Block bridge (protected): `syncBlocksFromJson` (decode `blocks_json`, extract `blocks[]`, map via `storedBlockFromEditorBlock`, `persistBlocks($post,$rows)` — **no actor passed → falls back to `Auth::user()`**); `renderPost` (render EN+FR via `BlockRenderer::renderBlocks`, `saveQuietly`); `storedBlockFromEditorBlock`/`editorBlockFromStoredBlock`/`normalizeEditorBlock` (round‑trip stored `{type,content}` ↔ editor JSON‑first, recursive on `innerBlocks`, UUIDs missing ids); `contractNameForType` (`section_head`→`gtc/section-head`, else `gtc/`+kebab); `legacyAttributesForType` (maps legacy paragraph/heading/quote/list content into JSON‑first attributes).

**`[TARGET]`** Gains a `MediaResolver` only if it ever resolves media directly (it doesn't today — the renderer does). Replace `[HOST User]` with the contract; replace the hard `gtc/` prefixes in `contractNameForType` with the configured prefix; `syncBlocksFromJson` should pass the real actor through to `persistBlocks` rather than relying on `Auth::user()` (a small correctness improvement for queued/CLI contexts).

## 3.10 `[TARGET]` Internalizing contracts + configurable block prefix

Two structural changes turn the engine standalone:

1. **Internalize the contracts.** Today they live in the host app at `resources/views/components/rm-ui/dashboard/blog-builder/blocks/`. Heisenberg ships them at `resources/blocks/<type>/<type>.json` inside the package, publishable so a host can override or add contracts. The registry's default root becomes the package path; `config('heisenberg.block_root')` overrides it; a host's published contracts merge in. Port all 9 existing contracts plus the human schema doc (`…block-schema.md`).
2. **Configurable block prefix.** The `gtc/` literal appears in four places: the validator `name` regex (§3.5), the payload‑service shape detection (§3.6), the block‑service html_raw detection (§3.7), and the builder's `contractNameForType` (§3.9). All read `config('heisenberg.block_prefix', 'heisenberg')`. A migrating GTC host sets it back to `gtc` and keeps every existing contract and stored block working unchanged.

The CSS class prefix (`gtc-block`, `gtc-block-<type>`, `gtc-<type>-*` variables) appears in the renderer and blade partials (§4, §5). Make it `config('heisenberg.css_prefix', 'hb')` so emitted markup carries `hb-block …`; ship a default stylesheet keyed to it. A migrating host sets it to `gtc-block` to reuse existing CSS.

---

# Part 4 — The Renderer (`BlockRenderer`)

**`[AS‑BUILT]`** `Modules\Blog\Services\BlockRenderer` (~1,300 lines). Constructor `(HtmlSanitizationService $sanitizer)`. It compiles an array of blocks to an HTML string for a given locale. It is the most security‑sensitive class in the package and the part most worth porting verbatim.

## 4.1 Pipeline overview

```php
public function renderBlocks(array $blocks, string $locale): string;   // concatenates renderBlock() over the list
public function renderBlock(array $block, string $locale): string;     // dispatches one block
```

`renderBlocks` simply iterates and concatenates. All the intelligence is in `renderBlock`'s dispatch.

## 4.2 Dispatch: JSON‑contract path vs legacy type path

`renderBlock` decides the shape first:

```php
public function renderBlock(array $block, string $locale): string
{
    // (1) Enveloped JSON-first: content is itself a named block
    if (is_array($block['content'] ?? null) && is_string($block['content']['name'] ?? null)) {
        return $this->renderJsonBlock($block['content'], $locale);
    }
    // (2) Bare JSON-first: the block itself is named
    if (is_string($block['name'] ?? null)) {
        return $this->renderJsonBlock($block, $locale);
    }
    // (3) Legacy {type, content}: hard-coded per-type methods
    $type = $block['type'] ?? '';
    $content = $block['content'] ?? [];
    return match ($type) {
        'paragraph' => $this->renderParagraph($content, $locale),
        'heading'   => $this->renderHeading($content, $locale),
        'image'     => $this->renderImage($content, $locale),
        'quote'     => $this->renderQuote($content, $locale),
        'list'      => $this->renderList($content, $locale),
        'cta'       => $this->renderCta($content, $locale),
        'gallery'   => $this->renderGallery($content, $locale),
        'video'     => $this->renderVideo($content, $locale),
        'faq'       => $this->renderFaq($content, $locale),
        'separator' => $this->renderSeparator($content),
        'html_raw'  => $this->renderHtmlRaw($content),
        'testimonial' => $this->renderTestimonial($content, $locale),
        'stat'      => $this->renderStat($content, $locale),
        'accordion' => $this->renderAccordion($content, $locale),
        'section_head' => $this->renderSectionHead($content, $locale),
        'takeaway'  => $this->renderTakeaway($content, $locale),
        'data_row'  => $this->renderDataRow($content, $locale),
        'component' => $this->renderComponent($content),
        'button'    => $this->renderButton($content),
        'columns'   => $this->renderColumns($content, $this->renderBlocks($block['innerBlocks'] ?? [], $locale), $locale),
        default     => '',
    };
}
```

`renderJsonBlock` (the contract path) resolves the block's contract from the registry; if the contract declares a `render.template`, it renders via `renderContractTemplate` (§4.3). Otherwise it falls back to a small per‑name `match` that delegates to the same partial/legacy renderers (e.g. `gtc/paragraph` → `renderParagraph`, `gtc/image` → `renderBlockPartial('image', …)`). Unknown name → `''`.

`renderBlockPartial(name, attributes, innerBlocksHtml='')` renders a blade view `blog::builder.blocks.{name}` if it exists (§5 lists the five partials), else `''`.

## 4.3 Contract template rendering

`renderContractTemplate(array $template, array $block, array $contract, string $locale, bool $isRoot=false)` walks the contract's `render.template` node tree:
- **Editor‑only nodes are skipped** (`isEditorOnlyTemplateNode`: class contains `__picker` or attribute `data-image-picker`).
- **Tag** resolves via `substituteTemplateValue` then is whitelisted to `^[a-z][a-z0-9-]*$` (else falls back to `div`).
- **Class** is interpolated, optionally merged with `contract.style.className` at the root, de‑duplicated.
- **Attributes** are interpolated; names are validated against `^[a-z_:][-a-z0-9_:.]*$`; the special `data-blog-builder-inner-blocks` marks the inner‑blocks slot (renders `block.innerBlocks`); empty `src`/`srcset` are dropped.
- **Root styles** are appended from `blockStyleDeclarations` (§4.5).
- **Children** render via `renderContractTemplateChild`: a `rich-text` child sanitizes the named localized attribute through `sanitizeRichText`; a `text` child escapes an interpolated string; nested element children recurse.
- **Lucide auto‑inject:** if a node has no children and a `data-lucide` attribute, the installed Lucide SVG is inlined (§4.7).
- Void elements (`img, br, hr, input, …`) emit self‑closing; everything else wraps children.

`substituteTemplateValue` resolves `{{ id }}`, `{{ name }}`, `{{ attributes.X }}` (locale‑aware, §4.4), and `{{ supports.X }}` (never localized) mustache tokens inside tag/class/attribute strings.

## 4.4 Locale resolution

Two resolution helpers, both essential to bilingual content:

**`localizedTemplatePath($source, $path, $locale)`** (contract path) — tries the `_<locale>` suffixed leaf first (`content` → `content_en`), then the bare path. So a JSON‑first block can carry `content_en`/`content_fr` and render the active locale.

**`localized($content, $baseKey, $locale, $default='')`** (legacy path) — a richer fallback chain supporting three on‑the‑wire conventions:
1. Suffix form `text_en` / `text_fr` (preferred).
2. Bare locale key `en` / `fr` (legacy factory shape).
3. Locale‑neutral `text`.

Resolution order for `$baseKey='text'`, `$locale='fr'`: `text_fr` → `fr` → `text_en` → `en` → `text` → `$default`. Non‑string values (e.g. an integer `stat.value`) pass through unchanged. `localizedList` is the array‑valued sibling for list‑shaped fields. `isNonEmptyValue` treats `0`/`false` as present (only empty string/empty array are "absent").

**`[TARGET]`** This locale logic is pure and host‑free — port verbatim. It is the canonical answer to "how does one block render in two languages."

## 4.5 Style / CSS sanitization tokens

`blockStyleDeclarations($block, $contract)` materializes the contract's `style.variables` into a CSS `style` attribute: for each `--var`, it reads the declared `source` (from `block.attributes.*` or `block.supports.*`), falls back to the declared `default`, and runs the value through `sanitizeCssValue` keyed by the declared `sanitize` token. Spacing sources (`supports.spacing.margin|padding`) get box‑model treatment via `spacingBoxValue` (per‑side Top/Right/Bottom/Left with fallback to the base value).

`sanitizeCssValue($value, $sanitizer, $fallback)` — the sanitizer tokens and their guards:
| token | accepts |
|---|---|
| `color-token` | `var(--accent-*)`, `#hex{3..8}`, `rgb[a](…)`, `hsl[a](…)` via `isSafeColorValue` |
| `color-token-or-transparent` | the above plus literal `transparent` |
| `size-token` | `0`, `auto`, `100%`, `var(--…)`, `calc(…)`, numeric+unit (`px|rem|em|vw|%`) |
| `integer` | `^-?\d+$` |
| (default) | `^[a-z0-9\s().,%_\/-]+$` |

`isSafeColorValue` enforces the accent‑token / hex / rgb(a) / hsl(a) shapes with full numeric‑range regexes (rejecting `0–255` overflow etc.).

A second, legacy style path (`styleAttributes`/`isSafeStyleValue`) handles the legacy `content.styles` map (paragraph etc.) with an allow‑list of properties (`color`, `backgroundColor`, `fontFamily`, `fontSize`, `fontWeight`, `lineHeight`, `letterSpacing`, `textAlign`, `padding`, `margin`) each validated by one big regex.

**`[TARGET]`** Pure, host‑free — port verbatim. These regexes are the CSS‑injection defence; do not "simplify" them.

## 4.6 Rich‑text sanitization

`sanitizeRichText($value)` (used by contract `rich-text` children) is a **lightweight inline allow‑list** independent of HTMLPurifier: `strip_tags` to `<a><b><br><em><i><strong>`, then re‑parses `<a>` to keep only a scheme‑safe `href` (`https?:`, `mailto:`, `tel:`), and normalizes the remaining inline tags. This is the fast path for editor rich‑text; the heavyweight HTMLPurifier configs (§5) guard `html_raw` and the final render‑cache purification.

## 4.7 Lucide icon resolution

`installedLucideIconSvg($name)` resolves a Lucide SVG by name with **defence‑in‑depth path‑traversal protection**:
1. Allow‑list the name to `[a-z0-9-]+` (strip `lucide-` prefix; `..` and separators cannot survive).
2. `realpath()` both the candidate and the vendor root (`vendor/mallardduck/blade-lucide-icons/resources/svg`).
3. Assert the resolved path starts with `root + DIRECTORY_SEPARATOR` (the trailing separator is critical — without it a sibling `<root>-evil` would pass).

**`[TARGET]`** Move behind the `IconProvider` contract (§4.11): `IconProvider::svg($name): ?string`. Default adapter wraps the Lucide vendor package (a `suggest` dependency); a host without Lucide binds its own. The traversal‑safety logic moves into the default adapter unchanged.

## 4.8 Responsive images

`responsiveImage($content, $context)` and `imageTag(...)` build `<img>` with `src`/`srcset`/`sizes`:
- It calls **`App\Models\PublicFile::forUrl($url)`** → `$file->imagePayload($context)` to get `{ url, srcset, sizes }` for a known media‑library file.
- **Fallback** (no `PublicFile` match): emit the raw URL only if its scheme is `http`/`https` or it's scheme‑relative (relative URLs allowed); anything else (`javascript:`, `data:`, `file:`, …) is dropped.

This is the **second** host coupling in the renderer (after Lucide).

**`[TARGET]`** Replace `PublicFile::forUrl(...)->imagePayload($context)` with `MediaResolver::resolve($url, $context): MediaPayload` (§4.11). The default `NullMediaResolver` returns the scheme‑checked raw URL with empty `srcset`/`sizes` — i.e. exactly the current fallback path. A host with a media library binds a real resolver. The scheme allow‑list stays in the renderer as a last line of defence.

## 4.9 Per‑block render contracts (all 20)

Every renderer returns `''` when its required content is missing (empty blocks vanish — they never emit empty tags). Markup is class‑prefixed `gtc-block gtc-block-<type>`. Summary of each:

| type | required | emits |
|---|---|---|
| `paragraph` | `text` | `<p class="gtc-block gtc-block-paragraph" [style]>…escaped…</p>` |
| `heading` | `text` | `<h{1..6} class="… gtc-heading-{n}">…</h{n}>` (level clamped 1–6) |
| `image` | `url` | `<figure>` + `imageTag` + optional `<figcaption>` |
| `quote` | `text` | `<blockquote>` + `<p>` + optional `<cite>` (author, optional source) |
| `list` | `items` | `<ul>`/`<ol>` (style `bullet`/`numbered`) of escaped `<li>` |
| `cta` | `text`+`url` | `<div class="… cta-{style}">` + `<a class="cta-link">` |
| `gallery` | `images[]` | `<div class="… gallery-{layout}">` of `imageTag` |
| `video` | `url` | YouTube/Vimeo → safe `<iframe>` (converted+escaped embed URL); else `<video controls>` + optional caption |
| `faq` | `items[]` | `<div>` of `{ <h3 question>, <p answer> }` |
| `separator` | — | `<hr>` / dotted / decorative `<div aria-hidden>` per `style` |
| `html_raw` | `_allow_raw` | gated + re‑sanitized; see §4.10 |
| `testimonial` | `quote` | `<blockquote>` + author block (optional avatar via `imageTag`, role, company) |
| `stat` | `value` or `label` | `<div>` + `<span stat-value>{prefix}{value}{suffix}</span>` + `<span stat-label>` |
| `accordion` | `items[]` | `<div>` of toggle `<button aria-expanded aria-controls>` + hidden `<div>` panels |
| `section_head` | `title`/`text` | `<header>` + optional subtitle + `<h2>` |
| `takeaway` | `text` | `<aside class="gtc-block-takeaway">` + `<p>` |
| `data_row` | `label`/`value` | `<div>` + `<span data-label>` + `<span data-value>` |
| `component` | `component` key | safe component via `BlogComponentRegistry` (§3.8) → `Blade::render("<x-{blade} … />")`; missing/unapproved → HTML comment; errors logged + commented |
| `button` | — | delegates to `renderBlockPartial('button', …)` (§5) |
| `columns` | — | `<div class="… columns-{n} va-{align}" style="--columns-count/--columns-gap">` wrapping pre‑rendered inner‑block HTML (1–4 columns, gap size‑token‑validated, optional stack‑on‑mobile) |

**Embed‑URL conversion:** `convertYoutubeUrl` (watch / youtu.be / embed forms → `youtube.com/embed/<id>`) and `convertVimeoUrl` (→ `player.vimeo.com/video/<id>`); the result is additionally `htmlspecialchars`‑escaped into the `src` (defence against converter regression).

**Component rendering** uses `buildAttributesString` which validates prop **keys** against `^[a-zA-Z][a-zA-Z0-9_-]*$` (so a poisoned payload can't inject attribute names like `onerror`) and `htmlspecialchars`‑escapes **values**.

## 4.10 The renderer security model

The renderer assumes it runs with **no auth context** (it executes inside a queued job, §8). Its defences:

1. **Universal escaping.** `escape()` = `htmlspecialchars(…, ENT_QUOTES|ENT_HTML5, 'UTF-8')` wraps every user string in every per‑block method.
2. **URL scheme allow‑lists.** Image fallback URLs, CTA links, video src — all scheme‑checked (`http`/`https`/scheme‑relative; CTA/button also `mailto`/`tel`).
3. **CSS token validation.** Every style value passes a sanitizer regex (§4.5).
4. **`html_raw` triple gate** — `renderHtmlRaw($content)`:
   ```php
   if (! ($content['_allow_raw'] ?? false)) {
       return '<!-- html_raw block rendering suppressed: missing _allow_raw flag -->';
   }
   $sanitized = $this->sanitizer->purifyForBlockType((string) ($content['html'] ?? ''), 'html_raw');
   return $sanitized === '' ? '' : '<div class="gtc-block gtc-block-html-raw">' . $sanitized . '</div>';
   ```
   The renderer **re‑sanitizes even though the write path already did** — it never trusts stored HTML. Combined with the write‑side strip + policy gate (§3.7), raw HTML must survive *three* independent checks to reach a page.
5. **Component allow‑list.** Only `BlogComponentRegistry` keys resolve to a blade; prop keys are name‑validated.
6. **Inner‑block escaping boundary.** `columns` and the inner‑blocks slot concatenate **pre‑rendered** inner HTML without re‑escaping — each inner block escaped its own content at its own renderer. (A clear, documented invariant: never double‑escape, never trust un‑rendered input.)

## 4.11 `[TARGET]` `MediaResolver` + `IconProvider` contracts

The renderer's only two host couplings become injected contracts:

```php
namespace Heisenberg\Contracts;

interface MediaResolver
{
    /** @return array{url:string, srcset:?string, sizes:?string} */
    public function resolve(string $url, string $context): array;
}

interface IconProvider
{
    public function svg(string $name): ?string;   // null when unknown
    /** @return string[] available icon slugs (for the editor picker) */
    public function available(): array;
}
```

- `NullMediaResolver` (default): returns the scheme‑checked raw `url` with `srcset`/`sizes` `null` — byte‑identical to today's fallback path.
- A GTC host binds a `PublicFileMediaResolver` wrapping `PublicFile::forUrl(...)->imagePayload(...)`.
- `LucideIconProvider` (default, optional dep): wraps the vendor package with the traversal‑safe lookup from §4.7.

The renderer constructor becomes `(HtmlSanitizationService $sanitizer, MediaResolver $media, IconProvider $icons)`. Everywhere it currently names `PublicFile` or the Lucide vendor path, it calls the contract. **No other change** — the 20 per‑block methods, the escaping, the CSS guards, and the `html_raw` triple gate are all host‑free and port verbatim.

---

# Part 5 — HTML Sanitization (`HtmlSanitizationService`)

**`[AS‑BUILT]`** `Modules\Blog\Services\HtmlSanitizationService`. No constructor. Wraps **two** lazily‑built, cached `HTMLPurifier` instances (vendor `ezyang/htmlpurifier`). This is the server‑side single source of truth for HTML sanitization and is **security‑critical** — port the configs exactly.

## 5.1 Public API & routing

```php
public function purify(string $html): string;                              // empty → ''; else rawPurifier
public function purifyForBlockType(string $html, string $blockType): string;
```
`purifyForBlockType` routes:
```php
match ($blockType) {
    'html_raw' => $this->rawPurifier()->purify($html),       // the HARDENED config
    default    => $this->richTextPurifier()->purify($html),  // the STRICT inline config
};
```
So **only `html_raw` gets the hardened (richer) config**; every other block type gets the strict inline config. `purify()` (no type) uses the hardened config — it's the final render‑cache pass in `RenderBlogPostJob` (§8).

## 5.2 Config A — `rawPurifier()` (hardened, for `html_raw`)

```php
$config = HTMLPurifier_Config::createDefault();
$config->set('Core.Encoding', 'UTF-8');
$config->set('HTML.Doctype', 'HTML 4.01 Transitional');
```

**`HTML.Allowed`** (verbatim — note: NO `<h1>`, NO `script/style/object/embed/form/input/button/link/meta/base`, NO `on*` handlers):
```
p, br, strong, em,
a[href|title|target|rel],
ul, ol, li,
blockquote,
h2, h3, h4, h5, h6,
img[src|alt|title|width|height],
figure, figcaption,
pre, code,
hr,
div[class], span[class],
table, thead, tbody, tr,
th[scope|colspan|rowspan],
td[colspan|rowspan],
iframe[src|width|height|frameborder|allowfullscreen|loading|title]
```

**Schemes:** `['http'=>true, 'https'=>true, 'mailto'=>true, 'tel'=>true]`.

**SafeIframe** (embeds restricted to YouTube + Vimeo only):
```php
$config->set('HTML.SafeIframe', true);
$config->set('URI.SafeIframeRegexp',
    '%^https://(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');
```

**Links:** `HTML.TargetBlank=true`, `Attr.AllowedFrameTargets=['_blank']`. **Other:** `Core.RemoveInvalidImg=true`, `AutoFormat.RemoveEmpty=false`, `AutoFormat.AutoParagraph=false`.

**Custom HTML5 definition** (the HTML 4.01 doctype doesn't know `figure`/`figcaption`; without this, cold‑cache sanitization throws and strips image wrappers):
```php
$config->set('HTML.DefinitionID', 'gtc-blog-raw');
$config->set('HTML.DefinitionRev', 2);
if ($def = $config->maybeGetRawHTMLDefinition()) {
    $def->addElement('figure', 'Block', 'Flow', 'Common');
    $def->addElement('figcaption', 'Block', 'Flow', 'Common');
    $def->addAttribute('iframe', 'allowfullscreen', 'Bool');
    $def->addAttribute('iframe', 'loading', 'Enum#lazy,eager,auto');
}
```
(SafeIframe registers `<iframe>` with a minimal attr set, so `allowfullscreen` + `loading` must be re‑added for embeds.)

## 5.3 Config B — `richTextPurifier()` (strict inline, for all non‑`html_raw`)

Same encoding/doctype. **`HTML.Allowed`** (inline only — no headings, images, iframes, tables, divs, spans):
```
p, br, strong, em, a[href|title|rel], ul, ol, li
```
Same schemes. Flags: `AutoFormat.AutoParagraph=false`, **`AutoFormat.RemoveEmpty=true`** (note: `true` here, `false` in the raw config). No custom HTML definition.

## 5.4 Cache directory

`ensureCacheDirectory()` resolves `storage_path('framework/cache/htmlpurifier')`, creates it `@mkdir(…, 0775, true)` if missing, and returns it only if writable; on failure it logs a warning and returns `null`, in which case the config disables the definition cache (`Cache.DefinitionImpl = null`) so a permissions problem never crashes sanitization. The cache path must be set **before** the custom HTML definition so the definition is cacheable.

**`[TARGET]`** Two changes: (1) `storage_path('framework/cache/htmlpurifier')` → `config('heisenberg.purifier_cache_path', storage_path('framework/cache/heisenberg-purifier'))`; (2) the `HTML.DefinitionID` token `gtc-blog-raw` → `heisenberg-raw`. Optionally expose the two `HTML.Allowed` lists as `config('heisenberg.sanitization.raw_allowed' | 'rich_allowed')` so a host can widen/narrow the allow‑list without forking the class — but ship the exact lists above as defaults. **Do not** loosen them silently; they are the XSS boundary.

---

# Part 6 — Patterns, Revisions, Taxonomy & Slugs

## 6.1 `SlugService`

**`[AS‑BUILT]`** `Modules\Blog\Services\SlugService`. No constructor. Const `MAX_RETRIES = 5`.

```php
public function generate(string $sourceText, string $modelClass, ?int $ignoreId = null): string;
```
Algorithm:
1. `$base = Str::slug($sourceText) ?: 'untitled'`.
2. Loop up to 6 times (`attempt 0..5`): `$slug = candidate($base, $counter)`; if `! slugExists($modelClass, $slug, $ignoreId)` → return it; on `UniqueConstraintViolationException` (concurrent insert) → bump counter and retry.
3. On exhaustion, return the last candidate (the DB unique index is the final guard — a true collision surfaces as a 500, preferred over a silent overwrite).

`candidate($base, $counter)` = `$counter === 1 ? $base : "{$base}-{$counter}"` — so the sequence is `base, base-2, base-3, …` (**never `-1`**; numbering starts at `-2`). `slugExists` does `$modelClass::where('slug', $slug)->when($ignoreId, fn($q)=>$q->where('id','!=',$ignoreId))->exists()` — per‑model‑class scoped (each table is its own slug namespace).

**`[TARGET]`** Pure, host‑free — port verbatim. Document the **prerequisite**: every sluggable table must carry a DB‑level unique index on `slug` (Heisenberg migrations provide them). The `$modelClass` parameter keeps the service model‑agnostic, which is exactly the standalone shape.

## 6.2 `TaxonomyService`

**`[AS‑BUILT]`** `Modules\Blog\Services\TaxonomyService`. Constructor `(SlugService $slugs)`. Owns all CRUD + listing for `BlogCategory` and `BlogTag` (extracted from the controller to keep it thin).

Categories:
- `listCategories($search, $perPage): array` → `{ paginator (with parent + posts_count, ordered by order,name_en), all (id/parent_id/name_en for the tree), total }`.
- `createCategory($data): BlogCategory` — name trimmed; slug from `$data['slug']` else name; **`name_fr` intentionally null on create**; `order = max(order)+1` (append).
- `updateCategory($category, $data): array` → `{ category, error }` — **returns** an error string (does not throw) for `withErrors()`. Cycle prevention: a category can't be its own parent (`'A category cannot be its own parent.'`) nor have a descendant as parent (`'Cannot assign a descendant as the parent.'`, via `getDescendantIds()`).
- `deleteCategory($category)` — promotes children to root (`children()->update(['parent_id'=>null])`) then deletes.
- `bulkDeleteCategories($ids, callable $authorize): array` → `{ processed, skipped }` (authorization injected as a callable so the policy stays in the controller).

Tags: `listTags`, `createTag` (`name_fr` null), `updateTag`, `deleteTag` (**detaches from all posts first** via `posts()->detach()`), `bulkDeleteTags`.

**`[TARGET]`** Replace `BlogCategory`/`BlogTag` with the configured models; the injected‑`callable $authorize` pattern is already a clean decoupling (keep it). Host‑free otherwise.

## 6.3 `BlogRevisionService`

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogRevisionService`. Constructor `(BlogBlockService $blockService)`.

- `snapshot(BlogPost $post, User $actor, string $revisionType='manual'): BlogRevision` — loads `$post->blocks()->orderBy('order')->get()`, maps each to `{ type (enum‑safe), content, order }`, and `BlogRevision::create([...])` capturing the blocks array + both rendered HTML + title/excerpt EN/FR + `revision_type`.
- `restore(BlogPost $post, BlogRevision $revision, User $actor): BlogPost` — **security‑critical:** restores blocks via `$this->blockService->persistBlocks($post, $blocks, $actor)` (the canonical write path), so restored `html_raw` is **re‑sanitized + policy‑gated against the restoring actor** — a direct `->create()` would re‑inject stored markup verbatim (an XSS path). `persistBlocks` does a full replace, so no manual delete‑all is needed. Then restores text fields (with fallback to current), and records the restore itself as a new `'restore'` revision.
- **No pruning / retention cap** — revisions accumulate unbounded.

**`[TARGET]`** Replace `[HOST User]` with the contract. Add an optional `config('heisenberg.revisions.keep', null)` retention cap (prune oldest beyond N) — a small, safe improvement on the unbounded growth (§16). The `restore`‑via‑`persistBlocks` invariant is the key thing to preserve: **never restore blocks by raw insert.**

## 6.4 `BlogPatternService`

**`[AS‑BUILT]`** `Modules\Blog\Services\BlogPatternService`. Constructor `(SlugService $slugs, BlogBlocksPayloadService $payloadValidator, BlogBlockRegistryService $registry)`.

- `forActor(User $user): Collection` — all patterns visible to the actor: admins/super_admins see everything (moderation); others see `author_id = me OR is_shared = true`. Mirrors `BlogPatternPolicy::view`.
- `create(User $author, array $data): BlogPattern` — `normalizeBlocks` (envelope‑or‑list coercion → flat list, dropping `schemaVersion`/`registryHash` since the registry is re‑consulted at insert) → `assertBlocksAreValid` (validates each block against the **live registry** via `validateBlockInstance`/`validateLegacyBlock`; throws `InvalidArgumentException` on failure) → name required → slug from `slug`‑or‑`name` → `BlogPattern::create([...])`. The caller (controller) is responsible for the `share` policy check on `is_shared`.
- `update(BlogPattern, array $data): BlogPattern` — partial update (only present keys), same validation for `blocks`.
- `delete(BlogPattern)` — soft delete.
- `incrementUsage(BlogPattern)` — atomic `usage_count + 1` via `DB::raw`, deliberately **not** bumping `updated_at` (telemetry, not a content edit).

`contractsByName()` is memoized from `registry()['blocks']` for the service's singleton lifetime.

**`[TARGET]`** Replace `[HOST User]` + the `hasAnyRole(['super_admin','admin'])` in `forActor` with the `RoleGate` (§13.3); everything else (validation‑against‑live‑registry, the normalize/flat‑list logic, atomic usage bump) ports unchanged. Patterns are a strong reusable feature — keep them in ring 1.

## 6.5 Form requests (patterns)

`StoreBlogPatternRequest` — `authorize()`: `can('create', BlogPattern::class)`; rules: `name` required ≤160, `description` ≤2000, `slug` ≤180, `blocks` required array min 1, `is_shared` boolean. `UpdateBlogPatternRequest` — `authorize()`: `can('update', $pattern)`; all fields `sometimes`. In both, `is_shared=true` is **additionally gated by the `share` policy in the controller**, not the request (§9).

---

# Part 7 — Publishing Lifecycle & State Machine

## 7.1 The state graph

**`[AS‑BUILT]`** A pure `BlogPostStateMachine` service holds two hardcoded tables. The transition graph (`$transitions`, FROM → allowed TO):

```
draft          → pending_review, archived
pending_review → published, scheduled, draft
published      → archived
scheduled      → published, archived, draft
archived       → draft
```

The role‑permission table (`$rolePermissions`, target status → allowed roles):

```
pending_review → super_admin, admin, employee_l1, employee_l2, employee_l3
published      → super_admin, admin
scheduled      → super_admin, admin
archived       → super_admin, admin
draft          → super_admin, admin, employee_l1, employee_l2, employee_l3
```

The state machine validates but **never mutates**. Public API: `canTransition(from,to)`, `canUserTransition(to,role)`, `getValidTransitions(from)`, `getAllowedTransitionsForRole(from,role)`, `getAllowedTransitionsForUser(from,roles[])`, `isTerminal(status)`, `requiresReview(status)`, `isPubliclyVisible(status)` (delegates to the enum), `isValidStatus(status)`, `getTransitionLabel(from,to)`.

**`[TARGET]`** The two tables move to config (§14): `config('heisenberg.lifecycle.transitions')` and `config('heisenberg.lifecycle.role_permissions')`. The state machine reads them at construction. Role names become arbitrary host strings; the `RoleGate` (§13.3) answers "does this user hold role X". This is the single change that makes the lifecycle host‑agnostic — the graph logic ports verbatim.

## 7.2 `BlogPostTransitionAction` — the pipeline

**`[AS‑BUILT]`** `Modules\Blog\Actions\BlogPostTransitionAction`. Constructor `(BlogPostStateMachine $stateMachine, BlogBlockService $blockService)`. The only sanctioned path for a status change (GTC Rule 07: never write `$post->status` directly in a controller).

```php
public function handle(BlogPost $post, string $toStatus, User $actor, ?string $note = null): BlogPost;
```
Pipeline, in order:
1. **Validate** — `validateTransition($post, $toStatus, $actor)` (§7.3).
2. **Capture** `$fromStatus = $post->status`.
3. **Mutate** — `$post->status = $toStatus` (raw string assignment).
4. **Timestamps** — if `$toStatus === 'published'`: `published_at = now()`, `scheduled_at = null`; then `$post->save()`.
5. **Audit (queued)** — `LogBlogPostAuditEvent::dispatch($post, $fromStatus, $toStatus, $actor, $note)`.
6. **Event** — `event(new BlogPostTransitioned($post, $fromStatus, $toStatus, $actor, $note))`.
7. **Review note (conditional)** — if `$toStatus ∈ {draft, published}` and `$note` non‑empty: `BlogReviewNote::create([... action: 'approved'|'request_changes' ...])`.
8. **Revision snapshot** — `app(BlogRevisionService::class)->snapshot($post, $actor, 'auto_save')`.

**Critical absence:** `handle()` does **not** fire `BlogPostSaved`, so it does **not** trigger a re‑render. Rendering is decoupled from transitions (§8.4). The generic 7‑step pipeline's PDF/Scout steps (from GTC's application state machine) are **absent** here.

Convenience helpers (all delegate to `handle`): `submitForReview`, `approveAndPublish` (sets `published_at` first), `schedule(DateTimeInterface)` (throws `InvalidScheduledDateException` if `<= now()`), `archive`, `returnToDraft`.

## 7.3 `validateTransition` — the gate

In order:
1. **Block validation** (publish/schedule only) — `$blockService->validatePostBlocks($post)`; invalid → `InvalidBlogTransitionException`. A post can't be published/scheduled with invalid or missing blocks.
2. **Valid status** — else `InvalidBlogTransitionException("Invalid status: …")`.
3. **Graph legality** — `canTransition($post->status, $toStatus)`; else `InvalidBlogTransitionException("Transition from '…' to '…' is not allowed.")`.
4. **Role permission** — `$actor->getRoleNames()` (Spatie); if any role passes `canUserTransition($toStatus, $role)` → allowed; else `UnauthorizedBlogTransitionException`.
5. **Author‑specific** (only when actor is neither super_admin nor admin): submitting `pending_review` requires being the author; `archived` is admin/super_admin‑only.

## 7.4 The publish‑authority open question (`[RESOLVE BEFORE PORTING]`)

**What the code actually enforces:** an `admin` **can** publish. Both `super_admin` and `admin` are in `$rolePermissions['published']`, and `validateTransition` grants the transition to any role in that list; the author‑specific block adds no extra restriction on `published`. Employees (l1/l2/l3) cannot publish.

This **contradicts** the project memory note `blog-publish-authority-decision.md` ("can `admin` publish, or only `super_admin`? — tests vs code disagree"). The policy layer agrees with the code (`BlogPostPolicy::publish` = `hasAnyRole(['super_admin','admin']) && isPendingReview()`). **Heisenberg must pick the canonical rule and align the role table, the policy, and the tests.** Because it's now `config('heisenberg.lifecycle.role_permissions')`, a host simply sets the published row to `['super_admin']` or `['super_admin','admin']`. Default recommendation: keep `['super_admin','admin']` (matches current code + policy) and document it as the decision.

## 7.5 Scheduled publishing command

**`[AS‑BUILT]`** `PublishScheduledBlogPosts` (signature `blog:publish-scheduled`), scheduled every minute. `handle(BlogPostTransitionAction $action): int`:
- Query: `BlogPost::where('status', SCHEDULED)->whereNotNull('scheduled_at')->where('scheduled_at','<=', now())->get()` — **the only condition** (a removed `orWhere('published_at',…)` branch once caused future‑dated posts to publish early; do not reintroduce it).
- System actor: `getSystemActor()` returns the first user with role `super_admin`; if none → error + `FAILURE` (fails loudly, never silently no‑ops). The scheduler always publishes **as super_admin**, sidestepping the admin‑vs‑super_admin authority question for automated publication.
- For each due post: `$action->handle($post, PUBLISHED, $systemActor, 'Scheduled publication: …')` through the full pipeline; per‑post `\Throwable` increments a failure count and logs. Returns `SUCCESS` iff zero failures.

**`[TARGET]`** The "system actor" resolution moves behind config/contract: `config('heisenberg.system_actor_resolver')` or a `RoleGate::systemActor()` that returns an authenticatable with publish authority. Rename to `heisenberg:publish-scheduled`.

## 7.6 Exceptions

Three exceptions, all `extends \Exception`, empty bodies, messages supplied at throw‑time: `InvalidBlogTransitionException` (invalid blocks / invalid status / illegal graph move), `UnauthorizedBlogTransitionException` (role lacks permission / non‑author submit / non‑admin archive), `InvalidScheduledDateException` (`schedule()` with a past date). Rename without the `Blog` prefix; otherwise verbatim.

---

# Part 8 — Events, Jobs, Listeners & Queues

## 8.1 Events

**`BlogPostSaved`** — `{ public BlogPost $post; public bool $isNewRevision = false; }`. Traits `Dispatchable, InteractsWithSockets, SerializesModels` (not broadcast, not self‑queued). Triggers the render pipeline. Fired **only by controllers** (not the transition action).

**`BlogPostTransitioned`** — `{ BlogPost $post; string $fromStatus; string $toStatus; User $actor; ?string $note = null; }`. Helpers `wasPublished()`, `wasScheduled()`, `wasArchived()`, `wasSubmittedForReview()`, `getTransitionDescription()` (reads `$actor->email`). Fired **only** by `BlogPostTransitionAction::handle()` (§7.2). `[HOST User]` coupling on `$actor`.

## 8.2 Jobs

**`RenderBlogPostJob implements ShouldQueue`** — `tries=3`, `backoff=30s`, **default queue** (not pinned). Constructor `(BlogPost $post)`. `handle(BlockRenderer $renderer, HtmlSanitizationService $sanitizer)`:
1. `$post = $this->post->fresh()`; null → log warning + return.
2. blocks = `$post->blocks()->ordered()->get()->toArray()`; empty → write empty rendered HTML, bust cache, return.
3. render EN + FR via `renderBlocks`.
4. `applyPurification()` each via `HtmlSanitizationService::purify()` (final XSS pass).
5. `$post->update(['rendered_html_en'=>…, 'rendered_html_fr'=>…])`; bust cache.
- `bustCache()` forgets: `blog_post_{id}`, `blog_post_slug_{slug}`, `blog_posts_public`, `blog_posts_public_{category_id}`. `failed()` logs. `tags()` = `['blog', "blog_post:{id}"]` (Horizon).

**`LogBlogPostAuditEvent implements ShouldQueue`** — `tries=3`, `backoff=60s`, `onQueue('audit')`. Constructor `(BlogPost, string $fromStatus, string $toStatus, User $actor, ?string $note)`. `handle()` writes via Spatie:
```php
activity()->causedBy($this->actor)->performedOn($this->post)
    ->withProperties([
        'type' => 'blog_post.transition',
        'from_status' => $this->fromStatus, 'to_status' => $this->toStatus,
        'post_id' => $this->post->id, 'post_title' => $this->post->title, 'note' => $this->note,
    ])->log("Blog post transitioned from '{$this->fromStatus}' to '{$this->toStatus}'");
```
`failed()` calls `report($e)`. **Quirk:** `onQueue('audit')` names a queue with no dedicated connection in `config/queue.php` (the priority connections are `critical/default/low/report`); confirm a worker consumes `--queue=audit` or audit jobs pile up (§16).

## 8.3 Listeners & wiring

**`HandleBlogPostSaved implements ShouldQueue`** (`connection='low'`, `queue='low'`) — dispatches `RenderBlogPostJob::dispatch($event->post)` (ignores `isNewRevision`).

**`HandleBlogPostTransitioned`** (synchronous, not queued) — on `toStatus ∈ {published,scheduled,archived}` busts cache; on `wasPublished()` calls `regenerateSitemap()` (a **stub** — the Spatie‑sitemap write is commented out). Its `bustCache()` uses a **different** key scheme: `blog.post.{slug}.{locale}`, `blog.group.{group}.{locale}`, `blog.posts.listing.{locale}`.

`EventServiceProvider`: `BlogPostTransitioned → [HandleBlogPostTransitioned]`, `BlogPostSaved → [HandleBlogPostSaved]`.

## 8.4 The render‑is‑decoupled‑from‑transition invariant

Rendering happens on **content save** (`BlogPostSaved`, fired by controllers), not on **status transition** (`BlogPostTransitioned`). A pure status change does not re‑render HTML. **Risk:** a scheduled post whose blocks were edited after scheduling without a save event will publish with stale `rendered_html_*`. Heisenberg should decide whether `approveAndPublish`/`schedule` should also enqueue a render (recommended: yes — fire a render on publish) (§16, item 4).

## 8.5 `[TARGET]` Queues, cache, and the audit sink

- **Queue names** become config: `config('heisenberg.queues.render' | 'audit')`, defaulting to sensible names that map to real connections (don't ship the unconfigured `audit` queue — point it at `default` unless overridden).
- **Cache keys** unify under one scheme (§16, item 3): a single `CacheBuster` helper both the job and the transition listener call, so a transition and a render never leave divergent stale entries. Make the cache key prefix configurable.
- **Audit** moves behind the `AuditSink` contract (§13.4): `AuditSink::record(actor, subject, properties, message)`. Default `NullAuditSink` (no‑op); ship a `SpatieAuditSink` adapter when `spatie/laravel-activitylog` is present. `LogPostAuditEvent` calls the sink instead of the global `activity()` helper, so an app without Spatie still transitions.
- **Sitemap / social webhook** are stubs today — either implement them behind a `SitemapNotifier` contract or drop them from the public contract (§16, item 5). Recommended: drop from the package, emit `PostTransitioned` and let the host subscribe.

---

# Part 9 — HTTP Surface

## 9.1 Route topology (`[AS‑BUILT]`)

The module owns **less** of its own HTTP surface than you'd expect:
- `Modules/Blog/routes/web.php` — only the **public** news routes.
- `Modules/Blog/routes/api.php` — the admin JSON‑API + public JSON‑API.
- **The builder web routes (admin/staff dashboards) live in the host app**, in `routes/admin.php` and `routes/staff.php`, which import `Modules\Blog\…\Builder\*` controllers directly and bind them to the `admin.`/`staff.` subdomains.

`RouteServiceProvider::map()` runs `mapApiRoutes()` (`Route::middleware('api')->prefix('api')->name('api.')->group(api.php)`) then `mapWebRoutes()` (`Route::middleware('web')->group(web.php)` — no prefix). The doubled `api` prefix was historically the cause of `/api/api/admin` 404s, so the inner groups use `prefix('admin')->name('admin.')`.

**`[TARGET]`** Heisenberg ships **all** its routes inside the package (a `routes/` dir loaded by the provider), with the subdomain/middleware/route‑name conventions made configurable (§9.5). The builder web routes are re‑homed into the package as an **optional** route file the host can enable; the JSON API is the primary, always‑on surface.

## 9.2 Public routes — `web.php`

| Method | URI | Name | Controller@action |
|---|---|---|---|
| GET | `/news` | `news.index` | `BlogController@index` |
| GET | `/news/{slug}` | `news.show` | `BlogController@show` |

## 9.3 API routes — `api.php` (base `prefix('api')->name('api.')`)

Public (mw `auth:sanctum, verified`): `GET /api/blog` → `BlogController@index`; `GET /api/blog/{id}` → `BlogController@show`.

The shared admin middleware stack (`$adminApiMiddleware`):
```php
[ EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'verified', 'verified.mfa',
  'role:super_admin|admin|employee_l1|employee_l2|employee_l3' ]
```

Admin READ (`prefix('admin')->name('admin.')`, `throttle:120,1`):
| Method | URI | Action |
|---|---|---|
| GET | `/api/admin/blog-posts` | `BlogPostApiController@index` |
| GET | `/api/admin/blog-posts/{post}` | `BlogPostApiController@show` |
| GET | `/api/admin/blog-posts/{post}/translation-status` | `BlogPostApiController@translationStatus` |
| GET | `/api/admin/blog-patterns` | `BlogPatternApiController@index` |
| GET | `/api/admin/blog-patterns/{pattern}` | `BlogPatternApiController@show` |

Admin MUTATION (`throttle:60,1`):
| Method | URI | Action |
|---|---|---|
| POST | `/api/admin/blog-posts` | `BlogPostApiController@store` |
| PUT | `/api/admin/blog-posts/{post}` | `BlogPostApiController@update` |
| DELETE | `/api/admin/blog-posts/{post}` | `BlogPostApiController@destroy` |
| POST | `/api/admin/blog-posts/{post}/transition` | `BlogBuilderController@transition` |
| POST | `/api/admin/blog-posts/{post}/link-translation` | `BlogPostApiController@linkTranslation` |
| POST | `/api/admin/blog-posts/{post}/unlink-translation` | `BlogPostApiController@unlinkTranslation` |
| POST | `/api/admin/blog-patterns` | `BlogPatternApiController@store` |
| PUT | `/api/admin/blog-patterns/{pattern}` | `BlogPatternApiController@update` |
| DELETE | `/api/admin/blog-patterns/{pattern}` | `BlogPatternApiController@destroy` |
| POST | `/api/admin/blog-patterns/{pattern}/insert` | `BlogPatternApiController@insert` |

Admin AUTOSAVE (`throttle:30,1`): `PUT /api/admin/blog-posts/{id}/content` → `BlogPostContentController@update`.

Standalone admin (full `$adminApiMiddleware`, `throttle:120,1`):
| Method | URI | Constraint | Controller |
|---|---|---|---|
| GET | `/api/blog/blocks/registry` | — | `BlogBlockRegistryController` (`__invoke`) |
| GET | `/api/blog/icons/{icon}` | `where icon [a-z0-9-]+` | `BlogLucideIconController` (`__invoke`) |

## 9.4 Builder web routes (host `routes/admin.php` / `routes/staff.php`)

Bound to `Route::domain("admin." . config("app.domain"))` (and `staff.`), outer mw `["web","auth","setlocale","verified.mfa","subdomain.enforce"]`, content group `prefix('content')->name('content.')` with `role:super_admin|admin` (admin) / `role:employee_l1|employee_l2|employee_l3` (staff). Route names become `admin.content.*` / `staff.content.*`. The full admin set (≈42 routes): post CRUD + lifecycle (`submit-review`, `publish`, `schedule`, `archive`, `request-changes`), `dashboard`, `posts.{index,create,edit,preview,blocks,blocks.update,restore,force-delete,bulk,copy-blocks,seo.save,revisions.restore}`, `review.index`, `tags.*`, `categories.*`, and `comments.*` (full per‑comment moderation). **Staff registers a subset** — same post/taxonomy routes, but comment moderation limited to `index` + `bulk` and **no** `dashboard`.

**Surface detection coupling:** every builder controller derives `surface` via `str_starts_with($request->route()?->getName(), "staff.") ? "staff" : "admin"` and builds redirects from `routePrefix($surface) = "{$surface}.content."`. This **hard‑depends on the host registering `admin.content.*`/`staff.content.*` route names.**

**`[TARGET]`** Replace the surface‑detection string‑sniffing with a config map `config('heisenberg.surfaces')` → `{ name_prefix, domain, roles }`, and resolve the redirect prefix from it. Ship the builder routes as an opt‑in package route file. The `verified.mfa` / `subdomain.enforce` aliases are host middleware — Heisenberg references middleware **groups by config name**, never by GTC alias.

## 9.5 Controllers — responsibilities

- **`BlogController`** (public): `index` (locale‑aware, category/search/sort filters, featured logic, `paginate(9)`, view `blog::public.index`); `show` (slug lookup with relations, `view_count` increment, related posts, view `blog::public.show`). No `authorize()` (public).
- **`BlogBuilderController`** (constructor: `BlogBuilderService`, `BlogBlockService`, `BlogBlocksPayloadService`; uses `AuthorizesRequests`, `FormatsApiErrors`): the dashboard/index/create/edit/update/blocks/lifecycle hub. Every action `authorize()`s (`viewAny`/`create`/`update`/`publish`/`schedule`/`archive`/`delete`/`forceDelete`). `updateBlocks` validates each block, persists in a transaction, fires `BlogPostSaved`. `transition` validates `to_state ∈ BlogPostStatus::values()` and routes to the matching `BlogPostTransitionAction` method, catching the three transition exceptions → 422.
- **`TaxonomyController`** (constructor `TaxonomyService`): category/tag index/store/update/destroy/bulk; JSON‑or‑redirect dual responses; bulk passes a `user->can('delete', …)` closure into the service.
- **`BlogRevisionController`** (constructor `BlogRevisionService`): `restoreRevision` — `authorize('restoreRevision', $post)` (super_admin‑only), aborts 403 if the revision doesn't belong to the post, restores, redirects.
- **`BlogPreviewController`** (constructor `BlockRenderer`; imports `App\Models\PublicFile`): `preview` renders ordered blocks and returns the **host** `news.show` view (not `blog::`) with a large bespoke payload (hero, correspondence counters, letters, …). **This is the single tightest view coupling.** `[TARGET]`: render through `MediaResolver` and return a package‑owned `heisenberg::public.show` (or a host‑configured view name) — do not depend on the host's `news.show` blade.
- **`BlogCommentModerationController`** (no constructor; operates on models directly): `index` (search/status/scoping), `bulk`, and per‑comment `approve`/`reject`(→trash)/`spam`/`feature`/`editorPick`/`editorReply`/`destroy`. Role guards inline (`feature`/`editorPick`/`editorReply` require admin+; `destroy` requires super_admin).
- **`BlogPostApiController`** (constructor `BlogBlockService`, `BlogBlocksPayloadService`): JSON CRUD; `store`/`update` force/omit status (new posts are always draft), validate blocks → 422, fire `BlogPostSaved`; translation linking (`linkTranslation`/`unlinkTranslation`/`translationStatus`) manage the `translation_group_id`.
- **`BlogPostContentController`** (constructor `BlogBlockService`, `BlogBlocksPayloadService`, `BlogPostStateMachine`, `BlogPostTransitionAction`): the **autosave** endpoint with **optimistic locking** — `DB::transaction` + `lockForUpdate()`, compares client `content_version`; mismatch → **HTTP 409** with `server_version`; else persists, `touch()`, `bumpContentVersion()`, fires `BlogPostSaved`.
- **`BlogPatternApiController`** (constructor `BlogPatternService`): pattern CRUD + `insert` (increments usage, returns blocks); `store`/`update` pre‑check the `share` gate via `Gate::forUser($actor)->allows('share', …)` when `is_shared`.
- **`BlogBlockRegistryController`** (`__invoke`): returns the **raw** registry envelope (`200` or `422` if it has `errors`) — does **not** use the `{success,…}` envelope.
- **`BlogLucideIconController`** (`__invoke`): returns an SVG (`image/svg+xml`, `Cache-Control: public, max-age=86400`) or 404.

## 9.6 `FormatsApiErrors` concern & the JSON envelope

```php
errorResponse(string $message, array $errors = [], int $status = 422, array $extra = []): JsonResponse
// → { "success": false, "message": …, "errors": { "field.path": [...] }, ...$extra }

successResponse(mixed $data = null, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
// → { "success": true, "data": <payload>, "message": … }   (keys omitted when null)
```
`$extra` merges at top level (e.g. autosave conflict adds `server_version`; `updateBlocks` adds `errors_flat`). Port verbatim — it's host‑free and the editor depends on this exact shape.

## 9.7 Form requests (post/blocks/seo)

- **`StoreBlogBuilderPostRequest`** — `authorize`: `can('create', BlogPost::class)`; `withValidator` adds a "title required in at least one locale" error (`blog::validation.title_required_either_locale`); rules cover the full magazine field set; `blocks_json` is a JSON string; **no `status`/`scheduled_at`** (new posts always draft, Rule 07). `slug` unique on `blog_posts`.
- **`UpdateBlogBuilderPostRequest`** — same field set, `slug` unique ignoring current id; adds `status` (`Rule::in` the 5 statuses, routed through the transition action, never written directly) and `scheduled_at` (`required_if:status,scheduled`, `after:now` deliberately *not* enforced at the request layer).
- **`UpdateBlogBlocksRequest`** — `authorize`: `can('update', $post)`; rules: `blocks` present array, `blocks.*.{id?,type,content?,order?}` (coarse — fine validation happens in `persistBlocks`).
- **`UpdateSeoMetaRequest`** — `authorize`: `can('update', $post)`; rules: bilingual title/description, `og_image`, `canonical_url` (url), `robots` (`Rule::in` 4 directives), `schema_type` (`Rule::in` Article/NewsArticle/BlogPosting/WebPage).

---

# Part 10 — Authorization & the Role Map

## 10.1 Policies (`[AS‑BUILT]`)

Four policies under `Modules\Blog\Policies`, all `use App\Models\User`, all `HandlesAuthorization`, registered via `Gate::policy()`.

**`BlogPostPolicy`** abilities (the richest):
| ability | logic |
|---|---|
| `viewAny` | always `true` |
| `view(?User, post)` | published → true (incl. guests); else super_admin/admin → true; else `author_id === user.id`. The only `?User` ability. |
| `create` | `hasAnyRole([super_admin, admin, employee_l1, employee_l2, employee_l3])` |
| `update` | super_admin/admin → true; else owner |
| `delete` | super_admin → true; admin → true; else owner **and** `isDraft()` |
| `submitForReview` | (any blog role) **and** `isDraft()` **and** owner |
| `approve` / `publish` / `schedule` | `hasAnyRole([super_admin, admin])` **and** `isPendingReview()` |
| `archive` | super_admin/admin → true; else owner **and** `isPublished()` |
| `restoreRevision` | super_admin **only** |
| `manageComments` | super_admin/admin → true; else owner |
| `useHtmlRaw` | super_admin **only** (class‑scoped — gates placing an `html_raw` block on any post) |

**`BlogCategoryPolicy`** / **`BlogTagPolicy`** (identical): `viewAny`/`view`/`create` = any blog role; `update`/`delete` = super_admin/admin only.

**`BlogPatternPolicy`** (DRYs the role set into `const BLOG_ROLES`): `viewAny`/`create` = any blog role; `view` = shared OR owner OR admin+; `update`/`delete` = owner OR super_admin (**admin cannot edit others' patterns**); `share` = super_admin only (gates `is_shared = true`).

## 10.2 The complete role map

Roles in the system (every distinct string): `super_admin`, `admin`, `employee_l1`, `employee_l2`, `employee_l3`. ("owner" = `author_id === user.id`. The three employee levels are treated **identically** everywhere — there is no L1/L2/L3 differentiation in any policy.) `client` is never referenced in a blog policy.

| Resource.Ability | super_admin | admin | employee_l* | extra |
|---|---|---|---|---|
| Post.viewAny | ✅ | ✅ | ✅ | always (no auth check) |
| Post.view | ✅ | ✅ | owner | published → public/guest |
| Post.create | ✅ | ✅ | ✅ | |
| Post.update | ✅ | ✅ | owner | |
| Post.delete | ✅ | ✅ | owner+draft | |
| Post.submitForReview | ✅ | ✅ | owner | isDraft() |
| Post.approve/publish/schedule | ✅ | ✅ | ❌ | isPendingReview() |
| Post.archive | ✅ | ✅ | owner+published | |
| Post.restoreRevision | ✅ | ❌ | ❌ | super_admin only |
| Post.manageComments | ✅ | ✅ | owner | |
| Post.useHtmlRaw | ✅ | ❌ | ❌ | super_admin only |
| Category/Tag.viewAny/view/create | ✅ | ✅ | ✅ | |
| Category/Tag.update/delete | ✅ | ✅ | ❌ | |
| Pattern.viewAny/create | ✅ | ✅ | ✅ | |
| Pattern.view | ✅ | ✅ | owner OR shared | |
| Pattern.update/delete | ✅ | owner | owner | admin can't touch others' |
| Pattern.share | ✅ | ❌ | ❌ | super_admin only |

**Privilege tiers (for the config‑driven role map):**
1. **super_admin** — everything; sole holder of `restoreRevision`, `useHtmlRaw`, `pattern.share`, and editing others' patterns.
2. **admin** — full post/taxonomy/comment control + approve/publish/schedule; **not** restoreRevision/useHtmlRaw/pattern.share or editing others' patterns.
3. **employee** — author/own‑content tier: create + edit/submit/delete own drafts; no publish, no taxonomy mutation.

## 10.3 `[TARGET]` Config‑driven role map + RoleGate

The policies are **entirely role‑string based** (no `hasPermission`/`can` — only `hasRole`/`hasAnyRole`). This is the biggest single refactor for standalone. Two moves:

1. **`RoleGate` contract** (§13.3) replaces every `$user->hasRole('x')` / `$user->hasAnyRole([...])` with `$this->roles->is($user, 'super_admin')` / `$this->roles->isAny($user, [...])`. The default adapter maps to Spatie; a host without Spatie binds its own.
2. **Config role map** (§14): `config('heisenberg.roles')` defines named tiers — e.g. `{ admins: ['super_admin','admin'], authors: ['super_admin','admin','employee_l1','employee_l2','employee_l3'], super: ['super_admin'] }`. Policies reference tier names, not literal role strings. A host renames roles freely by editing config; the privilege structure (the three tiers above) is preserved.

The "5‑role set", the admin set, and the super‑only set each become one config tier. `BlogPatternPolicy::BLOG_ROLES` is already a const — it becomes `config('heisenberg.roles.authors')`.

---

# Part 11 — SEO Meta & Localization

## 11.1 SEO meta (`[AS‑BUILT]`)

`SeoMeta` (§2.3.11) is polymorphic (`able_type`/`able_id`) and bilingual; `BlogPost::seoMeta()` is a `MorphOne`. Saved via `BlogBuilderController@saveSeo` (`UpdateSeoMetaRequest`, §9.7) using `updateOrCreate`. `getJsonLd($locale, $url)` builds schema.org JSON‑LD from `schema_type` + `schema_data`. Host‑free; port as a generic SEO‑meta companion (works for any morphable model).

## 11.2 Localization (`[AS‑BUILT]`)

- **Namespace `blog::`** (from `$nameLower = 'blog'`). Three lang files in **both** `en/` and `fr/` (100% parity):
  - `blocks.php` — top‑level keys are the block type identifiers (the de‑facto block manifest: 20 types + a `common` group). Each block has `title`+`description`; richer blocks add `controls.*` (field labels) and `options.*` (enum value labels). `common.color.*` is a shared palette.
  - `comments.php` — ~50 flat keys for the moderation UI (tabs, columns, statuses, reply dialog, actions, confirmations).
  - `validation.php` — a single key `title_required_either_locale`.
- **Bilingual data conventions** (the renderer resolves these, §4.4): per‑field `_en`/`_fr` suffixes on every content column; the registry localizes contract labels via the `blog::blocks.` gate; the renderer additionally supports bare‑locale (`en`/`fr`) and locale‑neutral keys for legacy blocks.

**`[TARGET]`** Namespace → `heisenberg::`; the contract‑label gate keys off `heisenberg::blocks.`. Ship EN+FR defaults; document that a host adds locales by publishing lang dirs. Keep the `_en`/`_fr` suffix convention as the canonical bilingual storage shape — it's simple, index‑friendly, and the renderer already understands it.

---

# Part 12 — Console Commands

## 12.1 `blog:publish-scheduled`
Covered in §7.5 (the scheduled‑publication worker). Scheduled every minute, no‑overlap, background.

## 12.2 `blog:blocks:verify` — the parity sweep

**`[AS‑BUILT]`** `VerifyBlogBlocksCommand` (`blog:blocks:verify {--json}`). Disk/reflection only — never touches the DB. It cross‑checks **three** sets of block‑type identifiers and reports drift:
- **Enum set** — `BlockType::cases()` values.
- **Contract set** — globs the block‑contract JSON dir, reads each `name`, strips the `gtc/` prefix, `-`→`_`.
- **Renderer set** — reflects `BlockRenderer` public/protected `render*` methods, excluding 7 helpers (`renderBlock`, `renderBlocks`, `renderJsonBlock`, `renderBlockPartial`, `renderJsonList`, `renderContractTemplate`, `renderContractTemplateChild`), camel→snake.

It computes six directional diffs (`enum_missing_contract`, `enum_missing_renderer`, `contract_missing_enum`, `contract_missing_renderer`, `renderer_missing_enum`, `renderer_missing_contract`); any non‑empty diff = drift → `FAILURE`. `--json` emits `{ in_sync, sets, diffs }`.

This command is the **guard rail** for the whole block system: it proves the editor (contracts), the storage/validation (enum), and the output (renderer) agree. Today it (correctly) reports drift, because only 9 of 20 types have contracts — that's expected and is the to‑do list for closing the contract gap.

**`[TARGET]`** Rename `heisenberg:blocks:verify`; point the contract glob at the internalized `resources/blocks` dir; use the configured block prefix. Keep it in CI — it's the cheapest possible defence against a block type that's editable but unrenderable (or vice‑versa).

---

# Part 13 — The Decoupling Layer (couplings → contracts)

This is the heart of "standalone from day one." Six host couplings exist in the GTC code; Heisenberg replaces each with a small contract plus a default adapter. Rings 1–2 (the engine and domain) **never name a GTC class** — they depend only on these interfaces.

## 13.1 Identity — `HeisenbergUser` + config

**Coupling:** `App\Models\User`, in ~12 service/policy/event/job sites + 7 FK constraints (full list in Appendix B).

```php
namespace Heisenberg\Contracts;

/** Marker the host's user model implements. Heisenberg only ever needs an id. */
interface HeisenbergUser
{
    public function getAuthIdentifier();   // already on Illuminate Authenticatable
}
```
- Relationships resolve `config('heisenberg.user_model')`; migrations target `config('heisenberg.users_table', 'users')` (§2.6).
- Services type‑hint `HeisenbergUser` (or `Illuminate\Contracts\Auth\Authenticatable`) instead of `App\Models\User`.
- Role questions on the user go through `RoleGate` (§13.3), never a method on the model.

## 13.2 Media — `MediaResolver`

**Coupling:** `App\Models\PublicFile::forUrl(...)->imagePayload(...)` in `BlockRenderer` (§4.8) + `BlogPreviewController` + the file‑picker pagination in `BlogBuilderController@create/edit`.

```php
namespace Heisenberg\Contracts;

interface MediaResolver
{
    /** @return array{url:string, srcset:?string, sizes:?string} */
    public function resolve(string $url, string $context): array;

    /** Optional: paginated media for the editor picker. @return iterable */
    public function browse(int $page = 1, int $perPage = 24): iterable;
}
```
- `NullMediaResolver` (default): `resolve` returns the scheme‑checked raw URL with null srcset/sizes (= today's fallback); `browse` returns `[]`.
- A GTC host binds `PublicFileMediaResolver` wrapping `PublicFile`.

## 13.3 Authorization — `RoleGate`

**Coupling:** `$user->hasRole(...)` / `hasAnyRole(...)` / `getRoleNames()` across policies, the state machine validation, `BlogBlockService::authorizeHtmlRaw`, `BlogPatternService::forActor`, and `PublishScheduledBlogPosts::getSystemActor`.

```php
namespace Heisenberg\Contracts;

interface RoleGate
{
    public function is(Authenticatable $user, string $tier): bool;        // tier from config('heisenberg.roles')
    public function isAny(Authenticatable $user, array $tiers): bool;
    /** @return string[] the user's raw role names (for the transition validator) */
    public function rolesOf(Authenticatable $user): array;
    /** A user with publish authority, for scheduled publication. */
    public function systemActor(): ?Authenticatable;
}
```
- `ConfigRoleGate` (default): resolves tiers from `config('heisenberg.roles')` and answers membership by calling the host's role mechanism — by default Spatie (`hasAnyRole`), but the adapter is swappable.
- This is the contract that lets a host with *any* role system (Spatie, enum column, Gate abilities) drive Heisenberg's authorization without the package knowing how roles are stored.

## 13.4 Audit — `AuditSink`

**Coupling:** the global `activity()->causedBy()->performedOn()->withProperties()->log()` in `LogBlogPostAuditEvent` (Spatie activitylog).

```php
namespace Heisenberg\Contracts;

interface AuditSink
{
    public function record(?Authenticatable $actor, object $subject, array $properties, string $message): void;
}
```
- `NullAuditSink` (default): no‑op — an app without an audit system still transitions posts.
- `SpatieAuditSink`: the current behaviour, bound automatically when `spatie/laravel-activitylog` is installed.

## 13.5 Icons — `IconProvider`

**Coupling:** the hard `base_path('vendor/mallardduck/blade-lucide-icons/resources/svg')` lookup in `BlockRenderer::installedLucideIconSvg` + `BlogBlockRegistryService::lucideIcons/lucideIconSvg`, and the `<x-dynamic-component :component="'lucide-'.$icon"/>` in the cta/button partials.

```php
namespace Heisenberg\Contracts;

interface IconProvider
{
    public function svg(string $name): ?string;   // traversal-safe lookup
    /** @return string[] slugs for the editor picker */
    public function available(): array;
    public function bladeComponent(string $name): string;  // e.g. "lucide-arrow-up-right"
}
```
- `LucideIconProvider` (default, optional dep): wraps the vendor package with the §4.7 traversal‑safe lookup.
- A host without Lucide binds its own (e.g. Heroicons) — the partials call `$icons->bladeComponent($name)` instead of hardcoding `lucide-`.

## 13.6 Host integration surface (views, routes, middleware, namespaces)

Not interfaces but configuration (§14): the view namespace (`heisenberg::`), the block prefix (`heisenberg/`), the CSS prefix (`hb-`), the config/lang namespace, the queue names, the cache‑key prefix, the subdomain/middleware/route‑name conventions for the builder, and the `Post`/`Category`/… model class names. Every GTC literal (`blog::`, `gtc/`, `gtc-block`, `gtc-blog`, `admin.content.*`, `news.show`) maps to one of these knobs.

---

# Part 14 — Configuration Reference (`config/heisenberg.php`)

**`[AS‑BUILT]`** There is **no functional config today** — `Modules/Blog/config/config.php` is the trivial nwidart stub `['name'=>'Blog','alias'=>'blog']`, and the provider references a `gtc-blog.php` that never existed (a silent no‑op). Every "config" value is currently a hardcoded literal inside a service. So the config below is **designed fresh** — it externalizes every literal this blueprint flagged.

**`[AS‑BUILT, 2026‑08‑12]`** `register()` does **not** use `mergeConfigFrom()` — that helper is a SHALLOW `array_merge()`: any top‑level key a host's *published* `config/heisenberg.php` defines at all wins wholesale, so a nested addition inside that same key (a new provider default, a new role ability, a new lifecycle edge) never reaches a host that published before the addition existed. This silently broke a real install three separate times, most visibly: `lifecycle.transitions.draft` kept an old edge list with no `published` target, so no user of any role could publish a post. `register()` instead calls `Heisenberg\Support\ConfigMerge::merge()` — a RECURSIVE merge that walks every key the package ships and fills in only what the host's config is missing, at any depth, never touching a key the host already set. The one honest limit: a LIST (`locales`, `middleware.editor`, `lifecycle.transitions.draft`, …) is merged as one atomic value, never element‑wise — a host that already published the config still has to hand‑edit a list whose *contents* a later package version changed, since "the key exists" is all the merge can see. `php artisan heisenberg:config-diff` (`Heisenberg\Console\Commands\ConfigDiffCommand`) diffs the host's effective config against the package defaults and flags exactly these cases for a human to read. See `ConfigMerge`'s own docblock for the full rule, and the README's "Configuration surface" section for the host‑facing summary.

```php
<?php

return [
    // ── Identity ──────────────────────────────────────────────
    'user_model'   => env('HEISENBERG_USER_MODEL', \App\Models\User::class),
    'users_table'  => 'users',

    // ── Models (host may swap any) ────────────────────────────
    'models' => [
        'post'     => \Heisenberg\Models\Post::class,
        'block'    => \Heisenberg\Models\Block::class,
        'category' => \Heisenberg\Models\Category::class,
        'tag'      => \Heisenberg\Models\Tag::class,
        'comment'  => \Heisenberg\Models\Comment::class,
        'revision' => \Heisenberg\Models\Revision::class,
        'pattern'  => \Heisenberg\Models\Pattern::class,
        'seo_meta' => \Heisenberg\Models\SeoMeta::class,
    ],

    // ── Tables (default heisenberg_ prefix; set to GTC names to migrate in place) ──
    'tables' => [
        'posts' => 'heisenberg_posts', 'blocks' => 'heisenberg_blocks',
        // … one per table; a GTC host sets these back to blog_posts, blog_blocks, …
    ],

    // ── Block engine ──────────────────────────────────────────
    'block_prefix' => 'heisenberg',                                  // contract name namespace
    'block_root'   => null,                                          // null → package resources/blocks
    'css_prefix'   => 'hb',                                          // emitted CSS class/var prefix
    'components'   => [                                              // safe component allowlist (§3.8)
        // 'article_card' => ['blade' => 'heisenberg::components.article-card', 'props' => [...]],
    ],

    // ── Contracts → adapters ──────────────────────────────────
    'media_resolver' => \Heisenberg\Adapters\NullMediaResolver::class,
    'role_gate'      => \Heisenberg\Adapters\ConfigRoleGate::class,
    'audit_sink'     => \Heisenberg\Adapters\NullAuditSink::class,
    'icon_provider'  => \Heisenberg\Adapters\LucideIconProvider::class,

    // ── Authorization role map (tiers, not literal roles) ─────
    // admin/editor/author/viewer are Heisenberg's own canonical role
    // vocabulary; a host remaps its own role names here.
    'roles' => [
        'super'   => ['admin'],
        'admins'  => ['admin'],
        'editors' => ['admin', 'editor'],
        'authors' => ['admin', 'editor', 'author'],
    ],

    // ── Publishing lifecycle ──────────────────────────────────
    'lifecycle' => [
        'transitions' => [
            'draft'          => ['pending_review', 'archived'],
            'pending_review' => ['published', 'scheduled', 'draft'],
            'published'      => ['archived'],
            'scheduled'      => ['published', 'archived', 'draft'],
            'archived'       => ['draft'],
        ],
        'role_permissions' => [                                      // target status → tiers
            'pending_review' => 'authors',
            'published'      => 'editors',   // ← the resolved publish-authority decision (§7.4)
            'scheduled'      => 'editors',
            'archived'       => 'editors',
            'draft'          => 'authors',
        ],
    ],

    // ── Queues / cache / sanitization ─────────────────────────
    'queues' => ['render' => 'default', 'audit' => 'default'],
    'cache_prefix' => 'heisenberg',
    'purifier_cache_path' => storage_path('framework/cache/heisenberg-purifier'),
    'revisions' => ['keep' => null],                                // null = unbounded (as-built)

    // ── Builder HTTP surfaces (replaces route-name string-sniffing) ──
    'surfaces' => [
        'admin' => ['name_prefix' => 'admin.content.', 'domain' => null, 'roles' => 'admins'],
        'staff' => ['name_prefix' => 'staff.content.', 'domain' => null, 'roles' => 'authors'],
    ],
    'middleware' => [
        'api_admin' => ['auth:sanctum', 'verified', 'role:admin'],   // host overrides
        'builder'   => ['web', 'auth'],
    ],
];
```

Every value above traces to a specific as‑built literal documented earlier; the comments mark the load‑bearing decisions (publish authority, table names for in‑place migration, the unbounded‑revisions default).

---

# Part 15 — Rebuild Plan (milestones)

A suggested reconstruction order for the clean repo. Each milestone is independently testable and leaves the package bootable.

**M0 — Package skeleton.** `composer.json`, `HeisenbergServiceProvider`, `config/heisenberg.php`, Testbench harness, the five empty `Contracts/` + their default adapters (Null/Config/Lucide). Boot a Testbench app that registers the provider. *Done when:* `php artisan package:discover` finds Heisenberg and the config publishes.

**M1 — Block engine, ring 1 (no HTTP, no domain).** `BlockType` enum, the 9 internalized contracts + the schema doc, `BlockContractValidator`, `BlockRegistryService`, `BlocksPayloadService`, `HtmlSanitizationService`, `BlockRenderer` (with `MediaResolver`/`IconProvider` contracts), `ComponentRegistry` (config‑driven). Port the `blocks:verify` command. *Done when:* given a blocks array you can render safe bilingual HTML, the parity command runs, and the sanitizer configs match §5 exactly. **Heaviest security review here.**

**M2 — Data model + persistence.** The 11 models (renamed, config‑bound user/tables), the migrations (re‑homed from the host, prefixed), `Block`/`Post` + the cascade soft‑delete/restore, `SlugService`, `BlockService::persistBlocks` (with the `_allow_raw` triple gate + `RoleGate`). *Done when:* you can persist a blocks payload, round‑trip JSON‑first ↔ legacy, and the html_raw gate rejects an unauthorized actor.

**M3 — Domain services.** `TaxonomyService`, `RevisionService` (restore‑via‑persist invariant), `PatternService`, `BuilderService`, SEO meta. *Done when:* taxonomy CRUD, pattern save/validate/insert, and revision snapshot/restore all pass tests against MySQL.

**M4 — Lifecycle.** `PostStatus`, `PostStateMachine` (config tables), `PostTransitionAction` (the pipeline), the three exceptions, the scheduled‑publish command, events/jobs/listeners with `AuditSink` + unified cache busting + render‑on‑publish. *Done when:* every transition is tested for valid/invalid actor + invalid move, and the publish‑authority decision is locked in config and tests.

**M5 — Authorization.** The 4 policies, rewritten against `RoleGate` + config tiers. *Done when:* the role‑map table (§10.2) is reproduced by tests.

**M6 — HTTP surface.** `FormatsApiErrors`, all FormRequests, the JSON API controllers (registry, posts, content/autosave‑with‑optimistic‑lock, patterns, icons), and the opt‑in builder web routes with the configurable surface map. The public controller + a package‑owned `heisenberg::public.*` view (no host `news.show` dependency). *Done when:* the full route table (§9) resolves and the editor's expected JSON envelopes are returned.

**M7 — Polish & docs.** Localization (EN/FR), the publishable views/partials with the CSS prefix, README + the block‑schema doc, and a GTC **migration guide** (set `block_prefix=gtc`, `css_prefix=gtc-block`, table names = `blog_*`, bind `PublicFileMediaResolver` + `SpatieAuditSink` → drop‑in replacement for the current module).

Throughout: **MySQL‑only tests** (SQLite is banned per GTC Rule 11 — enums/FKs/column types differ), TDD per service, and the `blocks:verify` command in CI.

---

# Part 16 — Open Questions & Known Quirks

Carry these into the rebuild; each is a real finding from the source, not a hypothetical.

1. **Publish authority (primary).** Code + policy both allow `admin` to publish; project memory says it's contested. **Decide and encode** in `lifecycle.role_permissions.published` (§7.4). Default: `admins`.
2. **Unconfigured `audit` queue.** `LogBlogPostAuditEvent::onQueue('audit')` names a queue with no matching connection (`critical/default/low/report`). Either configure a worker for it or point `queues.audit` at `default` (§8.5).
3. **Two divergent cache‑key schemes.** `RenderBlogPostJob` and `HandleBlogPostTransitioned` bust **non‑overlapping** key sets — a transition can leave the render job's keys stale and vice‑versa. Unify behind one `CacheBuster` (§8.5).
4. **Render decoupled from transition.** `handle()` never fires `BlogPostSaved`, so publishing doesn't re‑render. A post scheduled and then edited (without a save event) publishes stale HTML. Recommend firing a render on publish (§8.4).
5. **Sitemap + social‑share webhook are stubs.** `regenerateSitemap()` is commented out; the webhook is unimplemented. Drop from the package contract and let hosts subscribe to `PostTransitioned`, or implement behind a contract (§8.5).
6. **`status` is not enum‑cast** on `BlogPost`; the transition action mutates/compares it as a raw string. Cast `status` to `PostStatus` in Heisenberg to end string/enum drift (§2.2).
7. **Enum vs state‑machine duplication.** `isTerminal`/`isPubliclyVisible` exist in both the enum and the state machine (partly with raw strings). Consolidate to the enum.
8. **Only 9 of 20 block types have contracts.** The other 11 render via legacy methods only. `blocks:verify` reports this drift by design. Closing it (writing the missing 11 contracts) is the obvious v2 roadmap; not required for extraction.
9. **`content_version` in `$fillable`.** It must be server‑only (bump via `bumpContentVersion()`); remove from `$fillable` (§2.3.1).
10. **`blog_patterns.blocks` is `longtext`, `blog_blocks.content` is `json`.** Normalize both to `json` (§2.5).
11. **`scheduled` publicness.** The enum says only `published` is public, yet the transition listener busts cache on `scheduled` too — confirm scheduled posts are excluded from public listing queries (the query guard lives outside the listener).
12. **Unbounded revisions.** No retention cap today; `revisions.keep` config added as an opt‑in prune (§6.3).
13. **Preview view coupling.** `BlogPreviewController` returns the host `news.show` blade, not a `blog::` view — the single tightest view coupling. Heisenberg must ship its own preview view (§9.5).

---

# Appendix A — File inventory (old → new mapping)

GTC path (under `Modules/Blog/`) → Heisenberg path (under `src/` unless noted). Rename drops the `Blog` prefix; namespace `Modules\Blog\…` → `Heisenberg\…`.

| GTC | Heisenberg |
|---|---|
| `app/Enums/BlogPostStatus.php` | `Enums/PostStatus.php` |
| `app/Enums/BlockType.php` | `Enums/BlockType.php` |
| `app/Models/BlogPost.php` … `SeoMeta.php` (11) | `Models/{Post,Block,Category,Tag,Revision,Comment,Pattern,ReviewNote,PostLike,PostTocEntry,SeoMeta}.php` |
| `app/Services/BlockRenderer.php` | `Services/BlockRenderer.php` |
| `app/Services/HtmlSanitizationService.php` | `Services/HtmlSanitizationService.php` |
| `app/Services/BlogBlockRegistryService.php` | `Services/BlockRegistryService.php` |
| `app/Services/BlogBlockContractValidator.php` | `Services/BlockContractValidator.php` |
| `app/Services/BlogBlocksPayloadService.php` | `Services/BlocksPayloadService.php` |
| `app/Services/BlogBlockService.php` | `Services/BlockService.php` |
| `app/Services/BlogComponentRegistry.php` | `Services/ComponentRegistry.php` (config‑driven) |
| `app/Services/BlogBuilderService.php` | `Services/BuilderService.php` |
| `app/Services/BlogPatternService.php` | `Services/PatternService.php` |
| `app/Services/BlogRevisionService.php` | `Services/RevisionService.php` |
| `app/Services/TaxonomyService.php` | `Services/TaxonomyService.php` |
| `app/Services/SlugService.php` | `Services/SlugService.php` |
| `app/Services/BlogPostStateMachine.php` | `Services/PostStateMachine.php` (config tables) |
| `app/Actions/BlogPostTransitionAction.php` | `Actions/PostTransitionAction.php` |
| `app/Events/BlogPostSaved.php`, `BlogPostTransitioned.php` | `Events/PostSaved.php`, `PostTransitioned.php` |
| `app/Jobs/RenderBlogPostJob.php`, `LogBlogPostAuditEvent.php` | `Jobs/RenderPostJob.php`, `Jobs/LogPostAuditEvent.php` |
| `app/Listeners/HandleBlogPostSaved.php`, `…Transitioned.php` | `Listeners/…` |
| `app/Policies/Blog{Post,Category,Tag,Pattern}Policy.php` | `Policies/{Post,Category,Tag,Pattern}Policy.php` (RoleGate) |
| `app/Http/Controllers/**` | `Http/Controllers/**` (renamed) |
| `app/Http/Requests/**`, `Concerns/FormatsApiErrors.php` | `Http/Requests/**`, `Http/Concerns/FormatsApiErrors.php` |
| `app/Console/Commands/{PublishScheduledBlogPosts,VerifyBlogBlocksCommand}.php` | `Console/Commands/{PublishScheduledPosts,VerifyBlocksCommand}.php` |
| `resources/lang/{en,fr}/{blocks,comments,validation}.php` | `resources/lang/{en,fr}/…` (namespace `heisenberg::`) |
| `resources/views/builder/blocks/{image,cta,quote,section-head,button}.blade.php` | `resources/views/blocks/…` (CSS prefix configurable) |
| `resources/views/{public,builder}/**` | `resources/views/**` (publishable) |
| **host** `resources/views/components/rm-ui/dashboard/blog-builder/blocks/*/*.json` | `resources/blocks/*/*.json` (**internalized**) |
| **host** `database/migrations/*blog*` | `database/migrations/*` (**re‑homed**, prefixed) |
| `Modules/Blog/config/config.php` (stub) | `config/heisenberg.php` (**new, real config**) |
| **new** | `Contracts/{HeisenbergUser,MediaResolver,RoleGate,AuditSink,IconProvider}.php` + `Adapters/*` |

**Do not port:** `blog_post_bookmarks`, `blog_letter_reactions` tables (dropped, no models); the dropped columns `blog_posts.content_blocks`, `blog_posts.bookmark_count`, `blog_comments.rxn_*_count`.

---

# Appendix B — GTC coupling index

Every host‑app dependency, by category, with the sites that must change. This is the checklist for "is it truly standalone yet?"

**`App\Models\User`** (identity) — `BlogPost::author()/likers()`, `BlogRevision::author()`, `BlogComment::user()/editor()`, `BlogReviewNote::user()`, `BlogPattern::author()` (top‑of‑file import); `BlogBlockService` (persist/normalize/authorizeHtmlRaw), `BlogBuilderService`, `BlogRevisionService`, `BlogPatternService`, `BlogPostTransitionAction` (+ `getRoleNames`), `BlogPostTransitioned::$actor` (+ `->email`), `LogBlogPostAuditEvent::$actor`, `PublishScheduledBlogPosts::getSystemActor`, `BlogPatternApiController::actor()`, all 4 policies. **7 FK constraints** to `users` (`blog_posts.author_id`, `blog_post_revisions.author_id`, `blog_comments.user_id`+`editor_user_id`, `blog_post_likes.user_id`, `blog_review_notes.user_id`, `blog_patterns.author_id`). → §13.1.

**`App\Models\PublicFile`** (media) — `BlockRenderer::responsiveImage`, `BlogPreviewController::presentMediaImage`, `BlogBuilderController@create/edit` (file picker). → §13.2.

**Spatie laravel‑permission** (roles) — `hasRole`/`hasAnyRole`/`getRoleNames` in all policies, `BlogPostStateMachine` tables, `BlogPostTransitionAction`, `BlogBlockService::authorizeHtmlRaw` (via Gate), `BlogPatternService::forActor`, `BlogBuilderService`, `BlogCommentModerationController`, `PublishScheduledBlogPosts`. Role strings: `super_admin, admin, employee_l1, employee_l2, employee_l3`. → §13.3, §10.3.

**Spatie laravel‑activitylog** (audit) — `LogBlogPostAuditEvent::handle`. → §13.4.

**`ezyang/htmlpurifier`** — `HtmlSanitizationService` (hard `require`, kept).

**`mallardduck/blade-lucide-icons`** — `BlockRenderer::installedLucideIconSvg`, `BlogBlockRegistryService::lucideIcons/lucideIconSvg`, cta/button blade partials. → §13.5.

**`Nwidart\Modules`** + `base_path("Modules/Blog/...")` — `BlogServiceProvider`, `RouteServiceProvider`. → §1.3.

**Spatie laravel‑sitemap** — `HandleBlogPostTransitioned::regenerateSitemap` (stubbed). → §8.5, §16.

**Namespaces / literals** — view+lang `blog::`; config `gtc-blog`; block prefix `gtc/`; CSS prefix `gtc-block`; HTMLPurifier id `gtc-blog-raw`; builder route names `admin.content.*`/`staff.content.*`; host preview view `news.show`; subdomain middleware `verified.mfa`, `subdomain.enforce`. → §13.6, §14.

**Host‑owned, outside the module entirely** — the block contracts (host `resources/views/.../blocks/*.json`), the builder web routes (`routes/admin.php`, `routes/staff.php`), and all blog migrations (host `database/migrations`). These must be **found in the host app and brought into the package** (§3.10, §9.1, §2.4).

---

---

# Appendix C — All shipped block contracts (verbatim)

All nine contracts, read byte‑for‑byte from the host app's `resources/views/components/rm-ui/dashboard/blog-builder/blocks/`. These are the **complete, authoritative** block definitions — copy them into Heisenberg's `resources/blocks/` (renaming `gtc/` → the configured prefix and `blog::` → `heisenberg::`). Study them alongside §3.2 (the schema) and §4.3 (the renderer's template walk).

> **Observed cross‑contract patterns** (verify against the verbatim JSON below):
> - **Categories** used: `text` (paragraph, heading, list, quote), `media` (image), `design` (button, cta, separator, section-head). The registry derives the editor's category tabs from these.
> - **Icons** (Lucide slugs): paragraph `pilcrow`, heading `bookmark`, image `image`, button `mouse-pointer-click`, cta `send`, list `list`, separator `minus`, quote `quote`, section-head `bookmark`.
> - **`source` discipline:** rich‑text/text attributes use `source: html|text` + a `selector`; URL/media attributes use `source: attribute` + an `attribute` (`src`/`href`). Pure style/enum attributes (alignment, variant, target…) declare **no** source — they're editor‑state only.
> - **`style.variables` default tokens** are always design tokens (`var(--accent-1)`, `var(--text-md)`, `var(--space-5)`, `var(--radius-1)`) or `transparent`/numerics — never raw hex (validator rule, §3.5).
> - **Editor‑only template nodes:** the image contract's `__picker` button + `data-image-picker` node and the `Select image` text are stripped at render by `isEditorOnlyTemplateNode` (§4.3) — they exist for the editor, never the public page.
> - **`data-lucide` icon nodes** (image picker, button icon, cta icon) are auto‑filled with the inline SVG at render (§4.7) when the node has no children.

## C.1 `gtc/paragraph`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1,
  "name": "gtc/paragraph",
  "title": "blog::blocks.paragraph.title",
  "category": "text",
  "icon": "pilcrow",
  "description": "blog::blocks.paragraph.description",
  "keywords": ["paragraph", "text", "body"],
  "version": "1.0.0",
  "attributes": {
    "content": { "type": "rich-text", "default": "", "source": "html", "selector": ".gtc-block-paragraph__text", "sanitize": "rich-text-block" }
  },
  "supports": {
    "align": ["left", "center", "right"],
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontFamily": true, "fontSize": true, "fontWeight": true, "lineHeight": true, "letterSpacing": true, "textTransform": true },
    "spacing": { "margin": true, "padding": true }
  },
  "controls": [
    { "id": "content", "type": "rich-text", "label": "Text", "attribute": "content", "section": "settings" }
  ],
  "style": {
    "css": "./paragraph.css",
    "className": "gtc-block-paragraph",
    "variables": {
      "--gtc-paragraph-color":      { "source": "supports.color.text",        "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-paragraph-background": { "source": "supports.color.background",   "default": "transparent",     "sanitize": "color-token-or-transparent" },
      "--gtc-paragraph-font-size":  { "source": "supports.typography.fontSize","default": "var(--text-md)",  "sanitize": "size-token" },
      "--gtc-paragraph-align":      { "source": "supports.align",              "default": "left",            "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "p",
      "class": "gtc-block gtc-block-paragraph",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [ { "type": "rich-text", "attribute": "content", "class": "gtc-block-paragraph__text" } ]
    },
    "publicPartial": "blogbuilder.blocks.paragraph",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```
> Note: the paragraph contract on disk declares only the 4 style variables above in the `attributes`‑bearing copy; the full GTC file also carries font‑family/weight/line‑height/letter‑spacing/transform/margin/padding variables matching the heading pattern (C.2). Treat C.2's variable set as the template for any text block.

## C.2 `gtc/heading`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1,
  "name": "gtc/heading",
  "title": "blog::blocks.heading.title",
  "category": "text",
  "icon": "bookmark",
  "description": "blog::blocks.heading.description",
  "keywords": ["heading", "title", "text"],
  "version": "1.0.0",
  "attributes": {
    "content": { "type": "rich-text", "default": "Heading", "source": "html", "selector": ".gtc-block-heading__text", "sanitize": "rich-text-inline" },
    "level":   { "type": "integer", "default": 2, "enum": [1, 2, 3, 4, 5, 6] }
  },
  "supports": {
    "align": ["left", "center", "right"],
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontFamily": true, "fontSize": true, "fontWeight": true, "lineHeight": true, "letterSpacing": true, "textTransform": true },
    "spacing": { "margin": true, "padding": true }
  },
  "controls": [
    { "id": "content", "type": "rich-text", "label": "Text", "attribute": "content", "section": "settings" },
    { "id": "level", "type": "select", "label": "Heading level", "attribute": "level", "section": "settings",
      "options": [ {"label":"H1","value":1}, {"label":"H2","value":2}, {"label":"H3","value":3}, {"label":"H4","value":4}, {"label":"H5","value":5}, {"label":"H6","value":6} ] }
  ],
  "style": {
    "css": "./heading.css",
    "className": "gtc-block-heading",
    "variables": {
      "--gtc-heading-color":          { "source": "supports.color.text",             "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-heading-background":     { "source": "supports.color.background",        "default": "transparent",     "sanitize": "color-token-or-transparent" },
      "--gtc-heading-font-size":      { "source": "supports.typography.fontSize",     "default": "",                "sanitize": "size-token" },
      "--gtc-heading-font-family":    { "source": "supports.typography.fontFamily",   "default": "var(--font-1)",   "sanitize": "text" },
      "--gtc-heading-font-weight":    { "source": "supports.typography.fontWeight",   "default": "700",             "sanitize": "integer" },
      "--gtc-heading-line-height":    { "source": "supports.typography.lineHeight",   "default": "1.15",            "sanitize": "size-token" },
      "--gtc-heading-letter-spacing": { "source": "supports.typography.letterSpacing","default": "0",               "sanitize": "size-token" },
      "--gtc-heading-text-transform": { "source": "supports.typography.textTransform","default": "none",            "sanitize": "text" },
      "--gtc-heading-margin":         { "source": "supports.spacing.margin",          "default": "0",               "sanitize": "size-token" },
      "--gtc-heading-padding":        { "source": "supports.spacing.padding",         "default": "0",               "sanitize": "size-token" },
      "--gtc-heading-align":          { "source": "supports.align",                   "default": "left",            "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "h{{attributes.level}}",
      "class": "gtc-block gtc-block-heading",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [ { "type": "rich-text", "attribute": "content", "class": "gtc-block-heading__text" } ]
    },
    "publicPartial": "blogbuilder.blocks.heading",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```
> Note the dynamic tag `h{{attributes.level}}` — the renderer interpolates `level` (validated 1–6 by the `enum`) into the tag name, then re‑whitelists the resolved tag against `^[a-z][a-z0-9-]*$` (§4.3).

## C.3 `gtc/image`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1,
  "name": "gtc/image",
  "title": "blog::blocks.image.title",
  "category": "media",
  "icon": "image",
  "description": "blog::blocks.image.description",
  "keywords": ["image", "photo", "media", "picture"],
  "version": "1.0.0",
  "attributes": {
    "url":         { "type": "url", "default": "", "source": "attribute", "selector": ".gtc-block-image__image", "attribute": "src", "sanitize": "url" },
    "alt":         { "type": "string", "default": "", "source": "attribute", "selector": ".gtc-block-image__image", "attribute": "alt", "sanitize": "text" },
    "caption":     { "type": "rich-text", "default": "", "source": "html", "selector": ".gtc-block-image__caption-text", "sanitize": "rich-text-inline" },
    "href":        { "type": "url", "default": "", "source": "attribute", "selector": ".gtc-block-image__link", "attribute": "href", "sanitize": "url" },
    "target":      { "type": "string", "default": "_self", "enum": ["_self", "_blank"], "sanitize": "text" },
    "alignment":   { "type": "string", "default": "center", "enum": ["left","center","right","wide","full"], "sanitize": "text" },
    "width":       { "type": "string", "default": "100%", "sanitize": "text" },
    "height":      { "type": "string", "default": "auto", "sanitize": "text" },
    "aspectRatio": { "type": "string", "default": "auto", "sanitize": "text" },
    "scale":       { "type": "string", "default": "cover", "enum": ["cover","contain","fill"], "sanitize": "text" },
    "lightboxEnabled": { "type": "boolean", "default": false, "sanitize": "boolean" }
  },
  "supports": {
    "align": ["left","center","right","wide","full"],
    "spacing": { "margin": true },
    "border": { "color": true, "width": true, "radius": true }
  },
  "controls": [
    { "id": "url", "type": "media", "label": "blog::blocks.image.controls.url", "attribute": "url", "section": "settings" },
    { "id": "alt", "type": "text", "label": "blog::blocks.image.controls.alt", "attribute": "alt", "section": "settings" },
    { "id": "caption", "type": "rich-text", "label": "blog::blocks.image.controls.caption", "attribute": "caption", "section": "settings" },
    { "id": "href", "type": "link", "label": "blog::blocks.image.controls.href", "attribute": "href", "section": "settings" },
    { "id": "target", "type": "select", "label": "blog::blocks.image.controls.target", "attribute": "target", "section": "settings",
      "options": [ {"label":"blog::blocks.image.options.target.self","value":"_self"}, {"label":"blog::blocks.image.options.target.blank","value":"_blank"} ] },
    { "id": "alignment", "type": "select", "label": "blog::blocks.image.controls.alignment", "attribute": "alignment", "section": "settings",
      "options": [ {"label":"blog::blocks.image.options.alignment.left","value":"left"}, {"label":"blog::blocks.image.options.alignment.center","value":"center"}, {"label":"blog::blocks.image.options.alignment.right","value":"right"}, {"label":"blog::blocks.image.options.alignment.wide","value":"wide"}, {"label":"blog::blocks.image.options.alignment.full","value":"full"} ] },
    { "id": "width", "type": "text", "label": "blog::blocks.image.controls.width", "attribute": "width", "section": "settings" },
    { "id": "height", "type": "text", "label": "blog::blocks.image.controls.height", "attribute": "height", "section": "settings" },
    { "id": "aspectRatio", "type": "text", "label": "blog::blocks.image.controls.aspect_ratio", "attribute": "aspectRatio", "section": "settings" },
    { "id": "scale", "type": "select", "label": "blog::blocks.image.controls.scale", "attribute": "scale", "section": "settings",
      "options": [ {"label":"blog::blocks.image.options.scale.cover","value":"cover"}, {"label":"blog::blocks.image.options.scale.contain","value":"contain"}, {"label":"blog::blocks.image.options.scale.fill","value":"fill"} ] },
    { "id": "lightboxEnabled", "type": "toggle", "label": "blog::blocks.image.controls.lightbox_enabled", "attribute": "lightboxEnabled", "section": "settings" }
  ],
  "style": {
    "css": "./image.css",
    "className": "gtc-block-image",
    "variables": {
      "--gtc-image-margin":        { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-image-border-color":  { "source": "supports.border.color", "default": "var(--accent-border)", "sanitize": "color-token" },
      "--gtc-image-border-width":  { "source": "supports.border.width", "default": "0", "sanitize": "size-token" },
      "--gtc-image-border-radius": { "source": "supports.border.radius", "default": "0", "sanitize": "size-token" },
      "--gtc-image-width":         { "source": "attributes.width", "default": "100%", "sanitize": "size-token" },
      "--gtc-image-height":        { "source": "attributes.height", "default": "auto", "sanitize": "size-token" },
      "--gtc-image-aspect-ratio":  { "source": "attributes.aspectRatio", "default": "auto", "sanitize": "text" },
      "--gtc-image-scale":         { "source": "attributes.scale", "default": "cover", "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "figure",
      "class": "gtc-block gtc-block-image gtc-block-image--align-{{attributes.alignment}} gtc-block-image--scale-{{attributes.scale}} gtc-block-image--lightbox-{{attributes.lightboxEnabled}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "element", "tag": "img", "class": "gtc-block-image__image", "attributes": { "src": "{{attributes.url}}", "alt": "{{attributes.alt}}" } },
        { "type": "element", "tag": "button", "class": "gtc-block-image__picker", "attributes": { "type": "button", "data-image-picker": "true" },
          "children": [
            { "type": "element", "tag": "i", "class": "gtc-block-image__picker-icon", "attributes": { "data-lucide": "image", "aria-hidden": "true" } },
            { "type": "text", "content": "Select image" }
          ] },
        { "type": "element", "tag": "figcaption", "class": "gtc-block-image__caption",
          "children": [ { "type": "rich-text", "attribute": "caption", "class": "gtc-block-image__caption-text" } ] }
      ]
    },
    "publicPartial": "blogbuilder.blocks.image",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowedUrlProtocols": ["http","https","mailto","tel"], "allowedMediaSources": ["public_media_library"], "allowCustomCss": false }
}
```

## C.4 `gtc/button`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/button", "title": "blog::blocks.button.title", "category": "design",
  "icon": "mouse-pointer-click", "description": "blog::blocks.button.description",
  "keywords": ["button", "cta", "link", "action"], "version": "1.0.0",
  "attributes": {
    "text":   { "type": "rich-text", "default": "Book a consultation", "source": "html", "selector": ".btn-text", "sanitize": "rich-text-inline" },
    "url":    { "type": "url", "default": "#", "source": "attribute", "selector": ".gtc-block-button__link", "attribute": "href", "sanitize": "url" },
    "variant": { "type": "string", "default": "primary", "enum": ["primary","secondary","danger","link","outline"], "sanitize": "text" },
    "icon":   { "type": "string", "default": "arrow-up-right", "sanitize": "text" },
    "iconPosition": { "type": "string", "default": "right", "enum": ["left","right"], "sanitize": "text" },
    "target": { "type": "string", "default": "_self", "enum": ["_self","_blank"], "sanitize": "text" },
    "fullWidth": { "type": "boolean", "default": false, "sanitize": "boolean" },
    "hoverTextColor": { "type": "string", "default": "", "sanitize": "text" },
    "hoverBackgroundColor": { "type": "string", "default": "", "sanitize": "text" },
    "hoverBorderColor": { "type": "string", "default": "", "sanitize": "text" }
  },
  "supports": {
    "color": { "text": true, "background": true, "custom": false },
    "spacing": { "margin": true, "padding": true },
    "border": { "color": true, "style": true, "width": true, "radius": true },
    "typography": { "fontSize": true, "fontWeight": true }
  },
  "controls": [
    { "id": "text", "type": "rich-text", "label": "blog::blocks.button.controls.text", "attribute": "text", "section": "settings" },
    { "id": "url", "type": "link", "label": "blog::blocks.button.controls.url", "attribute": "url", "section": "settings" },
    { "id": "variant", "type": "select", "label": "blog::blocks.button.controls.variant", "attribute": "variant", "section": "settings",
      "options": [ {"label":"blog::blocks.button.options.variant.primary","value":"primary"}, {"label":"blog::blocks.button.options.variant.secondary","value":"secondary"}, {"label":"blog::blocks.button.options.variant.danger","value":"danger"}, {"label":"blog::blocks.button.options.variant.link","value":"link"}, {"label":"blog::blocks.button.options.variant.outline","value":"outline"} ] },
    { "id": "icon", "type": "text", "label": "blog::blocks.button.controls.icon", "attribute": "icon", "section": "settings" },
    { "id": "iconPosition", "type": "select", "label": "blog::blocks.button.controls.icon_position", "attribute": "iconPosition", "section": "settings",
      "options": [ {"label":"blog::blocks.button.options.icon_position.left","value":"left"}, {"label":"blog::blocks.button.options.icon_position.right","value":"right"} ] },
    { "id": "target", "type": "select", "label": "blog::blocks.button.controls.target", "attribute": "target", "section": "settings",
      "options": [ {"label":"blog::blocks.button.options.target.self","value":"_self"}, {"label":"blog::blocks.button.options.target.blank","value":"_blank"} ] },
    { "id": "fullWidth", "type": "toggle", "label": "blog::blocks.button.controls.full_width", "attribute": "fullWidth", "section": "settings" },
    { "id": "hoverTextColor", "type": "select", "label": "blog::blocks.button.controls.hover_text_color", "attribute": "hoverTextColor", "section": "hover",
      "options": [ {"label":"blog::blocks.button.options.color.default","value":""}, {"label":"blog::blocks.button.options.color.accent_1","value":"var(--accent-1)"}, {"label":"blog::blocks.button.options.color.accent_2","value":"var(--accent-2)"}, {"label":"blog::blocks.button.options.color.accent_3","value":"var(--accent-3)"}, {"label":"blog::blocks.button.options.color.accent_4","value":"var(--accent-4)"}, {"label":"blog::blocks.button.options.color.accent_6","value":"var(--accent-6)"}, {"label":"blog::blocks.button.options.color.muted","value":"var(--accent-muted)"} ] },
    { "id": "hoverBackgroundColor", "type": "select", "label": "blog::blocks.button.controls.hover_background_color", "attribute": "hoverBackgroundColor", "section": "hover",
      "options": [ {"label":"blog::blocks.button.options.color.default","value":""}, {"label":"blog::blocks.button.options.color.accent_1","value":"var(--accent-1)"}, {"label":"blog::blocks.button.options.color.accent_2","value":"var(--accent-2)"}, {"label":"blog::blocks.button.options.color.accent_3","value":"var(--accent-3)"}, {"label":"blog::blocks.button.options.color.accent_4","value":"var(--accent-4)"}, {"label":"blog::blocks.button.options.color.accent_6","value":"var(--accent-6)"}, {"label":"blog::blocks.button.options.color.danger","value":"var(--accent-danger)"}, {"label":"blog::blocks.button.options.color.transparent","value":"transparent"} ] },
    { "id": "hoverBorderColor", "type": "select", "label": "blog::blocks.button.controls.hover_border_color", "attribute": "hoverBorderColor", "section": "hover",
      "options": [ {"label":"blog::blocks.button.options.color.default","value":""}, {"label":"blog::blocks.button.options.color.accent_1","value":"var(--accent-1)"}, {"label":"blog::blocks.button.options.color.accent_2","value":"var(--accent-2)"}, {"label":"blog::blocks.button.options.color.accent_3","value":"var(--accent-3)"}, {"label":"blog::blocks.button.options.color.accent_4","value":"var(--accent-4)"}, {"label":"blog::blocks.button.options.color.accent_6","value":"var(--accent-6)"}, {"label":"blog::blocks.button.options.color.muted","value":"var(--accent-muted)"} ] }
  ],
  "style": {
    "css": "./button.css", "className": "gtc-block-button",
    "variables": {
      "--gtc-button-color":             { "source": "supports.color.text", "default": "", "sanitize": "color-token" },
      "--gtc-button-background":        { "source": "supports.color.background", "default": "", "sanitize": "color-token-or-transparent" },
      "--gtc-button-margin":            { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-button-padding":           { "source": "supports.spacing.padding", "default": "0", "sanitize": "size-token" },
      "--gtc-button-border-color":      { "source": "supports.border.color", "default": "", "sanitize": "color-token" },
      "--gtc-button-border-style":      { "source": "supports.border.style", "default": "", "sanitize": "text" },
      "--gtc-button-border-width":      { "source": "supports.border.width", "default": "", "sanitize": "size-token" },
      "--gtc-button-border-radius":     { "source": "supports.border.radius", "default": "var(--radius-2)", "sanitize": "size-token" },
      "--gtc-button-font-size":         { "source": "supports.typography.fontSize", "default": "var(--text-xs)", "sanitize": "size-token" },
      "--gtc-button-font-weight":       { "source": "supports.typography.fontWeight", "default": "400", "sanitize": "integer" },
      "--gtc-button-hover-color":       { "source": "attributes.hoverTextColor", "default": "", "sanitize": "color-token" },
      "--gtc-button-hover-background":  { "source": "attributes.hoverBackgroundColor", "default": "", "sanitize": "color-token-or-transparent" },
      "--gtc-button-hover-border-color":{ "source": "attributes.hoverBorderColor", "default": "", "sanitize": "color-token" }
    }
  },
  "render": {
    "template": {
      "tag": "div",
      "class": "gtc-block gtc-block-button gtc-block-button--{{attributes.variant}} gtc-block-button--icon-{{attributes.iconPosition}} gtc-block-button--full-{{attributes.fullWidth}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "element", "tag": "a", "class": "btn btn-{{attributes.variant}} gtc-block-button__link", "attributes": { "href": "{{attributes.url}}", "target": "{{attributes.target}}" },
          "children": [
            { "type": "rich-text", "attribute": "text", "class": "btn-text" },
            { "type": "element", "tag": "span", "class": "btn-icon-frame",
              "children": [ { "type": "element", "tag": "i", "class": "gtc-block-button__icon", "attributes": { "data-lucide": "{{attributes.icon}}", "aria-hidden": "true" }, "children": [ { "type": "text", "content": "" } ] } ] }
          ] }
      ]
    },
    "publicPartial": "blogbuilder.blocks.button",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowedUrlProtocols": ["http","https","mailto","tel"], "allowCustomCss": false }
}
```

## C.5 `gtc/cta`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/cta", "title": "blog::blocks.cta.title", "category": "design",
  "icon": "send", "description": "blog::blocks.cta.description",
  "keywords": ["cta", "call to action", "banner", "button"], "version": "1.0.0",
  "attributes": {
    "heading":    { "type": "rich-text", "default": "Ready to plan your next trip?", "source": "html", "selector": ".gtc-block-cta__heading-text", "sanitize": "rich-text-inline" },
    "subheading": { "type": "string", "default": "Talk to a Global Traveling Consultant specialist for tailored guidance.", "source": "text", "selector": ".gtc-block-cta__subheading", "sanitize": "text" },
    "buttonText": { "type": "string", "default": "Book a consultation", "source": "text", "selector": ".gtc-block-cta__button .btn-text", "sanitize": "text" },
    "buttonUrl":  { "type": "url", "default": "#", "source": "attribute", "selector": ".gtc-block-cta__button", "attribute": "href", "sanitize": "url" },
    "buttonIcon": { "type": "string", "default": "arrow-up-right", "sanitize": "text" },
    "variant":    { "type": "string", "default": "default", "enum": ["default","accent","muted"], "sanitize": "text" },
    "alignment":  { "type": "string", "default": "center", "enum": ["left","center","right"], "sanitize": "text" }
  },
  "supports": {
    "color": { "text": true, "background": true, "custom": false },
    "spacing": { "margin": true, "padding": true },
    "border": { "radius": true },
    "typography": { "fontSize": true, "fontWeight": true }
  },
  "controls": [
    { "id": "heading", "type": "rich-text", "label": "blog::blocks.cta.controls.heading", "attribute": "heading", "section": "settings" },
    { "id": "subheading", "type": "textarea", "label": "blog::blocks.cta.controls.subheading", "attribute": "subheading", "section": "settings" },
    { "id": "buttonText", "type": "text", "label": "blog::blocks.cta.controls.button_text", "attribute": "buttonText", "section": "settings" },
    { "id": "buttonUrl", "type": "link", "label": "blog::blocks.cta.controls.button_url", "attribute": "buttonUrl", "section": "settings" },
    { "id": "buttonIcon", "type": "text", "label": "blog::blocks.cta.controls.button_icon", "attribute": "buttonIcon", "section": "settings" },
    { "id": "variant", "type": "select", "label": "blog::blocks.cta.controls.variant", "attribute": "variant", "section": "settings",
      "options": [ {"label":"blog::blocks.cta.options.variant.default","value":"default"}, {"label":"blog::blocks.cta.options.variant.accent","value":"accent"}, {"label":"blog::blocks.cta.options.variant.muted","value":"muted"} ] },
    { "id": "alignment", "type": "select", "label": "blog::blocks.cta.controls.alignment", "attribute": "alignment", "section": "settings",
      "options": [ {"label":"blog::blocks.cta.options.alignment.left","value":"left"}, {"label":"blog::blocks.cta.options.alignment.center","value":"center"}, {"label":"blog::blocks.cta.options.alignment.right","value":"right"} ] }
  ],
  "style": {
    "css": "./cta.css", "className": "gtc-block-cta",
    "variables": {
      "--gtc-cta-color":          { "source": "supports.color.text", "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-cta-background":     { "source": "supports.color.background", "default": "var(--accent-surface)", "sanitize": "color-token-or-transparent" },
      "--gtc-cta-margin":         { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-cta-padding":        { "source": "supports.spacing.padding", "default": "var(--space-6)", "sanitize": "size-token" },
      "--gtc-cta-radius":         { "source": "supports.border.radius", "default": "var(--radius-1)", "sanitize": "size-token" },
      "--gtc-cta-heading-size":   { "source": "supports.typography.fontSize", "default": "var(--text-4xl)", "sanitize": "size-token" },
      "--gtc-cta-heading-weight": { "source": "supports.typography.fontWeight", "default": "700", "sanitize": "integer" }
    }
  },
  "render": {
    "template": {
      "tag": "section",
      "class": "gtc-block gtc-block-cta gtc-block-cta--{{attributes.variant}} gtc-block-cta--align-{{attributes.alignment}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "element", "tag": "div", "class": "gtc-block-cta__content",
          "children": [
            { "type": "element", "tag": "h2", "class": "gtc-block-cta__heading",
              "children": [ { "type": "rich-text", "attribute": "heading", "class": "gtc-block-cta__heading-text" } ] },
            { "type": "element", "tag": "p", "class": "gtc-block-cta__subheading",
              "children": [ { "type": "text", "content": "{{attributes.subheading}}" } ] }
          ] },
        { "type": "element", "tag": "a", "class": "btn btn-primary gtc-block-cta__button", "attributes": { "href": "{{attributes.buttonUrl}}" },
          "children": [
            { "type": "element", "tag": "span", "class": "btn-text", "children": [ { "type": "text", "content": "{{attributes.buttonText}}" } ] },
            { "type": "element", "tag": "span", "class": "btn-icon-frame",
              "children": [ { "type": "element", "tag": "i", "attributes": { "data-lucide": "{{attributes.buttonIcon}}", "aria-hidden": "true" } } ] }
          ] }
      ]
    },
    "publicPartial": "blogbuilder.blocks.cta",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowedUrlProtocols": ["http","https","mailto","tel"], "allowCustomCss": false }
}
```

## C.6 `gtc/list`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/list", "title": "blog::blocks.list.title", "category": "text",
  "icon": "list", "description": "blog::blocks.list.description",
  "keywords": ["list", "bullets", "numbered", "checkmark"], "version": "1.0.0",
  "attributes": {
    "content":  { "type": "rich-text", "default": "First item\nSecond item\nThird item", "source": "html", "selector": ".gtc-block-list__content", "sanitize": "rich-text-block" },
    "ordered":  { "type": "boolean", "default": false, "sanitize": "boolean" },
    "start":    { "type": "integer", "default": 1, "sanitize": "integer" },
    "reversed": { "type": "boolean", "default": false, "sanitize": "boolean" },
    "style":    { "type": "string", "default": "default", "enum": ["default","checkmark"], "sanitize": "text" }
  },
  "supports": {
    "color": { "text": true, "background": true, "custom": false },
    "spacing": { "margin": true, "padding": true },
    "typography": { "fontSize": true, "fontWeight": true, "lineHeight": true }
  },
  "controls": [
    { "id": "content", "type": "rich-text", "label": "blog::blocks.list.controls.content", "attribute": "content", "section": "settings" },
    { "id": "ordered", "type": "toggle", "label": "blog::blocks.list.controls.ordered", "attribute": "ordered", "section": "settings" },
    { "id": "start", "type": "number", "label": "blog::blocks.list.controls.start", "attribute": "start", "section": "settings", "min": 1, "step": 1 },
    { "id": "reversed", "type": "toggle", "label": "blog::blocks.list.controls.reversed", "attribute": "reversed", "section": "settings" },
    { "id": "style", "type": "select", "label": "blog::blocks.list.controls.style", "attribute": "style", "section": "settings",
      "options": [ {"label":"blog::blocks.list.options.style.default","value":"default"}, {"label":"blog::blocks.list.options.style.checkmark","value":"checkmark"} ] }
  ],
  "style": {
    "css": "./list.css", "className": "gtc-block-list",
    "variables": {
      "--gtc-list-color":       { "source": "supports.color.text", "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-list-background":  { "source": "supports.color.background", "default": "transparent", "sanitize": "color-token-or-transparent" },
      "--gtc-list-margin":      { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-list-padding":     { "source": "supports.spacing.padding", "default": "0", "sanitize": "size-token" },
      "--gtc-list-font-size":   { "source": "supports.typography.fontSize", "default": "var(--text-md)", "sanitize": "size-token" },
      "--gtc-list-font-weight": { "source": "supports.typography.fontWeight", "default": "400", "sanitize": "integer" },
      "--gtc-list-line-height": { "source": "supports.typography.lineHeight", "default": "1.65", "sanitize": "size-token" }
    }
  },
  "render": {
    "template": {
      "tag": "div",
      "class": "gtc-block gtc-block-list gtc-block-list--ordered-{{attributes.ordered}} gtc-block-list--reversed-{{attributes.reversed}} gtc-block-list--{{attributes.style}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [ { "type": "rich-text", "attribute": "content", "class": "gtc-block-list__content" } ]
    },
    "publicPartial": "blogbuilder.blocks.list",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```
> The list block stores its items as a single newline‑delimited rich‑text `content`; the renderer's legacy `renderJsonList` splits on `\R+` and re‑emits `<ul>`/`<ol>` (§4.2). `ordered`/`start`/`reversed`/`style` drive the list semantics.

## C.7 `gtc/quote`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/quote", "title": "blog::blocks.quote.title", "category": "text",
  "icon": "quote", "description": "blog::blocks.quote.description",
  "keywords": ["quote", "blockquote", "citation"], "version": "1.0.0",
  "attributes": {
    "content":  { "type": "rich-text", "default": "Travel changes the way you see the world.", "source": "html", "selector": ".gtc-block-quote__content-text", "sanitize": "rich-text-block" },
    "citation": { "type": "rich-text", "default": "Global Traveling Consultant", "source": "html", "selector": ".gtc-block-quote__citation-text", "sanitize": "rich-text-inline" },
    "style":    { "type": "string", "default": "default", "enum": ["default","plain"], "sanitize": "text" }
  },
  "supports": {
    "align": ["left","right","wide","full"],
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontSize": true, "fontWeight": true, "lineHeight": true },
    "spacing": { "margin": true, "padding": true, "gap": true },
    "border": { "color": true, "radius": true, "style": true, "width": true }
  },
  "controls": [
    { "id": "content", "type": "rich-text", "label": "blog::blocks.quote.controls.content", "attribute": "content", "section": "settings" },
    { "id": "citation", "type": "rich-text", "label": "blog::blocks.quote.controls.citation", "attribute": "citation", "section": "settings" },
    { "id": "style", "type": "select", "label": "blog::blocks.quote.controls.style", "attribute": "style", "section": "settings",
      "options": [ {"label":"blog::blocks.quote.options.style.default","value":"default"}, {"label":"blog::blocks.quote.options.style.plain","value":"plain"} ] }
  ],
  "style": {
    "css": "./quote.css", "className": "gtc-block-quote",
    "variables": {
      "--gtc-quote-color":         { "source": "supports.color.text", "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-quote-background":    { "source": "supports.color.background", "default": "var(--accent-surface)", "sanitize": "color-token-or-transparent" },
      "--gtc-quote-margin":        { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-quote-padding":       { "source": "supports.spacing.padding", "default": "var(--space-5)", "sanitize": "size-token" },
      "--gtc-quote-gap":           { "source": "supports.spacing.gap", "default": "var(--space-3)", "sanitize": "size-token" },
      "--gtc-quote-font-size":     { "source": "supports.typography.fontSize", "default": "var(--text-xl)", "sanitize": "size-token" },
      "--gtc-quote-font-weight":   { "source": "supports.typography.fontWeight", "default": "500", "sanitize": "integer" },
      "--gtc-quote-line-height":   { "source": "supports.typography.lineHeight", "default": "1.4", "sanitize": "size-token" },
      "--gtc-quote-border-color":  { "source": "supports.border.color", "default": "var(--accent-2)", "sanitize": "color-token" },
      "--gtc-quote-border-radius": { "source": "supports.border.radius", "default": "var(--radius-1)", "sanitize": "size-token" },
      "--gtc-quote-border-style":  { "source": "supports.border.style", "default": "solid", "sanitize": "text" },
      "--gtc-quote-border-width":  { "source": "supports.border.width", "default": "0", "sanitize": "size-token" },
      "--gtc-quote-align":         { "source": "supports.align", "default": "left", "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "blockquote",
      "class": "gtc-block gtc-block-quote gtc-block-quote--{{attributes.style}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "element", "tag": "p", "class": "gtc-block-quote__content",
          "children": [ { "type": "rich-text", "attribute": "content", "class": "gtc-block-quote__content-text" } ] },
        { "type": "element", "tag": "cite", "class": "gtc-block-quote__citation",
          "children": [ { "type": "rich-text", "attribute": "citation", "class": "gtc-block-quote__citation-text" } ] }
      ]
    },
    "publicPartial": "blogbuilder.blocks.quote",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```

## C.8 `gtc/separator`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/separator", "title": "blog::blocks.separator.title", "category": "design",
  "icon": "minus", "description": "blog::blocks.separator.description",
  "keywords": ["separator", "divider", "line", "dots"], "version": "1.0.0",
  "attributes": {
    "opacity":   { "type": "string", "default": "alpha-channel", "enum": ["default","alpha-channel"], "sanitize": "text" },
    "color":     { "type": "string", "default": "var(--accent-border)", "sanitize": "text" },
    "thickness": { "type": "string", "default": "1px", "sanitize": "text" },
    "width":     { "type": "string", "default": "100%", "sanitize": "text" },
    "style":     { "type": "string", "default": "default", "enum": ["default","wide-line","dots"], "sanitize": "text" }
  },
  "supports": {
    "align": ["center","wide","full"],
    "color": { "background": true, "custom": false },
    "spacing": { "margin": true }
  },
  "controls": [
    { "id": "style", "type": "select", "label": "blog::blocks.separator.controls.style", "attribute": "style", "section": "settings",
      "options": [ {"label":"blog::blocks.separator.options.style.default","value":"default"}, {"label":"blog::blocks.separator.options.style.wide_line","value":"wide-line"}, {"label":"blog::blocks.separator.options.style.dots","value":"dots"} ] },
    { "id": "opacity", "type": "select", "label": "blog::blocks.separator.controls.opacity", "attribute": "opacity", "section": "settings",
      "options": [ {"label":"blog::blocks.separator.options.opacity.default","value":"default"}, {"label":"blog::blocks.separator.options.opacity.alpha_channel","value":"alpha-channel"} ] },
    { "id": "color", "type": "select", "label": "blog::blocks.separator.controls.color", "attribute": "color", "section": "settings",
      "options": [ {"label":"blog::blocks.common.color.default","value":"var(--accent-border)"}, {"label":"blog::blocks.common.color.accent_1","value":"var(--accent-1)"}, {"label":"blog::blocks.common.color.accent_2","value":"var(--accent-2)"}, {"label":"blog::blocks.common.color.accent_3","value":"var(--accent-3)"}, {"label":"blog::blocks.common.color.accent_4","value":"var(--accent-4)"}, {"label":"blog::blocks.common.color.muted","value":"var(--accent-muted)"} ] },
    { "id": "thickness", "type": "text", "label": "blog::blocks.separator.controls.thickness", "attribute": "thickness", "section": "settings" },
    { "id": "width", "type": "text", "label": "blog::blocks.separator.controls.width", "attribute": "width", "section": "settings" }
  ],
  "style": {
    "css": "./separator.css", "className": "gtc-block-separator",
    "variables": {
      "--gtc-separator-color":      { "source": "attributes.color", "default": "var(--accent-border)", "sanitize": "color-token" },
      "--gtc-separator-thickness":  { "source": "attributes.thickness", "default": "1px", "sanitize": "size-token" },
      "--gtc-separator-width":      { "source": "attributes.width", "default": "100%", "sanitize": "size-token" },
      "--gtc-separator-margin":     { "source": "supports.spacing.margin", "default": "var(--space-5)", "sanitize": "size-token" },
      "--gtc-separator-background": { "source": "supports.color.background", "default": "var(--accent-border)", "sanitize": "color-token-or-transparent" },
      "--gtc-separator-align":      { "source": "supports.align", "default": "center", "sanitize": "text" }
    }
  },
  "render": {
    "template": {
      "tag": "div",
      "class": "gtc-block gtc-block-separator gtc-block-separator--{{attributes.style}} gtc-block-separator--opacity-{{attributes.opacity}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}", "aria-hidden": "true" },
      "children": [ { "type": "element", "tag": "span", "class": "gtc-block-separator__line" } ]
    },
    "publicPartial": "blogbuilder.blocks.separator",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```
> The separator is the one contract whose style variables read entirely from `attributes.*` (color/thickness/width) rather than `supports.*` — it has no text content, so its "content" *is* its styling.

## C.9 `gtc/section-head`

```json
{
  "$schema": "../../../../../../../../docs/control/staff-admin-blog-builder-block-schema.md",
  "apiVersion": 1, "name": "gtc/section-head", "title": "blog::blocks.section_head.title", "category": "design",
  "icon": "bookmark", "description": "blog::blocks.section_head.description",
  "keywords": ["section", "heading", "label", "divider"], "version": "1.0.0",
  "attributes": {
    "label":   { "type": "string", "default": "KEY TAKEAWAY", "source": "text", "selector": ".gtc-block-section-head__label", "sanitize": "text" },
    "heading": { "type": "rich-text", "default": "What you need to know", "source": "html", "selector": ".gtc-block-section-head__heading-text", "sanitize": "rich-text-inline" },
    "style":   { "type": "string", "default": "default", "enum": ["default","accent","muted"], "sanitize": "text" }
  },
  "supports": {
    "color": { "text": true, "background": true, "custom": false },
    "typography": { "fontSize": true, "fontWeight": true, "textTransform": true },
    "spacing": { "margin": true, "padding": true },
    "border": { "color": true, "style": true, "width": true, "radius": true }
  },
  "controls": [
    { "id": "label", "type": "text", "label": "blog::blocks.section_head.controls.label", "attribute": "label", "section": "settings" },
    { "id": "heading", "type": "rich-text", "label": "blog::blocks.section_head.controls.heading", "attribute": "heading", "section": "settings" },
    { "id": "style", "type": "select", "label": "blog::blocks.section_head.controls.style", "attribute": "style", "section": "settings",
      "options": [ {"label":"blog::blocks.section_head.options.style.default","value":"default"}, {"label":"blog::blocks.section_head.options.style.accent","value":"accent"}, {"label":"blog::blocks.section_head.options.style.muted","value":"muted"} ] }
  ],
  "style": {
    "css": "./section-head.css", "className": "gtc-block-section-head",
    "variables": {
      "--gtc-section-head-color":           { "source": "supports.color.text", "default": "var(--accent-1)", "sanitize": "color-token" },
      "--gtc-section-head-background":      { "source": "supports.color.background", "default": "transparent", "sanitize": "color-token-or-transparent" },
      "--gtc-section-head-font-size":       { "source": "supports.typography.fontSize", "default": "var(--text-3xl)", "sanitize": "size-token" },
      "--gtc-section-head-font-weight":     { "source": "supports.typography.fontWeight", "default": "700", "sanitize": "integer" },
      "--gtc-section-head-label-transform": { "source": "supports.typography.textTransform", "default": "uppercase", "sanitize": "text" },
      "--gtc-section-head-margin":          { "source": "supports.spacing.margin", "default": "0", "sanitize": "size-token" },
      "--gtc-section-head-padding":         { "source": "supports.spacing.padding", "default": "var(--space-5)", "sanitize": "size-token" },
      "--gtc-section-head-border-color":    { "source": "supports.border.color", "default": "var(--accent-border)", "sanitize": "color-token" },
      "--gtc-section-head-border-style":    { "source": "supports.border.style", "default": "solid", "sanitize": "text" },
      "--gtc-section-head-border-width":    { "source": "supports.border.width", "default": "1px", "sanitize": "size-token" },
      "--gtc-section-head-border-radius":   { "source": "supports.border.radius", "default": "var(--radius-1)", "sanitize": "size-token" }
    }
  },
  "render": {
    "template": {
      "tag": "section",
      "class": "gtc-block gtc-block-section-head gtc-block-section-head--{{attributes.style}}",
      "attributes": { "data-block-name": "{{name}}", "data-block-id": "{{id}}" },
      "children": [
        { "type": "element", "tag": "span", "class": "gtc-block-section-head__label",
          "children": [ { "type": "text", "content": "{{attributes.label}}" } ] },
        { "type": "element", "tag": "h2", "class": "gtc-block-section-head__heading",
          "children": [ { "type": "rich-text", "attribute": "heading", "class": "gtc-block-section-head__heading-text" } ] }
      ]
    },
    "publicPartial": "blogbuilder.blocks.section-head",
    "script": null
  },
  "innerBlocks": { "enabled": false },
  "serialization": { "mode": "json", "saveAttributes": true, "saveSupports": true, "saveInnerBlocks": true, "migrations": [] },
  "security": { "richText": "inline-basic", "allowRawHtml": false, "allowCustomCss": false }
}
```

---

# Appendix D — Render partials (verbatim)

The five blade partials the renderer resolves via `renderBlockPartial('<name>', …)` (§4.2). They are the public‑page output for the `gtc/image|cta|quote|section-head|button` blocks. Each does its **own** defensive sanitization (independent of the contract `sanitize` tokens) — note the per‑partial scheme checks, enum clamps, and the `{{ }}` auto‑escaping. Port these into `resources/views/blocks/` with the configured CSS prefix.

## D.1 `image.blade.php`

```blade
@php
    $data = is_array($attributes ?? null) ? $attributes : [];

    $isSafeUrl = static function (mixed $value, bool $allowContact = false): bool {
        $value = trim((string) $value);
        if ($value === '') return false;
        if (preg_match('/^\/(?!\/)/', $value) === 1 || preg_match('/^https?:\/\//i', $value) === 1) return true;
        return $allowContact && preg_match('/^(mailto|tel):/i', $value) === 1;
    };
    $url = $isSafeUrl($data['url'] ?? '') ? trim((string) $data['url']) : '';
    $alt = trim((string) ($data['alt'] ?? ''));
    $caption = trim((string) ($data['caption'] ?? ''));
    $href = $isSafeUrl($data['href'] ?? '', true) ? trim((string) $data['href']) : '';
    $target = in_array(($data['target'] ?? '_self'), ['_self', '_blank'], true) ? $data['target'] : '_self';
    $alignment = in_array(($data['alignment'] ?? 'center'), ['left', 'center', 'right', 'wide', 'full'], true) ? $data['alignment'] : 'center';
    $scale = in_array(($data['scale'] ?? 'cover'), ['cover', 'contain', 'fill'], true) ? $data['scale'] : 'cover';
    $lightboxEnabled = filter_var($data['lightboxEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $isSafeSize = static fn (string $value): bool => $value === '' || preg_match('/^(0|auto|100%|-?\d+(\.\d+)?(px|rem|em|%)?)$/', $value);
    $isSafeAspectRatio = static fn (string $value): bool => $value === '' || $value === 'auto' || preg_match('/^\d+(\.\d+)?\s*\/\s*\d+(\.\d+)?$/', $value);

    $width = trim((string) ($data['width'] ?? '100%'));
    $height = trim((string) ($data['height'] ?? 'auto'));
    $aspectRatio = trim((string) ($data['aspectRatio'] ?? 'auto'));

    $style = [];
    if ($isSafeSize($width) && $width !== '') { $style[] = '--gtc-image-width: ' . $width; }
    if ($isSafeSize($height) && $height !== '') { $style[] = '--gtc-image-height: ' . $height; }
    if ($isSafeAspectRatio($aspectRatio) && $aspectRatio !== '') { $style[] = '--gtc-image-aspect-ratio: ' . $aspectRatio; }
    $style[] = '--gtc-image-scale: ' . $scale;
    $captionHtml = nl2br(e($caption));
@endphp

@if($url)
    <figure class="gtc-block gtc-block-image gtc-block-image--align-{{ $alignment }} gtc-block-image--scale-{{ $scale }} gtc-block-image--lightbox-{{ $lightboxEnabled ? 'true' : 'false' }}" @if($style) style="{{ implode('; ', $style) }}" @endif>
        @if($href)
            <a href="{{ e($href) }}" class="gtc-block-image__link" target="{{ $target }}" @if($target === '_blank') rel="noopener noreferrer" @endif>
        @endif
        <img src="{{ e($url) }}" alt="{{ e($alt) }}" class="gtc-block-image__image" loading="lazy" decoding="async">
        @if($href)</a>@endif
        @if($caption !== '')
            <figcaption class="gtc-block-image__caption">
                <span class="gtc-block-image__caption-text">{!! $captionHtml !!}</span>
            </figcaption>
        @endif
    </figure>
@endif
```
**Safety notes:** the entire block is suppressed when `url` is empty/unsafe; `href` allows contact schemes, `url` does not; `target` clamped to `_self`/`_blank`; sizes/aspect‑ratio validated by regex before becoming CSS vars; the caption is the **only** `{!! !!}` (raw) output and is pre‑escaped via `nl2br(e($caption))`.

## D.2 `cta.blade.php`

```blade
@php
    $data = is_array($attributes ?? null) ? $attributes : [];
    $heading = trim(strip_tags((string) ($data['heading'] ?? 'Ready to plan your next trip?'))) ?: 'Ready to plan your next trip?';
    $subheading = trim((string) ($data['subheading'] ?? ''));
    $buttonText = trim((string) ($data['buttonText'] ?? 'Book a consultation')) ?: 'Book a consultation';
    $buttonUrl = trim((string) ($data['buttonUrl'] ?? '#'));
    $scheme = parse_url($buttonUrl, PHP_URL_SCHEME);
    $buttonUrl = $buttonUrl !== '' && (! $scheme || in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true)) ? $buttonUrl : '#';
    $buttonIcon = preg_match('/^[a-z0-9-]+$/', (string) ($data['buttonIcon'] ?? 'arrow-up-right')) ? $data['buttonIcon'] : 'arrow-up-right';
    $variant = in_array(($data['variant'] ?? 'default'), ['default', 'accent', 'muted'], true) ? $data['variant'] : 'default';
    $alignment = in_array(($data['alignment'] ?? 'center'), ['left', 'center', 'right'], true) ? $data['alignment'] : 'center';
@endphp

<section class="gtc-block gtc-block-cta gtc-block-cta--{{ $variant }} gtc-block-cta--align-{{ $alignment }}">
    <div class="gtc-block-cta__content">
        <h2 class="gtc-block-cta__heading">
            <span class="gtc-block-cta__heading-text">{{ $heading }}</span>
        </h2>
        @if($subheading !== '')
            <p class="gtc-block-cta__subheading">{{ $subheading }}</p>
        @endif
    </div>
    <a href="{{ $buttonUrl }}" class="btn btn-primary gtc-block-cta__button">
        <span class="btn-text">{{ $buttonText }}</span>
        <span class="btn-icon-frame" aria-hidden="true">
            <x-dynamic-component :component="'lucide-' . $buttonIcon" class="size-4" />
        </span>
    </a>
</section>
```
**Lucide coupling:** `<x-dynamic-component :component="'lucide-' . $buttonIcon" …/>` — `$buttonIcon` regex‑restricted to `[a-z0-9-]+` so the dynamic component name can't be poisoned. `[TARGET]`: `$icons->bladeComponent($buttonIcon)` (§13.5).

## D.3 `quote.blade.php`

```blade
@php
    $data = is_array($attributes ?? null) ? $attributes : [];
    $content = trim(strip_tags((string) ($data['content'] ?? '')));
    $citation = trim(strip_tags((string) ($data['citation'] ?? '')));
    $style = in_array(($data['style'] ?? 'default'), ['default', 'plain'], true) ? $data['style'] : 'default';
@endphp

@if($content !== '')
    <blockquote class="gtc-block gtc-block-quote gtc-block-quote--{{ $style }}">
        <p class="gtc-block-quote__content">
            <span class="gtc-block-quote__content-text">{{ $content }}</span>
        </p>
        @if($citation !== '')
            <cite class="gtc-block-quote__citation">
                <span class="gtc-block-quote__citation-text">{{ $citation }}</span>
            </cite>
        @endif
    </blockquote>
@endif
```
Note `strip_tags` on both `content` and `citation` — the partial flattens any markup to text (the contract's `rich-text-block` sanitize already ran upstream; this is belt‑and‑braces).

## D.4 `section-head.blade.php`

```blade
@php
    $data = is_array($attributes ?? null) ? $attributes : [];
    $label = trim((string) ($data['label'] ?? 'KEY TAKEAWAY')) ?: 'KEY TAKEAWAY';
    $heading = trim(strip_tags((string) ($data['heading'] ?? 'What you need to know'))) ?: 'What you need to know';
    $style = in_array(($data['style'] ?? 'default'), ['default', 'accent', 'muted'], true) ? $data['style'] : 'default';
@endphp

<section class="gtc-block gtc-block-section-head gtc-block-section-head--{{ $style }}">
    <span class="gtc-block-section-head__label">{{ $label }}</span>
    <h2 class="gtc-block-section-head__heading">
        <span class="gtc-block-section-head__heading-text">{{ $heading }}</span>
    </h2>
</section>
```

## D.5 `button.blade.php`

```blade
@php
    $blockAttributes = is_array($attributes ?? null) ? $attributes : [];
    $text = trim(strip_tags((string) ($blockAttributes['text'] ?? 'Book a consultation'))) ?: 'Book a consultation';
    $url = trim((string) ($blockAttributes['url'] ?? '#'));
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $url = $url !== '' && (! $scheme || in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true)) ? $url : '#';
    $variant = in_array($blockAttributes['variant'] ?? 'primary', ['primary', 'secondary', 'danger', 'link', 'outline'], true) ? $blockAttributes['variant'] : 'primary';
    $icon = trim((string) ($blockAttributes['icon'] ?? 'arrow-up-right')) ?: 'arrow-up-right';
    $icon = preg_match('/^[a-z0-9-]+$/', $icon) ? $icon : 'arrow-up-right';
    $iconPosition = ($blockAttributes['iconPosition'] ?? 'right') === 'left' ? 'left' : 'right';
    $target = ($blockAttributes['target'] ?? '_self') === '_blank' ? '_blank' : '_self';
    $rel = $target === '_blank' ? 'noopener noreferrer' : null;
    $fullWidth = (bool) ($blockAttributes['fullWidth'] ?? false);
@endphp

<div class="gtc-block gtc-block-button gtc-block-button--{{ $variant }} gtc-block-button--icon-{{ $iconPosition }} gtc-block-button--full-{{ $fullWidth ? 'true' : 'false' }}">
    <a href="{{ $url }}" class="btn btn-{{ $variant }} gtc-block-button__link" target="{{ $target }}" @if($rel) rel="{{ $rel }}" @endif>
        <span class="btn-text">{{ $text }}</span>
        <span class="btn-icon-frame" aria-hidden="true">
            <x-dynamic-component :component="'lucide-' . $icon" class="size-4" />
        </span>
    </a>
</div>
```
> The button partial uses `$blockAttributes` (not `$data`) — a cosmetic naming difference from the others; preserve or normalize as you like, but the safety logic (scheme check, variant clamp, icon regex, `_blank`→`rel`) is the contract.

---

# Appendix E — Worked example: a block's full round‑trip

Tracing one paragraph block from editor to public page makes the two‑path system concrete.

**1. Editor sends (JSON‑first)** — the autosave PUT `/api/admin/blog-posts/{id}/content` body includes:
```json
{
  "schemaVersion": 1,
  "registryHash": "sha256:abc…",
  "computedStyles": "",
  "blocks": [
    { "id": "b-001", "name": "gtc/paragraph", "schemaVersion": "1.0.0",
      "attributes": { "content_en": "Hello <strong>world</strong>", "content_fr": "Bonjour <strong>le monde</strong>" },
      "supports": { "color": { "text": "var(--accent-2)" }, "spacing": { "margin": "1rem" } },
      "innerBlocks": [] }
  ]
}
```

**2. Validation** (`BlocksPayloadService::validatePayload`) — `schemaVersion===1` ✓, `registryHash` is `sha256:`‑prefixed ✓, then `validateBlockInstance` for `b-001`: `name` `gtc/paragraph` exists in the registry ✓, `block.schemaVersion "1.0.0"` equals the contract's `version` ✓, `attributes.content_*` are strings (rich‑text type) ✓. Returns `{valid:true}`.

**3. Persistence** (`BlockService::persistBlocks`) — `normalizeBlockPayload` strips any `_allow_raw` (none here), detects this is **not** html_raw, and — because the block is bare JSON‑first with no `content` array — wraps it to the stored legacy shape. The `blog_blocks` row written is:
```json
{ "post_id": 42, "type": "paragraph", "order": 0,
  "content": { "id": "b-001", "name": "gtc/paragraph", "schemaVersion": "1.0.0",
               "attributes": { "content_en": "Hello <strong>world</strong>", "content_fr": "Bonjour <strong>le monde</strong>" },
               "supports": { "color": { "text": "var(--accent-2)" }, "spacing": { "margin": "1rem" } },
               "innerBlocks": [] } }
```
`order` = array index (0), not any client value. After the loop, blocks not in the keep‑set are deleted (full replace).

**4. Render trigger** — the controller fires `BlogPostSaved($post)` → queued `HandleBlogPostSaved` (low queue) → `RenderBlogPostJob`.

**5. Render** (`BlockRenderer::renderBlocks($blocks, 'fr')`) — `renderBlock` sees the stored row's `content` is an array with a string `name` → `renderJsonBlock`. The `gtc/paragraph` contract has a `render.template`, so `renderContractTemplate` runs:
- tag `p`; class `gtc-block gtc-block-paragraph` (+ `contract.style.className` `gtc-block-paragraph`, de‑duped); attributes `data-block-name="gtc/paragraph" data-block-id="b-001"`; root style from `style.variables` → `--gtc-paragraph-color: var(--accent-2); --gtc-paragraph-...` (margin resolved via the spacing box helper).
- child is a `rich-text` node for `content`: `localizedTemplatePath(attributes, 'content', 'fr')` resolves `content_fr` = `"Bonjour <strong>le monde</strong>"`, sanitized by `sanitizeRichText` (keeps `<strong>`).

**6. Output (FR):**
```html
<p class="gtc-block gtc-block-paragraph" data-block-name="gtc/paragraph" data-block-id="b-001" style="--gtc-paragraph-color: var(--accent-2); --gtc-paragraph-margin: 1rem;">
  <span class="gtc-block-paragraph__text">Bonjour <strong>le monde</strong></span>
</p>
```

**7. Cache** — `RenderBlogPostJob` runs `HtmlSanitizationService::purify()` over the full concatenated HTML (final XSS pass), writes `rendered_html_fr` (and `_en`), and busts the post cache. The public `BlogController@show` then serves `rendered_html_fr` directly.

> The same block, had it arrived as legacy `{ "type":"paragraph", "content": { "text_fr":"Bonjour", "styles": {} } }`, would skip the contract path and render via `renderParagraph` (§4.2) — same `<p class="gtc-block gtc-block-paragraph">` shell, locale resolved by `localized()` instead of `localizedTemplatePath()`.

---

# Appendix F — Test matrix

Per GTC Rule 11 (tests run against **MySQL**, never SQLite) and TDD. The minimum suite Heisenberg should ship, mirroring the proven GTC coverage:

| Area | Must assert |
|---|---|
| **Contract validator** | each of the 17 required keys missing → error; bad `name` prefix → error; non‑semver `version` → error; `style.variables.default` raw hex → error; `serialization.mode != json` → error; `security.allowCustomCss != false` → error; asset path with `..` → error; a valid contract → `{valid:true}` |
| **Registry** | discovers all shipped contracts; `registryHash` stable across locale switch; duplicate `name` → `errors[]`; path‑traversal filename rejected; `getBlock`/`isBlockKnown` |
| **Payload service** | unknown block name → error; `schemaVersion` mismatch → error; enum violation → error; missing optional attribute → OK; legacy `{type,content}` per‑type required‑field checks; empty collection → "cannot publish without blocks" |
| **Block persistence** | full‑replace removes dropped blocks; `order` = array index; update‑in‑place preserves id/created_at; **html_raw by non‑super_admin → `AuthorizationException`**; html_raw by super_admin → sanitized + `_allow_raw=true` set server‑side; `_allow_raw` in the wire payload is stripped |
| **Renderer** | every block type renders; empty required field → `''`; XSS payload in text → escaped; `javascript:` image url → dropped; html_raw without `_allow_raw` → suppressed comment; html_raw re‑sanitized at render; YouTube/Vimeo url → safe iframe; CSS injection in style var → sanitized; locale fallback chain (`text_fr`→`fr`→`text_en`→`text`→default) |
| **Sanitizer** | `html_raw` config allows `figure`/`iframe` (YouTube/Vimeo only); `richText` config strips headings/images; `script`/`on*` always stripped; cache‑dir failure doesn't crash |
| **Slug** | `untitled` fallback; `base`,`base-2`,`base-3` sequence (no `-1`); `ignoreId` excludes self; unique‑violation retry |
| **State machine** | every legal transition allowed; every illegal one rejected; role permission per target status; **publish authority decision** (admin can/can't publish — lock it) |
| **Transition action** | publish sets `published_at`/clears `scheduled_at`; invalid blocks block publish/schedule; non‑author can't submit others' for review; audit dispatched; `PostTransitioned` fired; review note on approve/request‑changes; revision snapshot taken |
| **Scheduled publish** | only due `scheduled` posts published; future‑dated not published early; no system actor → loud failure |
| **Autosave** | optimistic lock → 409 on stale `content_version`; success bumps version; `BlogPostSaved` fired |
| **Patterns** | validate against live registry; `share` gated to super_admin; `incrementUsage` doesn't bump `updated_at` |
| **Revisions** | snapshot captures blocks+rendered+title/excerpt; **restore goes through `persistBlocks`** (html_raw re‑sanitized); restore records a `restore` revision |
| **Policies** | reproduce the full §10.2 role map (every ability × tier) |
| **Parity** | `blocks:verify` runs; document the known 9‑of‑20 contract gap |

---

> **End of blueprint.** Everything above was reverse‑engineered from the live GTC `Modules/Blog` source — services and renderer read in full, all nine block contracts and five render partials quoted byte‑for‑byte, plus the host‑app routes, migrations, policies, lang files, and config. The `[AS‑BUILT]` sections are ground truth; the `[TARGET]` sections are the recommended clean‑room design. Start the Heisenberg repo at Part 15 / M0, keep this file as the package's `docs/BLUEPRINT.md`, and resolve the Part 16 open questions (publish authority first) as you go. When the GTC `Blog` module changes, update the affected section here so the blueprint never drifts from the source it was distilled from.











# Heisenberg Post Template Contract Schema

The canonical definition of a Heisenberg **post template** — the JSON contract an adopter
writes to declare how their posts/pages render publicly: which chrome features (table of
contents, author box, comments, …) are on, and whether Heisenberg renders each one itself or
hands off to the adopter's own implementation.

This is a **contract on disk**, exactly like a [block contract](block-schema.md) — no database
migration is required to add, change, or remove a template. Templates live at
`resources/templates/<slug>/<slug>.json`, overridable via `config('heisenberg.template_root')` —
**this is how a host supplies its own post/page templates**: point `template_root` at a
directory in the host app and the registry scans, validates and serves those contracts instead.
Each is validated by `PostTemplateContractValidator`;
invalid contracts are excluded from the registry and reported by `PostTemplateRegistryService`
and the `templates:verify` command.

> **Design stance.** This schema deliberately mirrors the
> [block contract](block-schema.md)'s JSON shape, `version` field, ordered-pass validator
> style, and registry/scan/hash/localization conventions, so an adopter who has already
> learned the block contract recognises this immediately. It diverges only where a post
> template is a fundamentally different *kind* of object than a block — see
> [Divergences from the block contract](#divergences-from-the-block-contract).

## What a post template is (and isn't)

A block contract describes a **reusable, instantiable content unit**: an editor inserts many
`heisenberg/heading` instances into many posts, each with its own attribute values, and those
values are persisted per-instance. A post template is not that. It is picked **once per post**
(by whatever selection mechanism the host builds — a `template` field the host adds to their
own `Post` subclass, a per-category default, a hard-coded single choice; Heisenberg does not
prescribe this, because `Post` has no such column today and adding one is a migration this
package does not ship) and governs the page **chrome around** the post's already-rendered block
content:

```
┌─────────────────────────────────────────────────────────┐
│  Breadcrumbs                                             │
│  ┌─────────────────────────────────────────────────┐    │
│  │              Featured image                       │    │
│  └─────────────────────────────────────────────────┘    │
│  Title · Author box · Reading time · Post views          │
│  ┌───────────┐  ┌──────────────────────────────────┐    │
│  │  Table of │  │  {{ $post->rendered_html_en }}     │    │
│  │  Contents │  │  ← the block engine's own output,  │    │
│  │           │  │    already rendered, untouched     │    │
│  └───────────┘  └──────────────────────────────────┘    │
│  Share buttons                                            │
│  Related posts                                            │
│  Comments                                                 │
│  Pagination (prev/next)                                   │
└─────────────────────────────────────────────────────────┘
```

The post's own body (`rendered_html_en`/`rendered_html_fr`) is produced by `BlockRenderer` from
the post's `blocks` and is **out of scope here** — a post template never re-renders or wraps
individual blocks. It only decides which of the eleven chrome *capabilities* below appear, and
supplies the options each one needs.

## Top-level keys (11, all required)

| Key | Type | Purpose |
|---|---|---|
| `$schema` | string | Points to this file. |
| `apiVersion` | int | Must be `1`. |
| `name` | string | `<prefix>/<slug>`, lowercase (e.g. `heisenberg/article`). Prefix configurable, same convention as blocks. |
| `title` | string | Display title; a `heisenberg::`-namespaced lang key is localized. |
| `category` | string | Grouping for a future "choose a template" UI (e.g. `post`, `page`, `landing`). |
| `icon` | string | Lucide icon slug, for the same future picker UI. |
| `description` | string | Short description (lang key allowed). |
| `keywords` | string[] | Search terms for the same future picker UI. |
| `version` | string | Semver (e.g. `1.0.0`). |
| `capabilities` | object | Which of the 11 chrome features are on, and their options. See below. |
| `render` | object | `{ view, script }` — the Blade view the host supplies for this template. See below. |

## `render`

```jsonc
"render": {
  "view": "theme::posts.article",   // a Blade view name the HOST supplies — never a Heisenberg view
  "script": null                     // null, or a safe relative .js path
}
```

- `view` is a Blade view **reference** (`namespace::dot.path` or a bare dot-path), not a
  filesystem path — it is resolved by Laravel's view finder at render time, exactly like
  choosing which layout to extend. Heisenberg does not ship this view (it owns no
  `resources/views/**` for post templates) and does not check that it exists on disk — same
  as a block's `publicPartial`, which names a partial without the validator resolving it.
  Validation only rejects unsafe strings (path traversal, a leading dot, disallowed characters).
- `script` follows the exact same rule as a block's `render.script`: `null`, or a safe relative
  `.js` path (no traversal, no absolute path, no backslashes).

## `capabilities`

A map of `capabilityKey -> options`, one entry per feature the template turns on. **Every key
is optional** — an absent key behaves as `{"enabled": false}`. This is the schema's
forward-compatibility rule (see [Versioning](#versioning-and-compatibility)): a template written
against today's schema is never invalidated by a future package version adding a 12th
capability. An **unrecognized key is always rejected** — that catches typos and version skew in
the other direction (a template file that expects a capability an older Heisenberg doesn't
know about).

Every capability object requires a boolean `enabled`. The rest of each shape is documented
per-capability below, alongside **the render-vs-adapter decision** — this is the question the
project brief asked to be answered per option, not globally.

### The decision, at a glance

| # | Capability | Decision | Why |
|---|---|---|---|
| 1 | Table of contents | **Render** | Derived from the post's own heading blocks at render time. Zero storage. |
| 2 | Featured image | **Render** | Resolved from the post's own content (first image block) through the existing `MediaResolver` contract. Zero new storage. |
| 3 | Post views | **Adapter** | No column, no table exists anywhere in this package for a view counter. Nothing to render — a host supplies storage. |
| 4 | Comments/discussion | **Adapter** | Native storage ships (2026-08-11): `heisenberg_comments` backs `Heisenberg\Models\Comment`, and `NativeCommentProvider` is the default binding. A host may still bind `NullPostCommentProvider` (disable) or their own class (external system) at the same config key. |
| 5 | Related posts | **Adapter** | `heisenberg_post_related` table name is reserved but no model is built (planned M3), and the alternative (taxonomy-based) strategy depends on taxonomy work landing separately. Selection strategy is a host decision until then. |
| 6 | Reading time | **Render** | Pure computation (word count ÷ words-per-minute) over the post's own block content. Zero storage. |
| 7 | Author box | **Render** | Resolved via a configurable attribute map off the host's own user model — see the caveat below. |
| 8 | Share buttons | **Render** | Pure UI: a fixed list of networks plus the post's own public URL/title. Zero data dependency. |
| 9 | Breadcrumbs | **Render** | Structural UI (Home › [Category] › Title) with the category segment optional and configurable — degrades gracefully when a host hasn't added category linkage yet. |
| 10 | SEO/meta emission | **Adapter** | Native storage ships (2026-08-11, docs/seo-system.md Wave S1): the polymorphic `seo_meta` table backs `Heisenberg\Models\SeoMeta`, and `NativeSeoMetaProvider` is the default binding. A host may still bind `NullPostSeoMetaProvider` (opt out) or their own class (external SEO system) at the same config key. |
| 11 | Pagination | **Render** | Pure UI/query over already-existing `Post` columns (`published_at`, `locale`, `status`). Zero new storage. |

Seven of the eleven (1, 2, 6, 7, 8, 9, 11) are render concerns Heisenberg could plausibly
implement directly against data that already exists. Four (3, 4, 5, 10) need storage
this package does not yet have and are therefore adapter contracts with a null-object default,
following the exact pattern already established by `MediaResolver`/`RoleGate`/`AuditSink`/
`IconProvider` (`HeisenbergServiceProvider::registerContracts()`): an interface, a bundled
no-op adapter, and a config key naming the bound class. **These four interfaces and null
adapters are included in this delivery** (`src/Contracts/PostViewsProvider.php`,
`PostCommentProvider.php`, `RelatedPostsProvider.php`, `PostSeoMetaProvider.php`, and their
`src/Adapters/Null*.php` defaults) so the design is concrete and testable today — but **they are
not yet bound in the service container**, because that binding lives in
`HeisenbergServiceProvider::registerContracts()`, a file this work was scoped to leave
untouched. See [Wiring](#wiring) for the exact addition needed.

### 1. Table of contents — Render

```jsonc
"tableOfContents": {
  "enabled": true,
  "source": "headings",        // "headings" | "entries" — see note below
  "minLevel": 2,                // 1-6, "headings" only
  "maxLevel": 3,                // 1-6, >= minLevel, "headings" only
  "title": "heisenberg::templates.article.toc_title"
}
```

Two sources, genuinely different features:

- `"headings"` — computed from the post's own heading blocks (`heisenberg/heading` instances in
  its `blocks`) at render time; no storage needed. `minLevel`/`maxLevel` filter which heading
  levels are included.
- `"entries"` (2026-08-10) — the post's own AUTHORED table of contents: `{label, anchor}` rows an
  editor writes explicitly from the editor's Post tab ("Table of contents" section → modal),
  stored in `heisenberg_post_toc_entries` (`config('heisenberg.tables.toc_entries')`,
  `Post::tocEntries()`, blueprint §2.3.10's `BlogPostTocEntry`). Custom labels and manual
  ordering, independent of the heading structure — a post's TOC renders only when it has rows
  here. `minLevel`/`maxLevel` don't apply to this source (there is no heading level to filter).
  Written via `PUT /editor/posts/{post}/toc` (`PostSettingsController::updateToc()`), which
  replaces the whole set on every save; the modal's "Load from headings" action seeds it from the
  current heading blocks (writing a slugified `anchor` attribute back onto each heading so the
  link actually resolves) as a starting point, not a live binding — editing headings afterward
  does not change already-authored entries.

### 2. Featured image — Render

```jsonc
"featuredImage": {
  "enabled": true,
  "source": "post-attribute",       // or "first-image-block"
  "context": "hero",                // passed to MediaResolver::resolve($url, $context)
  "fallback": null                  // a static fallback URL, or null
}
```

`Post` has no `featured_image` column in the current schema (`docs/BLUEPRINT.md`'s full
magazine schema reserves one; the as-built reduced migration does not carry it, and adding it
is a migration out of scope here). So, like TOC, this resolves from content that already
exists: the first `heisenberg/image` block in the post, its `url` attribute resolved through the
**already-bound** `MediaResolver` contract (no new binding needed — this is the one render
capability that leans on an existing seam rather than a new one). `"post-attribute"` (added
2026-08-10, the moment a host actually needed it — a real host integration bench) declares the OTHER
source: the post's own `featured_image_id` FK (`Post::featuredImage`), i.e. whatever the
editor's Post-tab picker set — the same relation `PreviewController::featuredPayload()` renders.

### 3. Post views — Adapter

```jsonc
"postViews": {
  "enabled": true,
  "label": "heisenberg::templates.article.views_label"
}
```

`Heisenberg\Contracts\PostViewsProvider` (`record(Post $post): void`, `count(Post $post): int`).
Bundled default `Heisenberg\Adapters\NullPostViewsProvider` always reports `0` and records
nothing. There is no column, table, or cache anywhere in this package for a view counter —
`config('heisenberg.tables.*')` doesn't even reserve a name for one (the full magazine schema's
`blog_posts.view_count` column never made it into the as-built reduced `heisenberg_posts`
migration). A host wires their own analytics table, Redis counter, or third-party service.

### 4. Comments/discussion — Adapter

```jsonc
"comments": {
  "enabled": true,
  "allowGuests": true,
  "sortOrder": "newest",         // "newest" | "oldest" — top-level ordering only
  "threaded": true,               // render nested replies at all
  "maxDepth": 3                   // 1-10; a template-side cap, never deeper than storage allows
}
```

`Heisenberg\Contracts\PostCommentProvider` (`thread(Post $post, string $sortOrder): array`,
`count(Post $post): int`, `submit(Post $post, array $input): array`). **Native storage now
ships** (2026-08-11): `config('heisenberg.tables.comments')`'s `heisenberg_comments` table
backs `Heisenberg\Models\Comment` (blueprint §2.3.6 `BlogComment`, scoped down — see the
model's own docblock for what's deliberately out: no `meta` json, no editor-reply/feature
flags, no reaction counts), and `Heisenberg\Adapters\NativeCommentProvider` is the **default**
binding at `heisenberg.post_template.comments_provider`. A host binds
`Heisenberg\Adapters\NullPostCommentProvider` at the same key to disable comments entirely, or
its own class to integrate an external system (their own table, Disqus, a hosted service) — the
capability's shape never changes, only which class answers it.

`thread()` returns approved top-level comments (sorted by `sortOrder`) with their approved
replies nested underneath (always oldest-first, at every level, regardless of `sortOrder`); a
reply of an unapproved parent simply doesn't render. `count()` is the total approved count,
top-level and replies combined. `submit()` takes
`{parent_id?, author_id?, author_name, author_email?, body, auto_approve?}` and returns
`{ok, status, comment?, error?}` — a new comment is stored `pending` unless
`config('heisenberg.comments.auto_approve')` or the input's own `auto_approve` says otherwise; a
reply is rejected (`error: 'max-depth'`) once it would sit at or past
`config('heisenberg.comments.max_depth')`, and a `parent_id` from a different post or a
spam/trash comment is rejected as `error: 'invalid-parent'`. See `PostCommentProvider`'s
docblock for the full `Item` shape and every field's precise meaning — this doc only summarizes
it. Request-shape validation (required fields, auth/guest policy per `allowGuests`, rate
limiting) is an HTTP layer's job, not this contract's.

### 5. Related posts — Adapter

```jsonc
"relatedPosts": {
  "enabled": true,
  "limit": 3
}
```

`Heisenberg\Contracts\RelatedPostsProvider` (`related(Post $post, int $limit): array`). Bundled
default `Heisenberg\Adapters\NullRelatedPostsProvider` always returns `[]`.
`config('heisenberg.tables.post_related')` reserves `heisenberg_post_related`, a self-pivot
matching `docs/BLUEPRINT.md` §2.4's `blog_post_related` (hand-curated relations, no model built
— planned M3). Unlike comments, though, "related" doesn't strictly require curation — once
taxonomy (categories/tags) exists, a perfectly reasonable strategy is computed
("shares a category", "shares 2+ tags") rather than curated. Baking in *either* choice now would
bias a decision that isn't this package's to make yet, especially with taxonomy work landing in
parallel. Adapter-with-null-default defers that choice cleanly.

### 6. Reading time — Render

```jsonc
"readingTime": {
  "enabled": true,
  "wordsPerMinute": 200,
  "label": "heisenberg::templates.article.reading_time_label"
}
```

A pure computation — word count of the post's own rendered/plain content divided by
`wordsPerMinute` — needs no storage at all (the full magazine schema's `reading_time` column
was a *cache* of this same computation; recomputing at render time is equally correct and
avoids yet another column this package doesn't carry).

### 7. Author box — Render

```jsonc
"authorBox": {
  "enabled": true,
  "fields": { "name": "name", "avatar": "avatar_url", "bio": "bio" }
}
```

**The judgment call in this schema.** The brief's own "pure renderer concerns" list (TOC,
reading time, featured image, breadcrumbs, share buttons) does not include author box, and for
good reason: `Post::author_id` is a bare foreign key, `config('heisenberg.user_model')` names
whatever `Authenticatable` class the host uses, and the only contract Heisenberg has for it —
`Heisenberg\Contracts\HeisenbergUser` — deliberately exposes nothing but
`getAuthIdentifier()` (see its docblock: "Heisenberg only ever needs the identifier; role
questions go through `RoleGate`"). There is **no contract anywhere in this package for a
display name, avatar, or bio.**

Two ways to close that gap: (a) a new `AuthorProfileProvider` adapter contract, or (b) a
configurable attribute map read directly off whatever `config('heisenberg.user_model')`
resolves to, tolerating missing fields. This schema picks **(b) — render, not adapter** — because
unlike comments/views/related/SEO, the *shape* of the answer (a name, an avatar URL, a short
bio) is completely conventional across virtually every Laravel `User` model, whereas *what to do
with a comment* or *how to pick a related post* genuinely varies by host. `fields` maps each
logical slot to the attribute/accessor name to read (`name` on the model by default in the
worked example below); a `null` value or a field the model doesn't actually have simply
omits that slot from the rendered box rather than erroring. If a host's needs outgrow this
(e.g. a dedicated author bio separate from their `users` table), an `AuthorProfileProvider`
adapter is the obvious follow-up — not built here because nothing today demands it.

### 8. Share buttons — Render

```jsonc
"shareButtons": {
  "enabled": true,
  "networks": ["x", "facebook", "linkedin", "email", "copy-link"]
}
```

Allowed networks: `x`, `facebook`, `linkedin`, `email`, `copy-link`, `whatsapp`, `pinterest`.
Pure UI — every network's share URL is a template combining the post's own public URL and
title, both already available with zero new data. `networks` must be non-empty when enabled.

### 9. Breadcrumbs — Render

```jsonc
"breadcrumbs": {
  "enabled": true,
  "homeLabel": "heisenberg::templates.article.breadcrumbs_home",
  "categoryAttribute": null
}
```

A trail of `Home › [Category] › Post title`. `Post` carries no `category_id` in the current
schema (taxonomy is being built separately from this work), so the category segment is
optional and configurable: `categoryAttribute: null` (the default) renders `Home › Title` with
zero data dependency; a host that has added a category relation of their own (to their `Post`
subclass) names its attribute/relation there and the segment appears once that data exists.
Graceful degradation, not a hard dependency on taxonomy landing first.

### 10. SEO/meta emission — Adapter

```jsonc
"seoMeta": {
  "enabled": true,
  "fields": ["title", "description", "canonical", "ogImage", "robots"]
}
```

`Heisenberg\Contracts\PostSeoMetaProvider` (`meta(Post $post, string $locale): array`). **Native
storage now ships** (2026-08-11, docs/seo-system.md Wave S1):
`config('heisenberg.tables.seo_meta')`'s polymorphic `seo_meta` table (`docs/BLUEPRINT.md`
§2.3.11/§2.4's `SeoMeta` model — generic, polymorphic, "no host coupling", ported verbatim plus
the SEO/Social panel's extra fields) backs `Heisenberg\Models\SeoMeta`, and
`Heisenberg\Adapters\NativeSeoMetaProvider` is the **default** binding at
`heisenberg.post_template.seo_meta_provider`. A host binds
`Heisenberg\Adapters\NullPostSeoMetaProvider` at the same key to opt out entirely (always
empty, e.g. when SEO is managed by something outside this package), or its own class to
integrate an external system (their own table, a hosted SEO service) — the capability's shape
never changes, only which class answers it, same posture as `comments`
([§4](#4-commentsdiscussion--adapter)).

`meta()`'s return shape grows additively: the original `title`/`description`/`canonical`/
`ogImage`/`robots` plus `ogTitle`/`ogDescription`/`jsonLd` (schema.org JSON-LD,
`SeoMeta::getJsonLd()`) added in Wave S1 — every key is optional, callers must tolerate any of
them being absent or null. `fields` still names which meta fields the template *wants*
emitted (unchanged shape); the reference template flips to `enabled: true` in this wave since
there's finally a truthful default behind it — see `PreviewController::showPost()` for where
the seam is actually called and mapped onto the page's `<head>`.

### 11. Pagination — Render

```jsonc
"pagination": {
  "enabled": true,
  "mode": "prev-next",     // "prev-next" | "numbered"
  "perPage": 10             // required only when mode = "numbered"
}
```

`prev-next` needs only the post's own `published_at`/`locale`/`status` (already-existing
columns) to find its neighbors; `numbered` paginates an archive listing and needs `perPage`.
Neither needs new storage.

## Divergences from the block contract

A block contract's `attributes`/`supports`/`style`/`innerBlocks`/`serialization`/`security` all
exist because a block is **instantiated repeatedly with per-instance state that must be
validated, serialized, and rendered safely** — often authored by a less-trusted content editor
through the block UI. A post template has none of that shape:

- **No `attributes`/`supports`.** A template is not instantiated with values — it is selected by
  name. There is nothing analogous to an attribute's default/enum/sanitizer.
- **No `style`.** A template has no CSS variables of its own; each capability's presentation is
  entirely the host's Blade view's concern.
- **No `innerBlocks`.** Templates don't nest inside each other.
- **No `serialization`.** Nothing about a template is persisted per-post; there is no "saved
  attributes" JSON blob to keep in sync with a schema migration.
- **No `security`.** A block's `security` key exists because block *content* is frequently
  user/editor-authored HTML/rich-text needing an explicit sanitization posture. A template
  contract is developer-authored code shipped alongside the rest of the package/app — the same
  trust level as `config/heisenberg.php` itself — so there is no analogous "how much do we trust
  this string" question to answer per-template.

Kept identical to blocks: the `$schema`/`apiVersion`/`name`/`title`/`category`/`icon`/
`description`/`keywords`/`version` metadata block, the `render.script` safe-path rule, the
ordered-pass validator style (`validate()` returns `['valid' => bool, 'errors' => string[]]`),
and the registry's scan/cache/hash/localization approach (`schemaVersion`, `registryHash`,
per-instance disk scan cached for the request, `computeHash()` on the untranslated contracts,
`heisenberg::`-namespaced label localization that never changes the hash).

## Versioning and compatibility

- **`apiVersion`** is the contract *shape* version — bump it only when the top-level key set or
  a key's fundamental meaning changes in a breaking way (mirrors the block contract's
  `apiVersion`). It is `1` today; there is only one shape.
- **`version`** is the individual template's own semver, bumped by its author when the
  template's behavior changes in a way a consumer (a host's chosen-template config, a cached
  render) should care about. Heisenberg does not enforce any relationship between two versions
  of the same template — that is the same stance the block contract takes.
- **Adding a 12th capability** in a future package version is non-breaking for every template
  already on disk: an absent key already means "disabled" today (see `capabilities` above), so
  the new key simply defaults off until an adopter opts in. No migration of existing template
  files is ever required for this.
- **Removing or renaming a capability key** is breaking (an adopter's template referencing the
  old key starts failing validation) and should ship as an `apiVersion` bump with a documented
  migration note, exactly like a breaking block-contract change would.
- **`PostTemplateRegistryService::computeHash()`** is a *separate* hash from
  `BlockRegistryService::computeHash()` — a completely distinct registry, cache, and hash
  namespace. Adding, editing, or removing a template never changes the block registry's hash
  and vice versa.

## Worked example: `heisenberg/article`

The shipped reference template at `resources/templates/article/article.json` — a realistic
single-post template exercising every render capability plus all four adapter capabilities,
`seoMeta` included since [§10](#10-seometa-emission--adapter)'s native default landed
(2026-08-11):

```jsonc
{
  "$schema": "../../../docs/post-template-schema.md",
  "apiVersion": 1,
  "name": "heisenberg/article",
  "title": "heisenberg::templates.article.title",
  "category": "post",
  "icon": "newspaper",
  "description": "heisenberg::templates.article.description",
  "keywords": ["article", "post", "blog", "single"],
  "version": "1.0.0",
  "render": {
    "view": "theme::posts.article",
    "script": null
  },
  "capabilities": {
    "tableOfContents": { "enabled": true, "source": "headings", "minLevel": 2, "maxLevel": 3, "title": "heisenberg::templates.article.toc_title" },
    "featuredImage":   { "enabled": true, "source": "first-image-block", "context": "hero", "fallback": null },
    "readingTime":     { "enabled": true, "wordsPerMinute": 200, "label": "heisenberg::templates.article.reading_time_label" },
    "authorBox":       { "enabled": true, "fields": { "name": "name", "avatar": "avatar_url", "bio": "bio" } },
    "shareButtons":    { "enabled": true, "networks": ["x", "facebook", "linkedin", "email", "copy-link"] },
    "breadcrumbs":     { "enabled": true, "homeLabel": "heisenberg::templates.article.breadcrumbs_home", "categoryAttribute": null },
    "pagination":      { "enabled": true, "mode": "prev-next" },
    "postViews":       { "enabled": true, "label": "heisenberg::templates.article.views_label" },
    "comments":        { "enabled": true, "allowGuests": true, "sortOrder": "newest" },
    "relatedPosts":    { "enabled": true, "limit": 3 },
    "seoMeta":         { "enabled": true, "fields": ["title", "description", "canonical", "ogImage", "robots"] }
  }
}
```

Its labels resolve from `resources/lang/{en,fr}/templates.php` under the `heisenberg::templates.*`
namespace, the same convention `resources/lang/{en,fr}/blocks.php` uses for block labels.

## Shipping and registering your own template

1. Create `resources/templates/<slug>/<slug>.json` (or wherever `config('heisenberg.template_root')`
   points once wired — see [Wiring](#wiring)) following the shape above. Use the
   `heisenberg/article` reference template as a starting point.
2. If any `title`/`description`/capability label uses a `heisenberg::`-namespaced lang key,
   add the matching entries to your own package/app's `resources/lang/{locale}/templates.php`
   (or whichever namespace you load) — exactly as you would for a custom block's labels.
3. Implement the Blade view named in `render.view` in your own app/theme (Heisenberg does not
   supply one).
4. If your template enables an adapter-backed capability (`postViews`, `comments`,
   `relatedPosts`, or `seoMeta`) and you want it backed by real data rather than the bundled
   null default, implement the matching contract (`Heisenberg\Contracts\PostViewsProvider`,
   `PostCommentProvider`, `RelatedPostsProvider`, or `PostSeoMetaProvider`) and bind it at the
   config key named in [Wiring](#wiring) below.
5. Run `php artisan templates:verify` to confirm your contract validates before shipping it.

## Wiring

**Historical note (2026-08-10): everything below has since landed** — the config keys exist in
`config/heisenberg.php` (`template_root`/`template_prefix`/`post_template`), the four provider
bindings are in `HeisenbergServiceProvider` (see its contract-binding map), and both verify
commands are registered. The section is kept as the record of what wiring meant. (One value has
since changed again, 2026-08-11: `comments_provider`'s default is now `NativeCommentProvider`,
not `NullPostCommentProvider` — see [§4](#4-commentsdiscussion--adapter) above. The snippet
below is left as originally written for historical accuracy.)

Everything in this delivery works standalone (direct instantiation, exactly like the tests in
`tests/Templates/`) with **zero required changes elsewhere**. Two things were deliberately *not*
added, because doing so meant touching files this work was scoped to leave alone
(`config/heisenberg.php`, `src/HeisenbergServiceProvider.php`) while a second, concurrent
change was landing in that same area. A maintainer who wants the full experience wired up needs:

1. **Two new config keys**, both optional and both already tolerated by the code above via
   `config(...)`'s default-value fallback (nothing breaks today without them):
   ```php
   'template_root'   => null,   // null -> package resources/templates, mirrors block_root
   'template_prefix' => 'heisenberg', // contract name namespace, mirrors block_prefix

   'post_template' => [
       'post_views_provider'     => \Heisenberg\Adapters\NullPostViewsProvider::class,
       'comments_provider'       => \Heisenberg\Adapters\NullPostCommentProvider::class,
       'related_posts_provider'  => \Heisenberg\Adapters\NullRelatedPostsProvider::class,
       'seo_meta_provider'       => \Heisenberg\Adapters\NullPostSeoMetaProvider::class,
   ],
   ```
2. **Four container bindings** in `HeisenbergServiceProvider::registerContracts()`, following
   the exact pattern already used there for `MediaResolver`/`AuditSink`/`IconProvider`:
   ```php
   $this->app->singleton(PostViewsProvider::class, fn ($app) => $app->make(
       (string) $app['config']->get('heisenberg.post_template.post_views_provider', NullPostViewsProvider::class)
   ));
   // ...and the same for PostCommentProvider, RelatedPostsProvider, PostSeoMetaProvider.
   ```
3. **Command registration**, since an Artisan command must be registered with the console
   kernel to be callable by name — this package's service provider currently registers no
   commands at all (`src/Console/` did not exist before this delivery). In
   `HeisenbergServiceProvider::boot()`:
   ```php
   if ($this->app->runningInConsole()) {
       $this->commands([
           \Heisenberg\Console\Commands\TemplatesVerifyCommand::class,
           \Heisenberg\Console\Commands\BlocksVerifyCommand::class,
       ]);
   }
   ```

None of the above is required for `PostTemplateContractValidator`, `PostTemplateRegistryService`,
the reference template, or either console command's own logic to work or to be tested — see
`tests/Templates/` for direct instantiation without any of this wiring.

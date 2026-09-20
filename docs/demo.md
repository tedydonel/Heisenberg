# The workbench demo — proving the adopter path end-to-end

This is Heisenberg's missing "client test app": a small Orchestra Workbench host
(`workbench/`) that adopts the package the way a real project would — seeded posts,
categories, tags, media, an email — plus an automated test
(`tests/Demo/AdopterPathTest.php`) and a script that screenshots the result
(`workbench/scripts/screenshots.mjs`).

It exists to answer one question precisely: **what does an adopter actually get for
free, and what do they still have to write themselves?** The short version — the block
editor, the media library, the email renderer, and a working bilingual public page are
real and work. Everything that makes a page look like a "blog" — layout, breadcrumbs,
reading time, an author box, share buttons, related posts, comments sorted the way your
own template says — is not wired to the template contracts Heisenberg ships, and you
write it yourself. See "What the host still has to write" below for the full,
evidenced list.

This demo also caught three real bugs in `src/`, reported back to the lead and fixed on
2026-09-19 — see "Found by this demo, fixed 2026-09-19" below for what they were and
how this workbench proves the fixes.

## Run it

From the package root (PowerShell or Git Bash):

```bash
# One-time: create the sqlite file (Laravel's sqlite driver refuses to connect
# to a path that doesn't already exist as a file).
# PowerShell: New-Item -ItemType File workbench\database\testbench.sqlite
touch workbench/database/testbench.sqlite

# Build the schema, then seed the demo content (posts, categories, tags,
# media, one email). Both are idempotent-ish: migrate:fresh drops and
# rebuilds; demo:seed can be re-run but will duplicate rows (it doesn't
# check for existing content — see "Known rough edges" below).
php vendor/bin/testbench migrate:fresh
php vendor/bin/testbench demo:seed

# Start the dev server (pick any free port).
php vendor/bin/testbench serve --port=8972
```

Then visit:

| URL | What it is |
|---|---|
| `/blog` | The demo host's own blog index (workbench code) |
| `/blog/en/getting-started-with-widgets` | The demo host's own post page, English — every template capability wired by hand |
| `/blog/fr/getting-started-with-widgets` | The SAME post in French — same row, same URL shape, `{locale}` segment alone decides the language |
| `/posts/en/getting-started-with-widgets` | The PACKAGE's bundled public route (`heisenberg.public.routes`), zero host code |
| `/posts/fr/getting-started-with-widgets` | The bundled route in French — same single row (fixed 2026-09-19; see below) |
| `/editor` | The block editor, unauthenticated (local-dev bypass) |
| `/editor/1` | The seeded rich article, open in the editor |
| `/editor/email/4` | The seeded email, open in the editor |
| `/editor/media` | The media library, showing the two seeded images |
| `/demo/email-preview/widgets-weekly-welcome` | The seeded email rendered via `EmailRenderer` directly — HTML + plain text side by side |

Row ids (`1`, `4`, …) come from `demo:seed`'s own insert order on a fresh database —
there is no "browse all posts" screen in the shipped editor UI to click through from,
so this doc just states them. Query them yourself with:

```bash
php -r '$pdo = new PDO("sqlite:workbench/database/testbench.sqlite"); foreach ($pdo->query("SELECT id, locale, slug, type, status FROM heisenberg_posts") as $r) { echo json_encode($r), PHP_EOL; }'
```

Stop the server with Ctrl+C (or, on Windows, find the PID listening on your port with
`netstat -ano | findstr :8972` and `taskkill /F /PID <pid>`).

## Regenerate screenshots

With the server running (as above):

```bash
node workbench/scripts/screenshots.mjs http://127.0.0.1:8972
```

Uses the root `node_modules/playwright` install. If Chromium isn't installed for it:

```bash
node node_modules/playwright/cli.js install chromium
```

Screenshots land in `docs/screenshots/` (1440×900, deviceScaleFactor 1 — file sizes
were already 90-110KB at scale 1, so scale 2 was skipped to keep them small).
`public-post-hero.png` is the one exception: a viewport-only (`fullPage: false`)
capture at the same 1440×900 size, meant to sit next to the editor screenshots without
the page's full scroll height. The script tries to click a canvas block and a
dark-mode toggle; if either selector stops matching after a future editor UI change, it
logs a warning instead of failing and just skips that one screenshot — re-run with
manual inspection of `editor-post.png` to find the new selector.

Current screenshots:

| File | Shows |
|---|---|
| `public-post-bundled.png` | The bundled `/posts/en/{slug}` route, English — full page |
| `public-post-fr.png` | The bundled `/posts/fr/{slug}` route, French — full page, same row as the English one |
| `public-post.png` | The workbench's own `/blog/en/{slug}` route — full page, every capability wired |
| `public-post-hero.png` | The same workbench page, viewport-only (1440×900): header, breadcrumbs, title, reading time, featured image, start of the TOC |
| `blog-index.png` | The workbench's `/blog` index |
| `media-library.png` | `/editor/media` with the two seeded images |
| `editor-post.png` | `/editor/1`, the seeded rich article loaded |
| `editor-post-block-selected.png` | Same page, a heading block selected — inspector shows its Text/Level/Anchor fields |
| `editor-dark-mode.png` | Same page, dark chrome toggled |
| `editor-email.png` | `/editor/email/4`, the seeded email, showing `{{ user.first_name }}` as a variable chip |
| `email-preview.png` | The workbench's own `EmailRenderer` preview route — HTML + plain text side by side |

## Run the automated proof

```bash
php vendor/bin/phpunit tests/Demo
```

10 tests, 53 assertions, all passing as of this task. It seeds via the exact same
`Workbench\Database\Seeders\DemoSeeder` class `demo:seed` runs (`require_once`'d
directly — see "composer.json lines" below), then asserts:

- the bundled `/posts/{locale}/{slug}` route returns 200 in English AND French — the
  SAME single `heisenberg_posts` row, asserted directly (`Post::where('slug', ...)
  ->count() === 1`) — and actually contains BlockRenderer output (a heading's anchor
  id, `hb-block-*` classes), not just a 200;
- `/posts/fr/{slug}` (no `?locale=` needed) renders the French title and French block
  text (`content_fr`/`citation_fr` attribute variants) and does NOT contain the
  English title or English body text — this is genuinely the French render, not the
  English row with a French-looking title bolted on;
- the bundled route no longer shows the editor's "Preview" bar (regression guard for
  the fixed bug — see below) but STILL renders none of the template's OTHER declared
  capabilities (no breadcrumbs, reading time, author box, related posts) — that finding
  stands;
- the workbench's own `/blog/{locale}/{slug}` DOES render breadcrumbs, reading time,
  TOC, featured image, author box, 5 share-network links, related posts, and comments
  sorted by the template's own `sortOrder` (`oldest`, not the hardcoded `newest` the
  bundled controller uses) — with the pending comment excluded — in BOTH English and
  French, from the one row;
- draft posts 404 on both the bundled AND the workbench route;
- the email renders HTML + plain text with `{{ user.first_name }}`/`{{ unsubscribe_url }}`
  preserved verbatim, and a `<script>` tag injected directly into a block's stored
  attribute (bypassing the editor's own save-time sanitizer entirely) is still stripped
  by `BlockRenderer`'s own render-time rich-text sanitizer — a genuine second line of
  defense, not just a hope that save-time sanitization was correct.

## Found by this demo, fixed 2026-09-19

Building this demo caught three real bugs in `src/`. They were reported to the lead and
fixed the same day; this workbench and `tests/Demo/AdopterPathTest` were updated to
exercise the FIXED behavior directly (see the previous section).

1. **The bilingual model was in two different states at once.**
   `docs/content-translation.md` §0 declares Heisenberg moved to a single-row bilingual
   model (one `heisenberg_posts` row, `title_en`/`title_fr` + block `_<locale>`
   attribute variants), and `TranslationStatusService` was already built entirely
   against that model — but `PostPublicController::show()` still looked a post up with
   `where('locale', $urlLocale)`, so a single row was only ever reachable at ONE of its
   two locale URLs. **Fix:** `PostPublicController::resolvePost()` now tries the row
   whose own `locale` matches the URL first, then falls back to the slug's row IF it
   has a `title_<locale>` — the same "has content" test `alternatesPayload()` already
   used, so every hreflang link this page advertises now actually resolves.
2. **The URL's `{locale}` segment didn't drive rendering.** Even when the right row was
   found, `PostPublicController` rendered it with `app()->getLocale()` (the framework's
   global app locale), changed only by an explicit `?locale=` query override —
   `/posts/fr/{slug}` could silently show the English title on a row that had both
   columns populated. **Fix:** `show()` now calls `app()->setLocale($locale)` from the
   URL segment itself before rendering; `?locale=` still works as an override on top.
3. **The public route showed the editor's "Preview" bar.** `PostPublicController`
   rendered the exact same `heisenberg::preview` view the editor's own preview tab
   uses, banner and all — a real visitor saw "Preview — close this tab to return to the
   editor" copy that made no sense to them. **Fix:** the controller now passes
   `'previewBar' => false`.
4. **(Related, same day) The sitemap / hreflang alternates pointed at the editor by
   default.** `SeoUrlResolver::url()` fell back to `route('heisenberg.editor.preview.post',
   ...)` — the `/editor/{id}/preview` URL — whenever `heisenberg.seo.url_template` was
   left unset, even after `heisenberg.public.routes` was turned on. **Fix:** it now
   falls back to the bundled `heisenberg.public.posts.show` route instead, when that
   route exists. `workbench/config/heisenberg.php` still sets `seo.url_template`
   explicitly (belt-and-suspenders, no longer load-bearing) — see that file's comment.

`workbench/database/seeders/DemoSeeder.php` originally worked around bugs 1-2 by
creating a SEPARATE `Post` row per locale (the older split-row model) — that workaround
is gone now that the bugs are fixed; the seeder creates ONE row per bilingual post, as
docs/content-translation.md §0 always intended.

## What this task found (task 1: the render path investigation)

### PostTemplateRegistryService is discovery/validation only

`src/Services/PostTemplateRegistryService.php` scans `resources/templates` (or
`config('heisenberg.template_root')`), validates each JSON contract, and serves a
localized, hashed registry envelope. That is genuinely useful (a host CAN discover and
validate its own template contracts with zero extra code) but it has **no `render()`
method and no consumer**. Outside a console command
(`src/Console/Commands/TemplatesVerifyCommand.php`, `php artisan templates:verify` —
validation only) and tests, nothing in `src/` ever calls it.

### Nothing dispatches to a template's `render.view`

`resources/templates/article/article.json`'s `render.view` is `"theme::posts.article"` —
a view namespace the package never registers. `PostPublicController::show()` and
`PreviewController::showPost()` both hardcode `view('heisenberg::preview', [...])`
regardless of which template (if any) a post is supposed to use. There is not even a
`template` column on `heisenberg_posts`
(`database/migrations/2026_01_01_000001_create_heisenberg_posts_table.php`) to record
which contract a given post is meant to render through. A template contract is
consequently "a validated, verifiable, entirely inert JSON file" — exactly what the
project's own old notes said (see this task's brief) — and this workbench's own
`heisenberg/blog` template (`workbench/resources/heisenberg-templates/blog/post.json`)
is read and applied ONLY because `workbench/routes/web.php` does it by hand.

### Of 11 declared capabilities, the shipped preview view implements 3, and only partially

`resources/views/preview.blade.php` — the SAME view both the editor preview and the
public route render through — hardcodes:

- **featuredImage**: renders unconditionally if `$post->featuredImage` is set; ignores
  the capability's own `enabled`/`context`/`fallback` fields entirely.
- **tableOfContents**: renders `Post::tocEntries()` (the AUTHORED list, i.e.
  `source: "entries"`) with a hardcoded "Contents" heading, ignoring the capability's
  own `title` field, and with no per-locale `label_<locale>` column at all (see
  `TocEntry`'s migration) — its labels are the same text in every locale, even after
  the locale-resolution fix above. A template declaring `source: "headings"` (derive
  the TOC from the block tree at render time) gets nothing — nothing in `src/` derives
  headings into a TOC anywhere.
- **comments**: renders a real, working comment thread + submit form, but the sort
  order is hardcoded to `'newest'`
  (`src/Http/Controllers/PostPublicController.php`) and `allowGuests` comes from
  `config('heisenberg.comments.allow_guests')`, not the template's own
  `comments.sortOrder`/`comments.allowGuests` fields. Comment bodies are also not
  locale-scoped (a single thread is shared across languages, by design per
  docs/content-translation.md §0).

The other 8 capabilities the schema defines — **breadcrumbs, readingTime, authorBox,
shareButtons, pagination, postViews, relatedPosts**, and template-driven **seoMeta**
(the real SEO meta comes from `PostSeoMetaProvider`, unrelated to the template
capability of the same name) — are never rendered anywhere in `src/`. A host writes
every one of them itself; `workbench/routes/web.php` does exactly that, by hand, to
demonstrate the amount of work involved (roughly 100 lines for 7 capabilities).

## What the host still has to write

Based directly on the above, following README's own "Post Templates and Page
Rendering" section to the letter gets you a `PostTemplateRegistryService` you can
query and a `BlockRenderer::renderBlocks()` call — everything else in
`workbench/routes/web.php` and `workbench/resources/views/blog/*.blade.php` is what
you write yourself:

- **The whole page shell** — header, nav, footer; the bundled public route ships a
  bare `<main>` with no site chrome at all.
- **Breadcrumbs, reading time, an author box, share buttons, related posts,
  pagination** — declared in every template contract's `capabilities`, rendered by
  none of them.
- **Dispatching to a template's `render.view` at all** — there is no mechanism; you
  read the template JSON yourself (as this workbench does) and decide what to do with
  it.
- **A blog index / listing page** — nothing ships one.
- **Per-locale table-of-contents labels** — `TocEntry` has one `label` column, no
  `label_<locale>` variants, so an authored TOC is the same text in every language
  whichever locale the visitor is reading.
- **A public "browse all posts" / "open this post in the editor" affordance** — this
  doc had to query sqlite directly to find a post id to open in `/editor/{id}`.

What genuinely works out of the box, no host code required (as of 2026-09-19): the
block editor and its full authoring chrome (`/editor`), the media library
(`/editor/media`), category/tag CRUD, comment moderation, the email editor +
`EmailRenderer` (HTML/text/embeds, render-time script stripping as a second line of
defense beyond save-time sanitization), and — with `heisenberg.public.routes` turned
on — a genuinely functional (if unstyled, un-chrome'd) published-post page that
correctly serves BOTH locales of a single-row bilingual post at their own clean URLs,
with real SEO head tags, hreflang alternates that resolve to real, working pages, a
working native comment thread, and sanitized block HTML.

## Files in this demo

- `workbench/config/heisenberg.php` — turns on `public.routes`, points
  `template_root` at `workbench/resources/heisenberg-templates`, sets
  `seo.url_template` (now belt-and-suspenders — see its own comment). Loaded before
  `HeisenbergServiceProvider::register()` via `workbench.discovers.config: true`
  (testbench.yaml) — the workbench analogue of publishing `config/heisenberg.php` in a
  real app.
- `workbench/config/database.php` — see its own docblock: replaces the whole
  skeleton database config with one that resolves the sqlite path via `__DIR__`,
  because `testbench serve`'s built-in PHP server runs with the vendor skeleton's
  `public/` directory as its cwd, not this package's root — a relative `DB_DATABASE`
  (the natural thing to put in testbench.yaml) silently degrades to a fresh
  `:memory:` connection per request otherwise (see "Known rough edges" below).
- `workbench/resources/heisenberg-templates/blog/post.json` — the demo host's OWN
  template contract (distinct from the package's bundled `heisenberg/article`).
- `workbench/database/seeders/DemoSeeder.php` — plain PHP class, NOT PSR-4
  autoloaded (see "composer.json lines" below); `require_once`'d wherever it's used.
  Seeds each bilingual post as ONE row (single-row model) with `_fr`-suffixed block
  attribute variants — see its own docblock.
- `workbench/routes/web.php` — `/blog`, `/blog/{locale}/{slug}`,
  `/demo/email-preview/{slug}`. Read its own docblock before editing: it must never
  declare a named class/function (it's `require`d, not `require_once`d, so a fresh
  Router gets fresh routes every time — including once per PHPUnit test method).
  Resolves a post the same way `PostPublicController::resolvePost()` does (own
  `locale` matches the URL, else "has a `title_<locale>`").
- `workbench/routes/console.php` — the `demo:seed` Artisan command, as a closure for
  the same "no autoloading" reason.
- `workbench/resources/views/blog/*.blade.php` — the host's own layout, blog index,
  post page, and email-preview page.
- `tests/Demo/AdopterPathTest.php` — the automated proof (see above).
- `workbench/scripts/screenshots.mjs` — the screenshot script (see above).

## composer.json lines the lead should add (not added by this task — see ground rules)

None are REQUIRED — this task deliberately structured everything (closures in
`workbench/routes/*.php`, `require_once` for `DemoSeeder`) to work without any
autoload-dev change, per this task's own ground rules. If the lead wants the more
idiomatic Orchestra Workbench experience (a real `Workbench\App\...`/
`Workbench\Database\Seeders\...` namespace, PHPStorm/PHPStan resolving these classes,
`artisan make:*` scaffolding working inside `workbench/`), add:

```json
"autoload-dev": {
    "psr-4": {
        "Heisenberg\\Tests\\": "tests/",
        "Workbench\\App\\": "workbench/app/",
        "Workbench\\Database\\Seeders\\": "workbench/database/seeders/",
        "Workbench\\Database\\Factories\\": "workbench/database/factories/"
    }
}
```

(`workbench/app/` and `workbench/database/factories/` don't exist yet in this task's
output — they're included so `Orchestra\Testbench\Workbench\Workbench::detectNamespace()`
resolves consistently if either directory is added later.) After adding this, run
`composer dump-autoload`, and `workbench/database/seeders/DemoSeeder.php` would no
longer need the `require_once` calls in `workbench/routes/console.php` and
`tests/Demo/AdopterPathTest.php` (though leaving them in is harmless).

## Known rough edges hit while building this

- **`testbench serve` + a relative `DB_DATABASE`** (still an issue, not part of the
  2026-09-19 fixes above — this is a Testbench/dev-environment footgun, not a
  Heisenberg bug): see `workbench/config/database.php`'s docblock. This was a
  pre-existing footgun in the ORIGINAL (pre-this-task) `testbench.yaml`, which set
  `DB_DATABASE=database/testbench.sqlite` (also relative) — every request against
  `testbench serve` was silently getting a fresh, table-less `:memory:` database, not
  the seeded file. This task's `workbench/config/database.php` fixes it for the
  workbench app; the same class of bug would still bite a real adopter who copies that
  testbench.yaml pattern into their own `DB_DATABASE` env var for local dev.
- **`demo:seed` is not idempotent**: running it twice creates duplicate posts (no
  "already seeded" guard). Run `migrate:fresh` first if you need a clean slate.
- **The embed block (YouTube) renders as an empty box** in the screenshots. This is
  NOT a network or CSP problem — the iframe's `src` is a correctly-formed
  `https://www.youtube-nocookie.com/embed/<id>` URL, no Content-Security-Policy header
  is sent, and the iframe genuinely loads (confirmed via Playwright's own frame list
  and network trace). YouTube's embedded player itself reports "This video is
  unavailable" inside a headless/automated Chromium session — very likely bot/headless
  detection on YouTube's side, unrelated to Heisenberg. `tests/Demo` asserts the
  correct markup/`src` directly rather than relying on the video actually rendering.
- **Livewire component tags can get stuck uncompiled in the shared Blade view cache**
  (`vendor/orchestra/testbench-core/laravel/storage/framework/views/*.php`) if a
  stale compiled view was cached before `Livewire\LivewireServiceProvider` was in the
  active provider set for a given process. This bit the `/editor/media` screenshot
  during this task (the `<livewire:heisenberg.media-library>` tag rendered as literal
  text instead of the component) — fixed by deleting the two specific stale compiled
  files (identified by grepping for the literal tag text), not by clearing the whole
  cache. If a screenshot ever shows a raw `<livewire:...>` tag again, that's the cause.

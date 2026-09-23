# Upgrading

Heisenberg is pre-1.0. **Versioning policy: `0.0.x` releases may contain breaking changes.**
Every breaking or upgrade-relevant change will be listed here, in the version you're upgrading
past — read every section between your current version and your target, not just the target's.

Migrations ship inside the package and run automatically via `loadMigrationsFrom()` — you do not
need to publish or copy them. `php artisan migrate` after `composer update` is normally enough;
sections below call out anything that additionally needs your attention (a data backfill, a
config key, a behavior change).

If you've published `config/heisenberg.php` (`php artisan vendor:publish --tag=heisenberg-config`),
run `php artisan heisenberg:config-diff` after every upgrade. The package deep-merges its
defaults into your published config, so a **missing** key always reaches you automatically — but
a key that exists on **both** sides, where the package changed a *list's contents* (not just
added a sibling key), does not: your old list wins verbatim, silently. `config-diff`'s `differs`
section is where that shows up; a config-diff run is the only way to catch it.

## 0.0.9 (2026-09-23)

**Email HTML changed again, in two ways a snapshot test will notice.** Every `font-family` now
leads with the theme's real family before its web-safe fallback (`'Space Grotesk', Arial,
Helvetica, sans-serif` rather than `Arial, Helvetica, sans-serif`), and the document carries a
`<link>` to the theme's faces so clients that load webfonts render what the author picked. Nothing
regresses where webfonts cannot load: the same web-safe stack follows in every declaration. A host
that snapshot-tests `EmailRenderer` output should re-record. Fallbacks are also now chosen from the
font's own catalog category rather than by keyword-matching the token's name, so a token called
`font-serif` holding a sans family no longer ships Georgia.

**`icon` blocks now render in email.** The block joins the email palette and ships its glyph as an
inline `cid:` PNG the editor rasterizes. Those PNGs are written to the media disk under
`email-icons/` and deliberately have no media-library row, so they never appear in the author's
Media panel; a host that syncs or prunes that disk should leave that directory alone. Nothing is
required to adopt this, and an icon with no rasterized PNG renders nothing rather than a broken
image.

**New optional config key: `dashboard_url`.** The topbar's home button used to point at
`site_url`; it now resolves `heisenberg.dashboard_url` first, which takes a single URL or a map
keyed by your own role names. It is additive and deep-merged, so it reaches a published config
automatically, and leaving it unset keeps the previous behaviour exactly.

## 0.0.8 (2026-09-22)

**Breaking — email HTML has a new structure.** Every email block is now a margin cell around a box
cell (margins ship as cell padding), and layout is table cells rather than a flat sequence. The
result renders the same or better in a client, but the markup is different: a host that snapshot-
tests `EmailRenderer` output, post-processes it, or ships its own `email.template` contracts should
re-check them. Contracts gain an additive `"flow"` on `inner-blocks` nodes and `omitWhenEmpty` on
`enumMap` attributes; existing contracts keep working, they just do not get the new layout handling.
A registry-hash change also means an editor tab opened before the upgrade needs a reload.

A block nested inside a container (a group, column or columns child) also loses the fixed
bottom-spacing default it used to carry unconditionally — that default exists for a block sitting
directly on the document root, where nothing else spaces it from its neighbour, and was
previously stacking on top of the container's own padding and gap with no way to cancel it. A
document with nested blocks will render measurably tighter after upgrading; an explicit margin an
author actually set still applies. `BlockTreeRenderer::renderBlockAtDepth()`/`renderJsonBlock()`
gained an optional `$hint` array (render-pass-only attributes such as `_emailAlign`, `_emailValign`,
`_emailHug`, `_emailNested` — never persisted); a custom `email.template` contract that does not
read these hints is unaffected.

**Breaking — MCP and AI writes containing markdown are rejected.** `write_canvas`, `create_post`,
`update_post` and the other tools that take shortcode now fail (with a line-numbered message naming the
fix) when a text field contains a markdown list, a `#` heading, `**bold**` or a code fence. Clients
that used to send those got the characters printed on the page; they now have to resend real blocks.
`\n`, `\r\n` and `\t` in a quoted value or tag body are now read as whitespace instead of printed. Hand-
typed Code view is unchanged. `ShortcodeParser::parse()` gained an optional `$strict` argument
(default `false`), so direct callers are unaffected.

**Breaking — device visibility toggles were replaced.** Blocks used to have six exclusive bands
(`hideXs` … `hideXxl`); they now have three cumulative ones (`hideMobile`, `hideTablet`,
`hideDesktop`). Posts saved with the old attributes keep them, but **they no longer hide anything**;
stored content is not migrated. If you relied on the old toggles, re-set the new ones on those blocks.

**New config — `heisenberg.site_url`** (`HEISENBERG_SITE_URL`). Set it when Heisenberg is mounted on
a different host than your readers use (an admin subdomain); canonical, sitemap and hreflang URLs
use it instead of the request host. It falls back to `app.url`, and Laravel's default
`http://localhost` counts as unset. The editor topbar's home button now also points at it —
previously a bare `<button>` with no `href` at all, so this is the first release where clicking it
navigates anywhere; with neither `site_url` nor `app.url` set it stays inert, as before.

**Changed — the inbound MCP server speaks the Streamable HTTP transport.** It negotiates a protocol
version on `initialize` and validates `MCP-Protocol-Version` afterwards. The tool catalogue is
byte-identical.

**Behavior change — outbound AI/MCP URLs are now SSRF-guarded.** Outside `APP_ENV=local`, an MCP
server URL or a custom provider `base_url` that resolves to a loopback / RFC1918 / CGNAT / IPv6-ULA
address is now **rejected**, both on save and at request time. If you run an MCP server or a
self-hosted model (Ollama, vLLM, …) on localhost or inside your VPC in production, opt in with
`heisenberg.ai.outbound.allow_private_networks => true`, or list the specific hostnames in
`heisenberg.ai.outbound.allowed_hosts`. Link-local / cloud-metadata addresses (169.254.0.0/16,
`fe80::/10`) are blocked unconditionally, non-canonical numeric IPv4 hosts (`2130706433`,
`127.1`) are refused, and these requests no longer follow HTTP redirects — an MCP endpoint that
relied on a 301/302 must now be configured with its final URL.

**Behavior change — SVG uploads need a sanitizer.** `.svg`/`.svgz` uploads are refused unless you
bind a `Heisenberg\Contracts\SvgSanitizer` via `heisenberg.media.svg_sanitizer`, even if `svg` is
in `heisenberg.media.extensions`. They were not in the default allowlist, so this only affects
hosts that added them by hand.

**Fixed — saved-pattern endpoints require the `authors` tier.** `/editor/patterns` list / save /
delete previously had no role check. Users outside `heisenberg.roles.authors` (e.g. `viewer`) and
anonymous visitors now get a 403. If you rely on `middleware.editor` alone for access control,
make sure your editors map to a role in that tier.

**Fixed — Livewire media library authorization.** The `heisenberg::media-library` component now
authorizes `media.viewAny` before listing or selecting files, matching `MediaLibraryController`.
Actors without that ability now see an empty library instead of the full listing.

**Fixed — bundled public route (`heisenberg.public.routes`) now matches the single-row bilingual
model.** `GET /posts/{locale}/{slug}` used to require the row's own `locale` column to equal the
URL's, so an English-authored post with a French translation 404'd at `/posts/fr/{slug}` — while
the page's own hreflang alternates pointed there. Now the same row answers at every locale it has
a title in, the URL's `{locale}` drives rendering (`?locale=` still overrides), and a locale the
post was never translated into 404s. The editor's "Preview" bar is no longer rendered on this
public page. If you override `heisenberg::preview`, note the new optional `$previewBar` variable.

**Behavior change — default post URLs.** With `heisenberg.public.routes` enabled and no
`heisenberg.seo.url_template`, `SeoUrlResolver` (sitemap, canonical, hreflang) now emits the
public route instead of `/editor/{id}/preview`. Hosts with a `url_template` or their own
`PostUrlResolver` are unaffected.

**New config keys** (all have working defaults): `heisenberg.ai.outbound.*`,
`heisenberg.media.svg_sanitizer`, `heisenberg.warn_anonymous_in_local` (silences the new
throttled log warning emitted while the local anonymous bypass is active).

**Schema: `heisenberg_posts.locale` is no longer a database ENUM.** Migration
`2026_09_19_000001_convert_heisenberg_posts_locale_enum_to_string` turns it into a plain
`string(8)` with the same default (`'en'`) and the same `['locale','slug']` unique index; data is
preserved. Which locales are legal is now purely an application concern
(`config('heisenberg.locales')` via `Heisenberg\Support\LocaleConfig`) — the database no longer
rejects an unsupported locale on its own. No action is needed unless you insert into
`heisenberg_posts` with raw SQL / `DB::table()`: those writes must now validate `locale`
themselves (every first-party write path already does). This is **not** N-locale support —
titles and excerpts are still paired `_en`/`_fr` columns. SQLite only: the table rebuild also
drops the redundant DB-level CHECK on `status` (application validation is unchanged). Rolling
back refuses to run if any row holds a locale other than `en`/`fr`.

## [0.0.7] — 2026-09-19

No schema or config changes. Behavior: AI system prompt compressed (~27% smaller) — no action
needed unless you depend on exact prompt text. New `search_web` AI/MCP tool and an external
image-URL input on the image block's Content tab — additive, nothing to migrate.

## [0.0.6] — 2026-09-19

**Config change, not additive — check if you've published your config.** Between v0.0.5 and
v0.0.6, `config('heisenberg.email')` lost `batch_max_recipients` and `heisenberg.roles` lost the
`email.generate` entry — both backed a per-recipient batch-export feature that was removed as
part of this release (see "Behavior removed" below). In exchange, `email.variables` was added: an
array of
`{key, label, description, group}` entries so the editor can label recognized `{{ tokens }}`. If
you have a published config predating this, `heisenberg:config-diff` will show `batch_max_recipients`
and `email.generate` as stale-but-present (the deep merge cannot remove keys a host still has) —
safe to delete them manually, they do nothing now.

**Behavior removed — read this even if you don't publish config.** The email
host-integration layer was dropped: `EmailVariableRegistry`, `EmailVariableInterpolator`,
`EmailBatchExporter`, the six built-in `EmailVariableType` formatters, `EmailBatchExportController`,
and the `POST /editor/email/{post}/batch-export` route are all gone. `HeisenbergMailable`'s
constructor signature changed — **its third argument (the per-recipient value map) is gone**;
the signature is now `(int|string $postId, ?string $locale)`. If your host code called
`new HeisenbergMailable($post, $locale, $recipientValues)`, drop the third argument and do your
own substitution against the rendered `{html, text, subject}` before sending. `docs/email-personalization.md`
and `examples/EmailVariables/` were deleted; see `docs/email-system.md` §6 for the current model
(Heisenberg renders `{{ tokens }}` verbatim, the host substitutes at send time).

Also added: SEO & Social live preview panel (editor-only, no schema/config impact).

## [0.0.5] — 2026-08-27

No schema or config changes. Fixed `heisenberg_csp_nonce()` to call `Vite::cspNonce()` instead of
`Vite::useCspNonce()` — the latter generated a **fresh nonce on every call**, which could mismatch
your CSP header if you were relying on it. If your own Blade templates called
`Vite::useCspNonce()` directly for anything Heisenberg-adjacent, switch to `Vite::cspNonce()` too.

## [0.0.4] — 2026-08-27

No schema changes. Added CSP nonce support: every inline `<style>`/`<script>`/`<link rel=stylesheet>`
Heisenberg emits now carries `nonce="{{ heisenberg_csp_nonce() }}"`. No action needed unless you
enforce a strict CSP without `'unsafe-inline'` — in that case this release is what makes
Heisenberg work under it at all; see the README's CSP section.

## [0.0.3] — 2026-08-27

**Blade component namespace change.** Heisenberg's anonymous components moved under the
`heisenberg::` namespace prefix (`<x-heisenberg::ui.*>`, `<x-heisenberg::live.*>`) to stop
generic host component names (`ui.tabs`, `ui.button`) from shadowing the editor's own. If you
published or overrode any Heisenberg Blade component under the old unprefixed names, re-point
those overrides at the `heisenberg::` namespace. Also replaced hard-coded `/heisenberg-assets/`
URLs with named routes — irrelevant unless you were linking those paths directly yourself.

## [0.0.2] — 2026-08-27

**The big one.** Most of the schema and config Heisenberg carries today arrived in this release.
If you're upgrading from v0.0.1, read this whole section.

### Schema (new tables/columns, additive — no data loss on upgrade)

- `heisenberg_comments` (new table) — threaded comments.
- `heisenberg_posts.translated_from_version` (new column) — tracks which source revision a
  translation was translated from.
- `seo_meta` (new table, unprefixed) — polymorphic SEO metadata.
- `heisenberg_posts.type` (new column, default `'post'`) — distinguishes posts from emails.
- `heisenberg_patterns` (new table) — saved block patterns.

All five are additive `CREATE TABLE` / `ADD COLUMN` migrations; nothing is dropped or backfilled
destructively. (The category→pivot conversion below is the one migration in this window that
*does* drop a column, but it shipped before v0.0.1 — see the note at the end of this section.)

### Config — deep-merge covers new keys, but two things need a manual look

Config went from ~220 lines to ~470. The deep merge (`ConfigMerge`, added in this same release)
means an **absent** key reaches a published host config automatically — but two changes in this
release are exactly the shape deep-merge can't fix, because the key already existed:

1. **`lifecycle.transitions` changed shape.** `draft` gained direct edges to `published` and
   `scheduled` (previously only reachable via `pending_review`); `published` gained an edge back
   to `draft`; `archived` gained an edge to `published`. If you published your config before this
   release, your `lifecycle.transitions.draft` list is frozen at its old contents forever (deep
   merge treats a list as one atomic value) — **before v0.0.2, an admin had no path to publish a
   draft directly at all**. Compare your published `lifecycle.transitions` against the package
   default and update by hand if you want the new edges.
2. **`post_template.comments_provider` and `post_template.seo_meta_provider` changed their
   default bindings** from `NullPostCommentProvider`/`NullPostSeoMetaProvider` to
   `NativeCommentProvider`/`NativeSeoMetaProvider`. If you had explicitly bound the Null adapters
   yourself (to keep comments/SEO off), that's unaffected — your explicit value always wins. If
   you had *not* set these keys and your config predates this release, deep-merge will pick up
   the new native defaults automatically on next boot — meaning **comments and SEO metadata
   switch on** for any post going forward. If you don't want that, bind the Null adapters
   explicitly.

New top-level config sections (all additive, safe to leave at their shipped defaults):
`locales`, `default_locale`, `comments`, `translations`, `seo`, `public` (opt-in public post
route — `routes: false` by default, still `false` today), `email` (routes on by default,
`route_prefix: 'emails'`), plus `roles.comments.moderate` and (removed again in v0.0.6, see
above) `roles.email.generate`.

Run `php artisan heisenberg:config-diff` after upgrading past this release if you've ever
published `config/heisenberg.php`.

### Behavior

- Single-row bilingual content became the permanent model (a transient split-row-per-locale
  design was tried and reverted entirely within this same pre-release window — it never shipped
  in a tagged release, so there is nothing to migrate away from). See `docs/content-translation.md`.
- `heisenberg:merge-translations` console command added, for hosts who had accumulated
  split-row-style duplicate posts through local development against unreleased code — most hosts
  upgrading from a tagged v0.0.1 will have nothing for it to do.
- `heisenberg:warm` console command added, to precompile shipped Blade views.

### Also technically pre-v0.0.1-tag but worth knowing about

The categories-to-pivot conversion (`heisenberg_category_post` created, `heisenberg_posts.category_id`
backfilled into it and then dropped) happened before the v0.0.1 tag, so anyone starting from
v0.0.1 already has the pivot-based shape — nothing to do. It's mentioned here only because it's
the kind of migration (drops a column after backfilling) this policy exists to warn about, and
because its `down()` is explicitly lossy (a post with more than one category after the forward
migration restores only one, arbitrarily, on rollback).

## [0.0.1] — 2026-08-10

First public release. No upgrade path — this is the starting point.

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

## 8. Out of scope (recorded)

Subscriber management, campaign sending/scheduling/tracking, open/click analytics, MJML
interop, per-client conditional comments beyond the minimal Outlook shims the shell needs.

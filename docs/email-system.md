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

### Getting a built email OUT of the editor

Heisenberg renders and the host sends — but before a host is ready to wire up its own mailer, or
for the common case of pasting a built email straight into an ESP (Mailchimp, Klaviyo, …), the
editor also exports. `EmailPreviewController` (routes/editor.php), gated exactly like
`email-preview`/`email-size` above (PostPolicy `view`) plus a 404 for a non-email post:

- **`GET /editor/{post}/email-export?format=html`** — the ESP paste/upload case. Renders through
  the SAME `preview: true` path the browser preview uses: images are absolute, publicly-fetchable
  URLs, never `cid:` references, because a platform ingesting raw HTML has no MIME parts to
  resolve them against. Downloads as `<slug>-<locale>.html` (`Content-Disposition: attachment`).
- **`GET /editor/{post}/email-export?format=eml`** — the self-contained case. Builds a real
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
way as the preview/size URLs).

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

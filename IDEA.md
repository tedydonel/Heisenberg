# Heisenberg

**An embeddable content + email engine for Laravel, with AI and MCP built in.**

Heisenberg drops a block-based authoring editor, a bilingual content model, and an email
builder into an existing Laravel application — without asking that application to adopt a
CMS, a user system, or a frontend framework it didn't already choose.

## Who it's for

A Laravel developer who already has an app, already has users, and needs their app to grow
a blog, a docs section, or transactional/marketing email — without bolting on WordPress,
without wiring up Filament, and without inheriting a second admin panel or a second concept
of "user" to keep in sync with their own.

## What it is

- A **block editor** (Gutenberg-style authoring, not Gutenberg's implementation) that reads
  JSON block contracts and renders the same content two ways: live in the browser while
  authoring, and server-side (sanitized, escaped) when published. One block engine, one
  source of truth per block.
- A **bilingual-native content model**: English and French live on one row, not as separate
  translated documents that drift apart. Locale is a first-class citizen of the schema, not
  an afterthought bolted on later.
- An **email authoring surface** on the *same* block engine and the *same* editor chrome —
  write a page and write an email with the same tools, the same media library, the same AI
  assistant, rendered to two different safe outputs (sanitized HTML for the web, inlined
  table-based MIME for inboxes).
- An **AI writing assistant** wired into the editor, and a **bidirectional MCP** integration:
  Heisenberg can call out to external MCP tools, and it can expose itself as an MCP server so
  other agents can author content through it.

## Differentiators

- **No users, no theme, no build step.** Heisenberg has no user model of its own — it asks
  your app's own user model a handful of yes/no questions through a small set of contracts
  (`RoleGate`, `MediaResolver`, …). It has no theme system to learn and no bundler to run: the
  editor ships as vanilla JS delivered inline from Blade, server-authoritative rendering, and
  CSS served directly — `npm run build` is never part of the story.
- **Bilingual by construction**, not by plugin. Content authoring, revisions, SEO, and the
  public render path all understand "this post has an English side and a French side" as a
  fact about the schema.
- **Posts and email share one engine.** No separate campaign builder, no separate template
  language — the same blocks, the same contracts, two render targets.
- **MCP in both directions.** Heisenberg is both an MCP client (its AI assistant can reach
  outside tools) and an MCP server (outside agents can reach in and author content), not just
  a wrapper around one LLM API.

## Non-goals

- **Not a full CMS.** No admin dashboard for site settings, no page-builder-for-everything
  ambition — Heisenberg owns content and email authoring, your application owns the rest.
- **No user or role management.** Roles map onto whatever tiers your app already has via
  `RoleGate`; Heisenberg never creates or owns a `users` table.
- **No theme system.** There is no concept of installable themes to browse, activate, or
  update — your application's layout stays your application's layout.
- **No Filament dependency.** Heisenberg does not require, assume, or integrate with Filament
  (or any other admin-panel package); its own editor is the only surface it ships.
- **No arbitrary N-locale support before 1.0.** Two locales (English and French) are a
  deliberate design decision for now, not a placeholder for infinite locales — broader
  locale support is explicitly out of scope until the bilingual model has proven itself.

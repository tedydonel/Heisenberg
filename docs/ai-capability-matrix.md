# AI Capability Matrix

Every action a user can take in the editor, and the MCP tool that now exposes it
to the assistant. This is the work-item-4 deliverable — the gaps that remain are
listed at the bottom, each with the reason it is deliberate rather than missing.

Tools are registered on `McpToolRegistry` and reach the in-editor assistant
through `HeisenbergToolSource` (namespaced `heisenberg__*`) and external agents
through the inbound MCP server (`routes/mcp.php`). Both share one registry, but
the surfaces split over content writes: the editor assistant writes the LIVE
page through `write_canvas` and is not offered the DB content-write tools at
all, while the external server keeps them and never sees `write_canvas` (see
"Surface split").

## Covered

| Editor capability | MCP tool | Notes |
|---|---|---|
| List available block types | `list_blocks` | Slugs are the valid shortcode tags. |
| Read a block's full contract | `describe_block` | Accepts `name` **or** `names[]` to batch several in one round. |
| **Write the live page** (build/edit the canvas in front of the user) | `write_canvas` | **Editor surface only.** Shortcode `code` + `mode` (`append`/`replace`); validated server-side against the live contracts, applied client-side by the AI panel when the tool frame arrives on the stream. Unsaved until the user saves. |
| List posts | `list_posts` | Newest first. |
| Read a post (shortcode + block JSON + `content_version`) | `get_post` | The `content_version` guards concurrent edits. Also returns `translations`: `{<locale>: {is_default, title, excerpt, blocks_translated, blocks_total, complete}}` — per-locale translation COMPLETENESS on this same row (docs/content-translation.md §0), not a sibling map. |
| Create a post | `create_post` | Content as shortcode `code` or raw `blocks`; validated + sanitized as the editor does. |
| Update a post's title/content | `update_post` | Now also accepts `slug`, `excerpt_en`/`excerpt_fr`, `locale`; honors `content_version`. |
| Set/change post **slug** | `update_post` | Previously auto-derived only. |
| Set/change **excerpt** (en/fr) | `update_post` | — |
| Set/change **locale** | `update_post` | — |
| Publish / schedule / archive / back-to-draft | `set_post_status` | **Editor surface only.** Mirrors `PostController::applyTransition`'s config-gated lifecycle rules exactly; `schedule` takes `scheduled_at`. |
| List categories / tags | `list_categories` / `list_tags` | — |
| Attach category / tag | `attach_category` / `attach_tag` | — |
| **Detach** category / tag | `detach_category` / `detach_tag` | New — mirrors attach. |
| **Create** a category / tag | `create_category` / `create_tag` | Name → slug; returns the new id. |
| **Update** a category / tag's bilingual name/description | `update_category` / `update_tag` | Supply at least one field (`name_en`/`name_fr`/`description_en`/`description_fr`, tags have no description); names capped at 255 chars, the columns' own limit. Both surfaces. |
| **Translate a post's fields into another locale** | `create_translation` | **Both surfaces, no draft-only restriction** (it edits fields of an existing post; it never creates a post or changes `status`). `{post_id, target_locale, title?, excerpt?, code?}`; at least one of the three. `title`/`excerpt` write straight to `title_<locale>`/`excerpt_<locale>` on the SAME row. `code` is validated exactly like `update_post`, then folded into the post's EXISTING blocks' translatable attributes as `_<locale>` variants, matched BY POSITION — the block tree itself is never replaced. A structural mismatch (block count, or a block name at any position/depth) is refused, naming where. Returns `{post_id, locale, complete, blocks_translated, blocks_total}` (same completeness shape as `get_post`'s `translations` map). See docs/content-translation.md §0/§6. |
| Set page layout (padding) | `set_page_layout` | Mirrors `PostSettingsController::updateLayout`. |
| Set discussion (allow comments) | `set_discussion` | Mirrors `updateDiscussion`. |
| List revisions | `list_revisions` | Ids + timestamps + type. |
| Restore a revision | `restore_revision` | Goes through the normal restore path — undoable. |
| Read the active theme's design tokens | `get_theme` | Returns the `--hb-t-*` variables so the assistant honors the site theme. |
| Render without saving | `render_preview` | Shortcode/blocks → HTML. |
| List media | `list_media` | Read-only. |
| **Update media metadata** (alt text, caption, credit) | `update_media` | Both surfaces. `{file_id, alt_text_en?, alt_text_fr?, caption_en?, caption_fr?, credit?}`; at least one field; alt/caption capped at the column's own length (255/500), credit at 255. Still read-only on bytes — `list_media`'s docblock. |
| **Set/clear a post's featured image** | `set_featured_image` | Both surfaces, no draft-only restriction (see Surface split). `{post_id, file_id?}`; omitting/nulling `file_id` clears it; a non-null id must be a real, image-type `PublicFile`. Mirrors `PostSettingsController::updateFeaturedImage`. |
| **Read a post's SEO/social metadata** | `get_seo` | Both surfaces. Full `SeoMeta` row (both locales) + `has_seo`; `null` when unset. Does not run the analyzer. |
| **Set SEO/social metadata** | `update_seo` | Both surfaces. `{post_id, locale?, meta_title?, meta_description?, og_title?, og_description?, og_image?, canonical_url?, robots?, focus_keyphrase?, in_sitemap?, schema_type?, schema_data?}`; `locale` (default the post's own) routes the localized fields to their `_{locale}` column, the rest are locale-neutral; `updateOrCreate`s on `(able_type, able_id)`; at least one field; strings capped at 255; `robots` limited to comma-separated `index`/`noindex`/`follow`/`nofollow` tokens; `schema_data` must be a JSON object. |
| **Score a post's SEO** | `analyze_seo` | Both surfaces. `{post_id, locale?}` → `SeoAnalyzer::analyze()`'s `{score, rating, checks[]}` verbatim (docs/seo-system.md §4), against the post's SAVED state — no draft overrides (that's the editor panel's own live-scoring path, not this tool). |

### Whole-document reach — the direct code path everywhere

Both surfaces write content the same way: as shortcode, whole-document. The
in-editor assistant reaches any point of the live document through
`write_canvas` (`append` for additions, `replace` with the full updated
shortcode for edits — the current document arrives in its context every turn,
so it always edits from the real state). External agents do the equivalent
against saved posts: `get_post` returns the content as shortcode, they edit
it, and `update_post` takes the full updated `code` back. The per-block
index-path tools (`insert_blocks`/`update_block`/`remove_block`/`move_block`/
`duplicate_block`) were **removed entirely** (2026-08-09) — a second, bespoke
insertion surface next to the code path was complexity that bought nothing.
Every DB write goes through the same validation, sanitization and
revision-creating save path the editor's own UI uses; canvas writes land in
the editor's undo stack and stay unsaved until the user saves.

## Reversibility

Every destructive or mutating tool (`create_post`, `update_post`,
`set_post_status`, taxonomy attach/detach/create/update, `set_page_layout`,
`set_discussion`, `create_translation` when it changes block content)
writes through the normal save path, which snapshots a revision.
`restore_revision` reverses any of them. Nothing bypasses that path.
`write_canvas` is reversible through the editor's own undo stack instead — it
never touches the database.

## Surface split

`McpToolRegistry::call()`/`listFor()` take a **surface** argument.
`HeisenbergToolSource` (the in-editor assistant) passes `SURFACE_EDITOR`; the
inbound MCP server passes its own. The split (2026-08-09):

- **Editor surface only**: `write_canvas` (the live page the user is looking
  at — meaningless to an external agent with no canvas) and `set_post_status`
  (a human is driving; status is gated per-call by the same lifecycle tiers the
  Publish button uses).
- **External surface only**: `create_post` and `update_post`. Offering DB
  content writes to the in-editor assistant made models "insert" content into
  the database while the canvas in front of the user stayed empty — the
  editor's one write path is now the canvas itself. The inbound server — a
  bearer-token API for other AIs — keeps them and stays **draft-only**,
  keeping its original refusal ("posts are created as drafts") intact. Both
  restrictions are enforced at list AND call time.
- **Both surfaces, no draft-only restriction**: `create_translation` writes
  translated TEXT FIELDS on an existing post's own row — not the live canvas,
  and not a new post — so it makes sense on the external server too, and
  unlike `create_post`/`update_post` it holds no draft-only posture on either
  surface: it never creates a post and never changes `status`, so there is no
  "unreviewed content goes live" risk to guard against (docs/content-
  translation.md §0/§6).

## Error safety

`McpToolRegistry::call()` catches domain errors (`McpToolException`) as
descriptive, actionable tool results and now also catches any generic
`\Throwable`, returning a descriptive failure result instead of letting an
infrastructure fault break the model's result channel. The model always receives
a result for every call it makes.

## Deliberate gaps (not missing — explained)

| Capability | Why there is no tool |
|---|---|
| ~~**Featured image**~~ | **No longer a gap (2026-08-11):** `heisenberg_posts.featured_image_id` (nullable FK to public files, `nullOnDelete`), `Post::featuredImage`, `PUT /editor/posts/{post}/featured-image` (`PostSettingsController::updateFeaturedImage`, same posture as layout/discussion), rendered by the preview page, and the MCP `set_featured_image` tool (both surfaces). |
| ~~**SEO fields**~~ (meta title/description/OG, focus keyphrase, robots, canonical, schema, sitemap inclusion) | **No longer a gap (2026-08-11, docs/seo-system.md Wave A1):** `get_seo`/`update_seo`/`analyze_seo` (both surfaces) reach the full `SeoMeta` row and the `SeoAnalyzer` score/checklist. Reachable from the editor's SEO/Social panel too (Wave S2a/S2b). |
| **Media upload** | `list_media` is intentionally read-only for bytes; `update_media` (2026-08-11) covers metadata (alt/caption/credit) without opening an upload surface. |
| **Status changes over the inbound MCP server** | Deliberate: the external API stays draft-only (see Surface split). |
| **Theme editing** | `get_theme` is read-only by design — the assistant honors the theme, it does not rewrite it. |

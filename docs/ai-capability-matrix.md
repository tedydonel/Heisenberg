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
| Read a post (shortcode + block JSON + `content_version`) | `get_post` | The `content_version` guards concurrent edits. |
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
| Set page layout (padding) | `set_page_layout` | Mirrors `PostSettingsController::updateLayout`. |
| Set discussion (allow comments) | `set_discussion` | Mirrors `updateDiscussion`. |
| List revisions | `list_revisions` | Ids + timestamps + type. |
| Restore a revision | `restore_revision` | Goes through the normal restore path — undoable. |
| Read the active theme's design tokens | `get_theme` | Returns the `--hb-t-*` variables so the assistant honors the site theme. |
| Render without saving | `render_preview` | Shortcode/blocks → HTML. |
| List media | `list_media` | Read-only. |

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
`set_post_status`, taxonomy attach/detach/create, `set_page_layout`,
`set_discussion`) writes through the normal save path, which snapshots a
revision. `restore_revision` reverses any of them. Nothing bypasses that path.
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

## Error safety

`McpToolRegistry::call()` catches domain errors (`McpToolException`) as
descriptive, actionable tool results and now also catches any generic
`\Throwable`, returning a descriptive failure result instead of letting an
infrastructure fault break the model's result channel. The model always receives
a result for every call it makes.

## Deliberate gaps (not missing — explained)

| Capability | Why there is no tool |
|---|---|
| **Featured image** | The platform has no featured-image column, route, or adapter — the capability does not exist on either side yet. Building it would mean extending the post data model, which is out of scope. |
| **SEO fields** (meta title/description/OG) | Unbound scaffolding only (`NullPostSeoMetaProvider`, `seo_meta` table reserved) — "pending M3". Not reachable from the editor either. |
| **Media upload** | `list_media` is intentionally read-only; upload is a separate surface the assistant does not drive. |
| **Status changes over the inbound MCP server** | Deliberate: the external API stays draft-only (see Surface split). |
| **Theme editing** | `get_theme` is read-only by design — the assistant honors the theme, it does not rewrite it. |

# AI Subsystem Overhaul — Phase 0 Findings

Reconnaissance report. No code was changed. Every claim below carries a file:line reference
and the load-bearing ones were independently re-verified against the source.

---

## 1. The request path

One AI message, client → provider → client:

| # | Stage | Where |
|---|---|---|
| 1 | Panel mount, `stream-url` wired | `resources/views/editor/index.blade.php:24` → `panel-ai-tools.blade.php:564` |
| 2 | Send handler `run()` — builds `{prompt, context}` where context = full document via `window.hbCodeView.serialize()` + selection + title | `resources/views/components/live/panel-ai-tools.blade.php:290-298, 300-500` |
| 3 | `fetch POST /editor/ai/stream` (SSE over POST, not EventSource) | `panel-ai-tools.blade.php:376-386` |
| 4 | Route group: `heisenberg.middleware.ai` (default `web`) + `throttle:30,1` | `routes/ai.php:27-30, 58-68`; `config/heisenberg.php:320, 439` |
| 5 | `AiController::stream()` — auth check, request build, `response()->stream()` | `src/Http/Controllers/AiController.php:166-260` |
| 6 | `EditorPrompt::system()/user()` assemble the prompt | `src/Ai/EditorPrompt.php:30-60, 68-95` |
| 7 | `AiToolRunner::stream()` — tool discovery + agent loop, yields `AiStreamEvent`s | `src/Services/AiToolRunner.php:147-201` |
| 8 | One `$provider->stream()` call per round (black-boxed provider layer) | `AiToolRunner.php:169` |
| 9 | Controller `$emit` — one SSE `data:` frame per event, flushed | `AiController.php:202-208` |
| 10 | Client reader: `response.body.getReader()`, split on `\n\n`, JSON.parse per frame | `panel-ai-tools.blade.php:388-450` |

**Buffering points found:**

- **Server PHP buffering: already torn down.** `AiController.php:192-200` kills zlib
  compression, output_buffering, all open `ob_` levels, and sets implicit flush; per-frame
  `ob_flush()+flush()` in `$emit`; anti-proxy headers `X-Accel-Buffering: no`,
  `Content-Encoding: none` at `:254-257`. This layer is clean.
- **`AiToolRunner` forwards `TEXT_DELTA` 1:1** — no accumulation window (`AiToolRunner.php:172-174`).
- **Tool execution is a blocking silent gap.** Each tool call runs synchronously inside the
  generator (`AiToolRunner.php:191-197`); the client sees one `tool_use` narration frame,
  then nothing until the next round's deltas. This is the biggest visible "dead air."
- **Client: no rAF batching** — bubble repaints per SSE frame (`:399-404`). The only
  throttle is `liveApply()` at 250ms for canvas application (`:339-356`, because
  `replaceDoc` re-renders the whole canvas).
- **Residual chunkiness therefore originates in provider-adapter delta granularity or the
  upstream model server** — the adapter is the off-limits layer. Must be measured, not
  assumed (see Open Questions).

**Wire framing:** `data: {"type":"text_delta","text":…}\n\n`, `{"type":"tool_use","data":{"name":…}}`
(name only — no args/id sent to client), `{"type":"done"[,"data":{stopReason}]}`, `{"type":"error","text":…}`.
No `event:` field, no round separators. `done` is suppressed during tool rounds so the client
sees exactly one terminator (`AiToolRunner.php:178-180`; fallback `done` at `AiController.php:246-249`).

**Mid-stream error behavior (partial-work asymmetry):**

- Truncation (`!sawDone`) and user Stop **preserve** the accumulated bubble text
  (`panel-ai-tools.blade.php:451-476`).
- An explicit `error` event or a non-abort fetch failure **replaces the bubble text
  entirely** with the error string (`:416-420, :477-480`) — this is why the MiniMax blip and
  the round-cap failure both presented as total loss of the reply.
- Canvas blocks already applied via `liveApply` are never rolled back — and never surfaced
  as "kept work" either.

## 2. The agent loop

- Loop lives solely in `src/Services/AiToolRunner.php` — `run()` (non-stream, :98-130) and
  `stream()` (:147-201). Cap: `max(1, config('heisenberg.ai.mcp.client.max_iterations', 8))`
  at `:111/:162`; configured at `config/heisenberg.php:337` (`HEISENBERG_MCP_MAX_ITERATIONS`,
  default **8**). Not exposed in any UI or settings payload — env-only.
- On exhaustion: `AiResponse::error(...)`/`AiStreamEvent::error(...)` with
  `resources/lang/en/editor.php:106` — *"The assistant kept calling tools and stopped after
  :max rounds."* — exactly the observed string. Streaming path renders it as the error bubble
  (all partial text discarded, see §1). Non-streaming path returns HTTP 502.
- One **iteration = one provider pass**, so N tool calls in one response cost 1 round, but a
  model calling tools one-at-a-time burns 1 round each.

**Root cause of the observed failures (diagnosed from code; runtime log confirmation still
required in Work item 1):** a discovery spree. The tool description for `list_blocks` says
*"Call this before authoring content"* (`McpToolRegistry.php:132`); there are 12 block
contracts; `describe_block` covers one block per call; the system prompt tells the model to
use tools instead of asking but never says when it has enough or that it may batch. A
from-scratch creative prompt ("surprise me with a post") invites the model to explore many
blocks → `list_blocks` + sequential `describe_block` calls ≥ 8 rounds before any content is
emitted. Identical prompts fail differently because the model's tool-call sequence is
sampled, not deterministic — some runs explore less and finish; the observed pair both
explored past the cap.

**Contributing defects:**

- **Empty tool results pass as silent success.** `HttpMcpClient::callTool()` concatenates
  only `text` content blocks (`src/Adapters/HttpMcpClient.php:72-84`); a text-less reply
  yields `content: ''`, `isError: false` — the model sees `""` with no explanation.
  (Local Heisenberg tools always JSON-encode, so can't be empty — `McpToolRegistry.php:564-577`.)
- **Uncaught `\Throwable` from a tool handler skips the error channel entirely.**
  `McpToolRegistry::call()` catches only `McpToolException` (`:107-122`); nothing in
  `HeisenbergToolSource::call()` or `AiToolRunner::execute()` catches generic exceptions.
  Streaming path: surfaces as generic `stream_failed`. Non-streaming path:
  `AiController::complete()` (`:149`) has no try/catch → raw 500.
- **No de-duplication of identical tool calls across rounds**, no round budget hinting.
- **No per-request telemetry whatsoever**: zero log calls in `AiToolRunner`; intermediate
  rounds' token usage is discarded (only the final `AiResponse::$usage` survives); the
  configured `audit_sink` is never invoked from the AI path.
- **On the OpenAI-compatible wire format, tool errors carry no `is_error` marker**
  (`OpenAiCompatibleProvider.php:342-350`) — the model must infer failure from prose.

## 3. The tool registry

12 tools, all from `McpToolRegistry` (`src/Services/McpToolRegistry.php:127-326`), offered
to the in-editor assistant via `HeisenbergToolSource` (prefixed `heisenberg__`) and to
external agents via the inbound MCP server (`routes/mcp.php`) — same registry:

`list_blocks`, `describe_block`, `list_posts`, `get_post`, `create_post`, `update_post`,
`render_preview`, `list_categories`, `list_tags`, `attach_category`, `attach_tag`, `list_media`.

**Registration timing: already correct.** `AiToolRunner::discover()` (`:51-91`) resolves the
full tool set before round 1 and sends it with every provider pass, including the first.
Local tools are unconditional (`:57`); MCP-server tools are appended if enabled+allow-listed,
with failing servers skipped, not fatal. There is no lazy registration. The work order's
"assistant doesn't know its tools on message 1" symptom is a **prompt-content** problem
(§4), not a registration-timing problem.

**Results:** uniformly structured `{content:[{type:'text',text}], isError}`; success is
pretty-printed JSON; domain failures are descriptive and actionable (stale content_version,
unknown block, shortcode parse errors with line numbers). Gaps are the empty-external-result
and uncaught-exception cases in §2.

**Misleading schema text:** `create_post.status` says non-draft "requires an admins-tier
token" but the code unconditionally rejects any non-draft status
(`McpToolRegistry.php:351-355`) — there is no admin path.

**Naming collision:** the 8 "tool cards" in `AiSettingsRepository::TOOLS` (generate_title,
improve_writing, …) are canned prompts, not tools; their on/off toggles control nothing
about the MCP tool set (`enabledTools()` has zero callers; cards just send their label as a
prompt — `panel-ai-tools.blade.php:528-531`).

**Doc mismatch:** `docs/ai-mcp-plan.md:477` claims a confirm gate on write tools; none
exists in `src/`.

### Capability matrix (editor UI vs tool surface)

| Editor capability | Editor location | MCP tool | Gap |
|---|---|---|---|
| Create/read/update post content | `PostController` | `create_post`/`get_post`/`update_post` | covered (whole-document granularity only) |
| Per-block create/update/reorder/duplicate/delete | canvas UI | — | **gap** (only whole-document `update_post`) |
| Title | editor | `create_post`/`update_post` | covered |
| Slug (explicit) | `PostController.php:179` | — | **gap** (auto-derived only) |
| Excerpt (en/fr) | `PostController.php:179` | — | **gap** |
| Locale on create | `PostController.php:179` | — | **gap** |
| Publish / schedule / archive | `PostController::applyTransition`, `routes/editor.php:35` | — | **deliberately refused** (`McpToolRegistry.php:354`) — see Open Questions |
| Featured image | **does not exist in the platform** (no column, no route) | — | platform gap, not tool gap — see Open Questions |
| SEO fields | scaffolding only, unbound (`config/heisenberg.php:41,82`, pending M3) | — | platform gap — see Open Questions |
| Categories/tags attach | `routes/editor.php` | `attach_category`/`attach_tag` | covered |
| Categories/tags **detach** | `routes/editor.php:69,71` | — | **gap** |
| Taxonomy term CRUD | `routes/editor.php:45-52` | — | **gap** |
| Page layout (padding) | `routes/editor.php:74` | — | **gap** |
| Discussion (allow_comments) | `routes/editor.php:75` | — | **gap** |
| Revisions list/restore | `routes/editor.php:38-39` | — | **gap** |
| Theme read | `routes/editor.php:57-65` | — | **gap** (relevant to design tokens) |
| Media list | `routes/media.php` | `list_media` | covered (read-only by design) |
| Media upload | media routes | — | **gap** (tool explicitly declares itself read-only) |
| Render preview | n/a (AI-only affordance) | `render_preview` | covered |

## 4. The system prompt

Single unconditional template, `src/Ai/EditorPrompt.php:30-60`, rebuilt per request
(verbatim copy in that file; re-verified). Contents assessment:

| Required by work order | Status |
|---|---|
| What the platform is | One clause: "a block-based page editor." Nothing else. |
| What the assistant can do | Shortcode-vs-prose dichotomy + one vague sentence that tools exist. No capability list. |
| Component/block types | Bare slug list interpolated live from the block registry — no attributes, no usage guidance. |
| Design token variables + rule to use them | **Entirely absent.** The theme token system (`--hb-t-*`) is never mentioned; only raw CSS values are taught. |
| Tool manifest with argument shapes | Not in the prompt — delivered correctly via the provider tool channel (which is the right place); but the prompt gives the model no map of them. |

**There is no session.** Every request sends exactly one user message (prompt + full
re-serialized document + selection + title — `EditorPrompt::user()`, `panel-ai-tools.blade.php:290-298`).
No prior turns are ever transmitted; message 1 and message 5 are already mechanically
identical. The "stranger on first message" feeling comes from prompt thinness, not staleness.

**No chat persistence exists anywhere**: no tables (all 12 migrations checked), no models,
no storage; the thread is DOM state destroyed by reload or "New". Work item 7 is a
from-scratch build (schema + storage + endpoints + context replay + UI).

**Dialect coverage:** the prompt is a compressed cheat-sheet of `docs/code-view.md` — it
omits the full style-alias table, dotted-path escapes, token values, quoting rules,
container/child (innerBlocks) semantics, and the error catalog. The model is expected to
fetch per-block detail via tools — which feeds the round-cap failure.

## 5. Dead code inventory

Unused methods (zero callers repo-wide, tests included):

| Item | Location |
|---|---|
| `AiRequest::withModel()` | `src/Ai/AiRequest.php:53-56` |
| `AiMessage::assistant()` | `src/Ai/AiMessage.php:52-55` |
| `AiSettingsRepository::enabledTools()` | `src/Services/AiSettingsRepository.php:183-186` |
| `AiProviderProfile::toArray()` | `src/Ai/AiProviderProfile.php:61-72` |
| `McpServer::toArray()` | `src/Ai/McpServer.php:46-56` |
| `AiProvider::id()` (interface method, all 3 impls) | `src/Contracts/AiProvider.php:37` — needs one broader confirming grep before deletion |

Other findings:

- `POST /editor/ai/complete` (`routes/ai.php:60`, `AiController::complete()` :131-164) is
  **orphaned from the UI** — no shipped client calls it; only tests do. It is however a
  documented public API surface (`docs/ai-mcp-plan.md:247-255`). Decision needed (Open
  Questions).
- `generate_image` half-removed: card removed from the panel (test asserts absence,
  `tests/Editor/AiPanelWiringTest.php:303-308`) but still in `AiSettingsRepository::TOOLS`
  (`:41`) and both lang files — settings API still accepts/persists it while nothing acts
  on it.
- Redundant same-namespace import: `AiToolRunner.php:14`.
- Stale doc: `docs/ai-mcp-plan.md:320` says `GET /editor/ai/stream`; route is POST. And
  `:477` claims a write-confirm gate that doesn't exist.
- No commented-out code, no TODO/FIXME/HACK markers, no unreachable branches found in scope.

## Observed-failure explanations

1. *"Couldn't reach MiniMax"* — provider-layer blip, out of scope. In-scope lesson: the
   error path **discarded the bubble text and left no recovery affordance** (§1 asymmetry).
2. *"…stopped after 8 rounds"* (twice) — discovery spree vs. 8-round cap (§2), with all
   partial work discarded on error.
3. Different outcomes for identical prompts — sampled tool-call trajectories; no
   determinism, no dedup, no budget guidance.

## Open questions (need decisions before/during implementation)

1. **Publish/schedule via tools** (work item 4 requires it) conflicts with a deliberate
   refusal in `McpToolRegistry.php:354` ("posts are created as drafts") — a security posture
   for the *inbound* MCP server, which shares the registry with the in-editor assistant.
   Options: split tiers (in-editor assistant may set status; external MCP stays draft-only),
   or keep refusal and document the gap.
2. **Featured image and SEO fields** (work item 4 requires them) do not exist in the
   platform at all (no column/routes; SEO is unbound scaffolding "pending M3"). Building
   them means extending the post model — which the constraints forbid without asking.
   Options: build the platform features, or record as explained gaps in the matrix.
3. **`/editor/ai/complete`**: orphaned from the UI but documented+tested as a public API.
   Delete under work item 8, or keep as an integration surface?
4. **Token granularity ceiling**: if measurement shows chunkiness originates inside the
   provider adapter (off-limits layer), fixing per-token flushing there requires an
   explicit exemption. Everything outside it (tool-gap dead air, canvas throttle tuning,
   progress narration) is in scope regardless.

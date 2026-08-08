# AI Agent + MCP — Build Plan

> Turns the AI/Tools panel from a static composition into a working surface, and makes
> Heisenberg both an **MCP client** (it can use other people's tools) and an **MCP server**
> (other AIs can build pages here).
>
> Written 2026-08-07. Phase numbers in this document are local to it — `TODO.md` has its own.

---

## Starting point (2026-08-07) — kept for provenance

This is what the surface looked like before any of the work below; the current state is in
*Status — all six phases complete* further down.

`live/panel-ai-tools.blade.php` was **entirely presentational**, extracted 1:1 from Pencil and
never wired:

- Ai tab: 4 `ui/suggestion-row`s with hardcoded labels, a response card printing the literal
  string `panel_ai_tools.ai_response_demo`, and a prompt bar whose send button has **no listener**.
- Tools tab: 8 `ui/tool-card`s that the search field filters and nothing clicks.
- The only JS in the file is the Ai/Tools tab switcher.

There is **no AI backend of any kind**: no contract, adapter, service, controller, route,
config key, or composer dependency. No MCP anything. This is greenfield.

What we *do* have that this plan leans on hard:

| Existing | Used for |
|---|---|
| `BlockRegistryService` | the block contracts are the authoring schema an external AI needs |
| `BlocksPayloadService::validatePayload()` | every AI write goes through the same validator the editor uses |
| `HtmlSanitizationService` | rich-text sanitisation on the MCP write path |
| `BlockRenderer` (`MAX_NESTING_DEPTH = 20`) | `render_preview` MCP tool |
| `live/code-editor.blade.php` shortcode dialect | the machine-authoring format (`docs/code-view.md`) |
| `live/media/media-dialog.blade.php` scrim shell | `hbOpen`/`hbClose`, Escape, backdrop-close, focus trap |
| `live/revisions-dialog.blade.php` | precedent for a second dialog reusing that shell |
| `ThemeRepository` / `SavedThemeRepository` | JSON-file settings-repository pattern |
| `SavedThemeController` | RoleGate + `LocalDevRoleGate` authorisation posture for writes |
| `window.hbEditor` | `replaceDoc` / `insertBlock` / `getDoc` / `getSelectedId` / undo-redo |

---

## Decisions (locked 2026-08-07)

1. **Modal UI = composed from extracted atoms.** The AI-settings dialog is built inside the
   `.hb-mediadialog__scrim` shell out of existing `ui/*` primitives — the same route
   `live/revisions-dialog.blade.php` took when it had no design frame. **No new invented widgets.**
   (`heisenberg.pen` was not open in Pencil when this was planned, so no AI-settings frame could be
   confirmed either way. If one turns up later, it wins and this composition is replaced.)
2. **API keys: env only.** No secret ever lands in a JSON file or crosses the HTTP API. The modal
   renders "configured" / "not set" and can neither read nor write key material. MCP server auth
   follows the same rule: settings store the *name* of an env var, never the token.
3. **Two provider families:** `AnthropicProvider` + `OpenAiCompatibleProvider` (one adapter covering
   OpenAI, Groq, Ollama, OpenRouter via a configurable base URL).
4. **MCP goes both directions** — outbound client *and* inbound server.
5. **We own the tool loop.** Anthropic's server-side `mcp_servers` connector is Anthropic-only;
   since OpenAI-compatible providers must reach the same MCP servers, `McpClientService` connects
   directly and `AiToolRunner` runs the request → `tool_use` → execute → `tool_result` loop
   server-side. The provider-side connector stays an optional later optimisation, not the design.

---

## Ground rules

1. **Never replace extracted UI.** `panel-ai-tools.blade.php`'s markup is 1:1 from Pencil.
   Wire it — add hooks, bind the response card, delete the fake demo copy from the lang files.
   Do not restructure the composition.
2. **Vanilla JS on `/editor`.** No Alpine, no Livewire, no bundler, consistent with every other
   `live/*` component.
3. **No new hard composer dependency.** Both providers run on `Illuminate\Http\Client`, which the
   package already requires. No `anthropic-ai/sdk`, no MCP SDK.
4. **Every AI write goes through the existing save pipeline** — never around it. An unknown block
   name is dropped exactly the way `insertBlock` drops it.
5. **Disabled by default.** `NullAiProvider` is the bundled default and the inbound MCP server ships
   `enabled => false`. A fresh install must never fatal, never phone home, never expose a write API.
6. **Every phase ends green.** `vendor/bin/phpunit` passes; new behaviour needs new tests.

---

## Status — all six phases complete (2026-08-08)

Both MCP directions work, both provider families are live, and a provider can be added from the
modal without touching `.env`. What is deliberately **not** here, and why:

- **No image generation.** Both adapters are text-only; the Generate Image card was dropped rather
  than left inert.
- **Media is read-only over MCP.** An agent may reference existing files, not write bytes to the
  host's disk.
- **Publishing is not an MCP operation.** Posts arrive as drafts; status changes are a lifecycle
  transition and stay in the editor.
### Providers vs formats — corrected 2026-08-08

**The original decision #3 was a modelling error.** "Anthropic" and "OpenAI-compatible" were
treated as *providers* when they are only **wire formats**. xAI, Google Gemini, OpenRouter, Groq,
DeepSeek, Mistral, MiniMax and a local Ollama all speak the OpenAI `/chat/completions` shape — so
conflating vendor with format capped the whole package at exactly two providers and left nowhere to
put a key.

The structure now is:

- **`AiProviderProfile`** — a vendor: id, label, `format` (`anthropic` | `openai`), base URL, and
  either an env var name or a stored key. Config ships ten presets an operator can add in one
  click, plus a custom form for anything not listed.
- **`AiModel`** — belongs to a provider, carries `enabled` and **its own effort**. Effort is
  per-model because a small local model has no use for `xhigh` and a reasoning model is wasted at
  `low`.
- **`AiProviderRegistry`** picks the adapter from the provider's *format*, so adding a vendor is a
  settings row, never a new class.

**Models are discovered, not shipped.** Both formats expose a `/models` endpoint; the Discover
button asks the vendor. A hardcoded catalogue is wrong the week a model launches, so the presets
carry no model lists at all.

**Decision #2 (env-only keys) was reversed at the user's request.** Keys can now be entered in the
modal and are stored **encrypted at rest** (`EncryptedFileCredentialStore`, app-key encryption, its
own file — never the settings JSON). They remain **write-only**: no endpoint returns key material,
the API reports `has_key` booleans, and an **environment variable still wins** over a stored key so
the original, safer posture keeps working untouched. A host with a real secrets manager binds its
own `AiCredentialStore`.

One consequence worth remembering: `AiProviderRegistry::make()` is deliberately **not memoised**. A
cached adapter keeps answering with the credential it was built with, so a key saved earlier in the
same request would appear to have no effect.

### Models tab, revised 2026-08-08

The first cut of the Models tab was wrong and was rebuilt after review. It offered a
comma-separated text field plus a `ui/select` rendered server-side from the catalogue, which meant
there was no visible way to *add* a model, and a model that was added only appeared in the picker
after a reload.

It is now a **list of model rows plus an "+ Add model" button**, following the same
row-from-a-`<template>` pattern the Providers and MCP Servers tabs use. The list is both the picker
and the editor: each row can be put in use or removed, and an added model is selectable
immediately. That deletes the reload caveat entirely rather than documenting it.

Two smaller fixes from the same review: every tab body now mounts `ui/custom-scrollbar` (only
Providers did), and the obsolete `saved_reload` string is gone.

### Streaming, insertion and panel width — revised 2026-08-08

Four faults reported against the finished chat, and what each turned out to be:

**The panel had been given its own wider shell track.** Reverted. Every middle panel is one width;
the room a conversation needs was found *inside* the panel instead (full-bleed messages, no card
nested in a padded section). `AiPanelWiringTest` now asserts the wide class is **absent**, so the
next attempt to buy space by widening the shell fails there rather than in review.

**Replies arrived in one lump.** The frames were leaving the model fine and being held by PHP:
Laravel's stack starts with at least one output buffer open, and `zlib.output_compression` opens
another that `ob_flush()` cannot reach. `stream()` now tears every buffer down before the first
frame and sets `ob_implicit_flush`, plus `X-Accel-Buffering: no` and `Content-Encoding: none` for
proxies. It is skipped under the plain CLI SAPI — there is no browser waiting there, and the only
buffers open belong to the test harness.

**Replies looked cut off.** Three separate causes, all fixed:

1. **Streaming had no tools at all.** `stream()` called the provider directly while `complete()`
   went through `AiToolRunner`, so the assistant silently lost every platform tool the moment it
   streamed — and a turn that opened with a tool call produced no text whatsoever. `AiToolRunner`
   now has a streamed twin of its loop: text is forwarded delta by delta, `tool_use` is swallowed
   and acted on, and only the pass where the model stops asking may emit `done`.
2. **The OpenAI adapter dropped streamed tool calls.** They arrive in fragments keyed by `index`,
   not `id` — the first names the tool, the rest append slices of the argument JSON. Nothing
   reassembled them, so any tool-using turn on an OpenAI-format provider streamed as an empty
   answer.
3. **A mid-stream read that threw killed the response silently.** Both adapters now catch it and
   yield an error frame, the controller catches anything escaping the generator, and every stream
   is guaranteed to end with a terminator. The panel uses that: a stream that ends without one is
   reported as a dropped connection, and `stopReason: max_tokens` is named as a length limit
   instead of being left as a mystery.

**Insert refused perfectly good replies.** It handed the *entire* message to `hbCodeView.parse`,
so any answer with prose around the markup ("Here's a hero section:" … "want an intro under it?")
parsed as an error and inserted nothing. It now extracts the markup first — fenced code when the
model marked it, otherwise the span from the first opening tag to the last closing one — and still
refuses pure prose rather than guessing. On success it opens the **Code view** on the result, which
is what "write to the code editor" was asking for; `window.hbCodeView` gained `open()` and `sync()`
for that.

## Open questions — both resolved

- **Q1 — should MCP write tools accept shortcode *and* raw block JSON?** **Yes, both.**
  `create_post`/`update_post` take `code` or `blocks` (never both in one call) and `get_post`
  returns both. Shortcode is the ergonomic surface, block JSON the canonical one; they converge on
  the same validated models.
- **Q2 — should the inbound server expose media upload?** **No — media stays read-only.**
  `list_media` lets an agent reference existing files; writing bytes to the host's disk is a bigger
  grant than authoring text and is not part of this surface.

---

## Phase 1 — Foundation (nothing calls a model yet) — **DONE 2026-08-07**

Lands the whole seam — config, contracts, null adapter, settings storage, routes, and a modal that
opens — with zero network calls. Mergeable on its own.

Two things worth carrying forward into Phase 2:

- **The two real adapters are named in config but don't exist yet**, so `AiProviderRegistry` reports
  them `available: false` and the modal renders them as "Not installed" alongside the env var they
  will need. Phase 2.1/2.2 flip that to live with **no config change** — just the classes.
- **`AiSettingsRepository::validate()` runs on load as well as save.** That is what makes the
  credential guard hold against a hand-edited `ai.json`, and it is why a tainted file falls back to
  defaults rather than serving a secret back through the settings API.

- [x] **1.1 `heisenberg.ai` config block**
  New section in `config/heisenberg.php` (sketch at the bottom of this file): provider map, default
  provider/model/effort, max tokens, timeouts, `routes` flag, MCP client + server sub-blocks.
  Follows the `media` block's shape — every extension point externalised, adapters named as class
  strings.
  *Files:* `config/heisenberg.php`
  *AC:* `config('heisenberg.ai')` resolves on a fresh install and names only classes that exist.

- [x] **1.2 Contracts**
  `AiProvider` — `models(): array`, `complete(AiRequest): AiResponse`, `stream(AiRequest): iterable`,
  `supportsTools(): bool`. `McpClient` — `listTools(McpServer): array`, `callTool(McpServer, string, array): array`.
  Plus small value objects (`AiRequest`, `AiResponse`, `AiStreamEvent`, `McpServer`) so the two
  provider adapters can't drift in shape.
  *Files:* `src/Contracts/AiProvider.php`, `src/Contracts/McpClient.php`, `src/Ai/*.php`
  *AC:* both contracts are bound in the container; a host can swap either via config.

- [x] **1.3 `NullAiProvider`**
  Bundled default, same posture as `NullVirusScanner`: reports no models, `supportsTools() === false`,
  and returns a "no AI provider is configured" response rather than throwing.
  *Files:* `src/Adapters/NullAiProvider.php`
  *AC:* with no env keys set, the AI panel renders and the prompt bar returns a clean message —
  no exception, no 500.

- [x] **1.4 `AiSettingsRepository`**
  JSON-file-backed exactly like `SavedThemeRepository` (`storage/app/heisenberg/ai.json`, path
  overridable via config). Stores active provider, model, effort, enabled tool cards, and the MCP
  server list. **Never stores credentials** — an MCP server entry carries `auth_env`, the name of
  the env var holding its token.
  *Files:* `src/Services/AiSettingsRepository.php`
  *AC:* settings round-trip; a payload containing anything key-shaped is rejected by `validate()`.

- [x] **1.5 `AiProviderRegistry`**
  Resolves the configured provider classes, validates each against `AiProvider`, and reports per
  provider: id, label, whether its env key is present, and its model catalogue. Drives the modal's
  Providers tab. Mirrors `BlockRegistryService`'s role for contracts.
  *Files:* `src/Services/AiProviderRegistry.php`
  *AC:* the registry reports `configured: false` for a provider whose env key is absent, and never
  returns the key itself.

- [x] **1.6 `AiController` + `routes/ai.php`**
  `GET /editor/ai/settings`, `PUT /editor/ai/settings`. Reads are open; writes carry the `admins`
  RoleGate check plus the `LocalDevRoleGate` local-only bypass, copied from `SavedThemeController`.
  Loaded by a new `registerAiRoutes()` when `config('heisenberg.ai.routes')` is true, gated by
  `config('heisenberg.middleware.ai')` (default `['web']`) — so AI can be enabled/protected
  independently of editor and media, same as those two are of each other.
  *Files:* `routes/ai.php`, `src/Http/Controllers/AiController.php`, `src/HeisenbergServiceProvider.php`
  *D:* 1.1, 1.4, 1.5
  *AC:* settings persist over HTTP; a guest on a non-local env gets 403 on the write.

- [x] **1.7 Service-provider registration**
  `registerAi()` (singletons: provider, registry, settings repo, MCP client) + `registerAiRoutes()`,
  sitting alongside `registerMedia()` / `registerMediaRoutes()` at the same level.
  *Files:* `src/HeisenbergServiceProvider.php`
  *AC:* `php artisan config:clear` on a host with no AI config still boots.

- [x] **1.8 AI settings modal — shell + Providers/Models tabs**
  New `live/ai/ai-settings-dialog.blade.php` on `.hb-mediadialog__scrim` (inheriting `hbOpen`/`hbClose`,
  Escape, backdrop-close and the focus trap for free) with `ui/tabs` across the top:
  **Providers · Models · MCP Servers · Expose**. Phase 1 fills the first two; the last two render
  empty-state until Phases 4–5. Opened by a settings `ui/icon-button` added to the AI panel header —
  *added beside* the extracted header row, not replacing it.
  *Files:* `resources/views/components/live/ai/ai-settings-dialog.blade.php`,
  `live/panel-ai-tools.blade.php` (trigger only), `resources/views/editor/index.blade.php` (mount),
  `resources/lang/{en,fr}/editor.php`
  *D:* 1.6
  *AC:* the dialog opens from the panel, traps focus, closes on Escape/backdrop; provider rows show
  configured/not-configured; selecting a model persists through 1.6.

- [x] **1.9 Tests**
  `tests/Ai/AiSettingsRepositoryTest`, `tests/Ai/AiControllerTest` (auth posture + round-trip),
  and `tests/Editor/AiPanelWiringTest` in the style of `InspectorWiringTest` — asserting the panel
  **mounts** the dialog and carries its data attributes rather than re-implementing the UI.
  *Files:* `tests/Ai/*`, `tests/Editor/AiPanelWiringTest.php`

---

## Phase 2 — Providers live — **DONE 2026-08-08**

Phase 1 shipped a modal in which every provider read "Not installed", so the assistant could not be
turned on at all. That was the gap this phase closed, and it added one task the original plan
missed:

- [x] **2.1b Providers are addable from the modal**
  `base_url` and the model list are now editable per provider and stored in settings
  (`provider_config`), merged over config by both `AiProviderRegistry` and
  `AiSettingsRepository::mergedProviders()`. That is what lets someone point the OpenAI-compatible
  adapter at Ollama or OpenRouter and list what it serves **without editing `.env` and
  redeploying**. `key_env` and `adapter` are explicitly refused there — the first would turn the
  `configured` flag into an oracle for probing the process environment, the second would be
  arbitrary class instantiation from a JSON file.
  *AC:* a provider can be repointed and given a catalogue in one save, and the model chosen in that
  same save validates against the new catalogue.


- [x] **2.1 `AnthropicProvider`**
  On `Illuminate\Http\Client`. Default model `claude-opus-5`; catalogue also offers `claude-sonnet-5`
  and `claude-haiku-4-5`. Requests set `thinking: {type: "adaptive"}` and `output_config.effort`.
  **`temperature`, `top_p`, `top_k` and `budget_tokens` are rejected by these models — the request
  builder must never emit them.** Handles `stop_reason: "refusal"` before reading `content`.
  *Files:* `src/Adapters/AnthropicProvider.php`
  *AC:* a recorded-fixture test asserts the outgoing body contains no sampling params and no
  `budget_tokens`; a `refusal` response surfaces as a clean message, not an array-index error.

- [x] **2.2 `OpenAiCompatibleProvider`**
  Configurable `base_url` so one adapter covers OpenAI, Groq, Ollama and OpenRouter. Model catalogue
  is config-supplied (these endpoints don't share one).
  *Files:* `src/Adapters/OpenAiCompatibleProvider.php`
  *AC:* pointing `base_url` at a fake local endpoint round-trips a completion.

- [x] **2.3 Normalised streaming**
  Both wire formats differ; both normalise into one internal event stream —
  `text_delta` / `tool_use` / `done` / `error`. The panel only ever sees that.
  `GET /editor/ai/stream` emits SSE; the client aborts on dialog/panel close.
  *Files:* `src/Ai/AiStreamEvent.php`, both adapters, `src/Http/Controllers/AiController.php`, `routes/ai.php`
  *D:* 2.1, 2.2
  *AC:* switching provider changes nothing in the panel's JS.

- [x] **2.4 Prompt bar wired**
  The extracted send button posts the prompt plus editor context (`hbEditor.getDoc()`,
  `getSelectedId()`, `getModel()`), and streams tokens into the **existing** response card.
  Delete `panel_ai_tools.ai_response_demo` from `en` and `fr` — the card is bound now, not faked.
  *Files:* `live/panel-ai-tools.blade.php` (hooks only), `resources/lang/{en,fr}/editor.php`
  *D:* 2.3
  *AC:* typing a prompt streams a real response; the card is empty (not demo copy) on load.

- [x] **2.5 Insert via the shortcode dialect**
  The model is asked to emit `[h3 …]…[/h3]`, not prose. Insert runs it through the code editor's
  existing parser and lands it via `hbEditor.replaceDoc()` / `insertBlock()`. That gets registry
  validation, line-numbered errors and Ctrl+Z undo for free — no new insertion path.
  Requires exposing the code editor's parser (currently a closure at
  `code-editor.blade.php:388`) as `window.hbCodeView = { parse, serialize }`.
  *Files:* `live/code-editor.blade.php` (expose only), `live/panel-ai-tools.blade.php`
  *D:* 2.4
  *AC:* an AI-authored heading + paragraph appears as real blocks and undoes in one Ctrl+Z;
  an unknown block name is dropped rather than inserted.

- [x] **2.6 Tests** — `tests/Ai/AnthropicProviderTest`, `OpenAiCompatibleProviderTest` (both against
  `Http::fake()`), `AiStreamTest`; extend `tests/js/code-editor-matrix.mjs` for the exposed parser.

---

## Phase 3 — PHP shortcode parser (blocks Phase 4) — **DONE 2026-08-08**

Parity is **verified, not asserted**: `tests/Ai/ShortcodeParityTest.php` round-trips the fixture
corpus through the PHP pair and `tests/js/shortcode-parity.mjs` runs the same files through the
JavaScript pair in jsdom. Both agree byte for byte on every fixture.

The JS/PHP seams that actually bit, all handled in `ShortcodeDialect`: `String(true)` is `"true"` in
JS and `"1"` in PHP; `JSON.stringify` does not escape forward slashes and `json_encode` does;
`Number("")` is `0`, not `NaN`; and PHP cannot tell `{}` from `[]` after a JSON decode, so an empty
array is treated as an empty object when flattening supports.


The dialect exists **only in JavaScript** today. An MCP server that accepts shortcode needs it in
PHP. This is the largest single piece of the round, and it pays for itself beyond MCP: it lets the
server validate code-view output at save time.

- [x] **3.1 `ShortcodeParser`** — text → block array, validating against `BlockRegistryService`,
  reporting line-numbered errors. Same grammar as `docs/code-view.md`: tag aliases (`p`, `h1`–`h6`),
  contract attributes, CSS-familiar style aliases, box shorthands, `hover:`/`active:`/`focus:`
  state prefixes, nested bodies for `innerBlocks`-enabled contracts.
  *Files:* `src/Services/ShortcodeParser.php`

- [x] **3.2 `ShortcodeSerializer`** — block array → text, matching the JS serializer exactly:
  80-column inline/broken form, canonical supports group order
  (`align → position → layout → appearance → typography → size → color → spacing → border →
  effects → animation → states`), non-default values only, `layers` keys never emitted.
  *Files:* `src/Services/ShortcodeSerializer.php`

- [x] **3.3 Shared parity fixtures**
  One fixture corpus exercised by **both** the PHP tests and `tests/js/code-editor-matrix.mjs`, so
  the two implementations cannot drift. Round-trip must be byte-stable in both.
  *Files:* `tests/Fixtures/shortcode/*.txt`, `tests/Ai/ShortcodeParityTest.php`, `tests/js/code-editor-matrix.mjs`
  *D:* 3.1, 3.2
  *AC:* every fixture parses to the same block tree and re-serialises to the same bytes in PHP and JS.

---

## Phase 4 — Heisenberg as an MCP server — **DONE 2026-08-08**

Answers to the two open questions, now settled by the implementation:

- **Q1 — both shapes.** `create_post`/`update_post` accept `code` (shortcode) or `blocks` (JSON),
  never both in one call. `get_post` returns both.
- **Q2 — media stays read-only.** `list_media` lets an agent reference existing files; writing bytes
  to the host's disk is a bigger grant than authoring text and is not on this surface.

Two behaviours worth remembering because they deliberately differ from the editor:

- **An unregistered block name is an error, not a silent drop.** The editor drops (a contract can
  vanish between page load and save, and losing one block beats losing the document). An agent told
  "saved" for content that was discarded has no way to notice — so it gets an error naming
  `list_blocks` instead.
- **Writes need the runtime's stamps.** The parser emits bare models exactly as the JS parser does;
  in the browser `replaceDoc()` adds the `id`, the contract `schemaVersion` and the attribute
  defaults. There is no `replaceDoc` server-side, so `McpToolRegistry::hydrateBlocks()` is that step
  — without it every write fails validation on `missing key 'id'`.


Lets Claude Code, Claude Desktop or any MCP-speaking agent build pages here.

- [x] **4.1 Transport**
  JSON-RPC 2.0 over POST at `/heisenberg/mcp`: `initialize`, `tools/list`, `tools/call`. A tools-only
  server needs neither the bidirectional SSE channel nor sampling, which keeps this small.
  Its **own** route file and `config('heisenberg.middleware.mcp')` stack — deliberately *not* the
  `web` group, since it is bearer-token authed and must sit outside session CSRF.
  *Files:* `routes/mcp.php`, `src/Http/Controllers/McpServerController.php`, `src/HeisenbergServiceProvider.php`
  *AC:* `initialize` + `tools/list` answer correctly; the endpoint 404s when
  `heisenberg.ai.mcp.server.enabled` is false (the default).

- [x] **4.2 Token auth + scopes**
  Tokens from env; each maps to a RoleGate tier so a read-only token can be issued. Constant-time
  comparison, no token echoed in any response or log.
  *Files:* `src/Http/Middleware/McpTokenMiddleware.php`
  *D:* 4.1
  *AC:* no token → 401; read-only token → `tools/list` succeeds and every write tool is absent from
  the list *and* rejected if called directly.

- [x] **4.3 Read tools**
  `list_blocks` / `describe_block` (the contract set is the authoring schema),
  `list_posts` / `get_post`, `list_categories` / `list_tags`, `render_preview`.
  `get_post` returns both the block JSON and its shortcode serialisation.
  *Files:* `src/Ai/Mcp/Tools/*.php`
  *D:* 3.2, 4.2

- [x] **4.4 Write tools**
  `create_post`, `update_post`, `attach_category`, `attach_tag`. **Every one routes through
  `BlocksPayloadService::validatePayload()` + `HtmlSanitizationService`** — the same path
  `PostController` uses, including the `content_version` optimistic-concurrency check and the
  revision snapshot. Input shape per **Q1**.
  *Files:* `src/Ai/Mcp/Tools/*.php`
  *D:* 4.3, Q1
  *AC:* a post created over MCP is byte-identical to the same post created through the editor;
  an unregistered block name is dropped, not stored; a stale `content_version` gets a 409-equivalent
  error rather than clobbering.

- [x] **4.5 Media tools** — `list_media` (read-only unless **Q2** says otherwise).
  *D:* Q2

- [x] **4.6 Tests** — `tests/Mcp/` covering handshake, auth tiers, per-tool behaviour, and one
  end-to-end "build a page over MCP, render it, assert the HTML" case.

---

## Phase 5 — Heisenberg as an MCP client — **DONE 2026-08-08**

One design point the original plan under-specified: **a real tool round-trip needs message shapes
the value objects did not have.** Feeding tool output back as plain user text would have been
simpler and wrong — the model would not recognise it as the answer to its own call and would keep
asking. So `AiMessage` gained an assistant-with-`toolCalls` variant and a `tool` role, and each
adapter renders them natively: Anthropic uses `tool_use`/`tool_result` **content blocks** (results
merged into one user turn, as that API requires), while OpenAI-compatible endpoints use a `tool`
**role** keyed by `tool_call_id` with arguments as a JSON *string*.

The allow-list is checked in two places on purpose — once at discovery, so an un-allowed tool is
never even described to the model, and again in `HttpMcpClient::callTool()`, which is the last gate
before a third party's code runs on our behalf.


- [x] **5.1 `McpClientService`** — Streamable HTTP client: connect, `tools/list` (cached per server),
  `tools/call`. Auth token read from the server entry's `auth_env`.
  *Files:* `src/Services/McpClientService.php`, `src/Adapters/HttpMcpClient.php`
  *AC:* against a fake server, tools are discovered and called; a server whose `auth_env` is unset
  reports "not configured" rather than sending an empty header.

- [x] **5.2 Tool-definition bridge** — MCP tool schemas → whichever provider's tool shape is active.
  *D:* 5.1, 2.3

- [x] **5.3 `AiToolRunner`** — the server-side loop: request → `tool_use` → execute → `tool_result` →
  repeat. Hard iteration cap. Per-tool allowlist enforced *before* execution, and a confirm gate on
  any tool the allowlist marks as writing.
  *Files:* `src/Services/AiToolRunner.php`
  *D:* 5.2
  *AC:* a tool outside the allowlist is never executed even if the model calls it.

- [x] **5.4 MCP Servers tab** — server rows (name, URL, `auth_env`), add/remove, a **Test** button
  that probes server-side and returns discovered tool names, per-server enable toggle, per-tool
  allowlist. Built from `ui/field`, `ui/input`, `ui/toggle`, `ui/status-tag`, `ui/button`.
  *Files:* `live/ai/ai-settings-dialog.blade.php`, `src/Http/Controllers/AiMcpController.php`, `routes/ai.php`
  *D:* 5.1

- [x] **5.5 Expose tab** — the inbound side: endpoint URL, on/off, which env var holds the token,
  the tool list with per-tool enable, and the scope tier.
  *D:* 4.2

- [x] **5.6 Tests** — `tests/Ai/McpClientServiceTest` (`Http::fake()`), `AiToolRunnerTest`
  (allowlist enforcement + iteration cap).

---

## Phase 6 — Tools tab and suggestions — **DONE 2026-08-08**

6.1 and 6.2 landed early, with Phase 2.4's prompt-bar wiring: every suggestion row and tool card
carries `data-hb-ai-suggest` and runs through the same stream. **Generate Image was dropped**
rather than left inert — neither shipped adapter can produce an image, and a card that looks
actionable and silently does nothing is worse than no card. That is the same call TODO 0.1 made for
the Components tab's twelve cards, eight of which mapped to no contract. The lang key stays for
when an image provider lands.


- [x] **6.1 Suggestion rows** — the 4 extracted rows prefill real prompts scoped to the current
  selection (`hbEditor.getSelectedId()` / `getModel()`), falling back to whole-document context.

- [x] **6.2 Tool cards** — the 8 extracted cards map to real operations. Generate Title and
  SEO Optimize write into the Post/SEO panels; Improve Writing, Fix Grammar and Change Tone
  rewrite the selected block; Translate honours the active editor locale; Write Summary targets the
  excerpt. **Generate Image** has no image provider in this plan — either disable it with a tooltip
  or drop the card. *Flagging, not deciding.*

- [x] **6.3 Empty/error/loading states** on the response card, using `ui/status-tag`.

- [x] **6.4 Tests** — extend `tests/Editor/AiPanelWiringTest`; add a `tests/js/ai-panel-matrix.mjs`
  browser matrix in the style of the existing matrices.

---

## Security notes (apply across every phase)

- **Keys never traverse the API.** Read paths return `configured: true|false`, never key material.
  There is no endpoint that can echo a secret.
- **MCP tool output is untrusted input.** Nothing an MCP server returns reaches the block tree
  without `BlocksPayloadService::validatePayload()` and `HtmlSanitizationService` first. A tool
  *description* is model-facing text from a third party — it must never be able to drive a write.
- **Inbound writes carry the full lifecycle guard.** `status` / `published_at` / `scheduled_at`
  stay behind `config('heisenberg.lifecycle')`; an MCP token scoped to `authors` cannot self-publish
  any more than an author can.
- **The MCP endpoint is outside the `web` group**, so it must not rely on session state for
  authorisation — token → tier, checked per call.
- **Rate limits + iteration caps** on both the tool loop (5.3) and the MCP server (4.1), so a
  runaway agent can't hammer the provider or the DB.

---

## Config sketch (Phase 1.1)

```php
'ai' => [
    'routes'   => true,
    'provider' => env('HEISENBERG_AI_PROVIDER', 'null'),   // null | anthropic | openai

    'providers' => [
        'null'      => ['adapter' => \Heisenberg\Adapters\NullAiProvider::class],
        'anthropic' => [
            'adapter'  => \Heisenberg\Adapters\AnthropicProvider::class,
            'key_env'  => 'HEISENBERG_AI_ANTHROPIC_KEY',
            'base_url' => 'https://api.anthropic.com',
            'models'   => ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'],
            'default'  => 'claude-opus-5',
        ],
        'openai' => [
            'adapter'  => \Heisenberg\Adapters\OpenAiCompatibleProvider::class,
            'key_env'  => 'HEISENBERG_AI_OPENAI_KEY',
            'base_url' => env('HEISENBERG_AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'models'   => [],          // endpoint-specific; host supplies
            'default'  => null,
        ],
    ],

    // Sent on every request. `effort` is the cost/quality lever; sampling params and
    // `budget_tokens` are deliberately absent — current models reject them.
    'effort'     => env('HEISENBERG_AI_EFFORT', 'high'),   // low|medium|high|xhigh|max
    'max_tokens' => 16000,
    'timeout'    => 120,

    'settings_path' => env('HEISENBERG_AI_SETTINGS_PATH'), // null -> storage/app/heisenberg/ai.json

    'mcp' => [
        // Outbound: Heisenberg connects to other people's MCP servers. Server list lives in
        // ai.json; each entry names an env var for its token rather than storing one.
        'client' => [
            'enabled'        => false,
            'timeout'        => 30,
            'max_iterations' => 8,
        ],
        // Inbound: other AIs connect to Heisenberg. OFF by default, on purpose.
        'server' => [
            'enabled'    => false,
            'tokens_env' => 'HEISENBERG_MCP_TOKENS',   // "token:tier,token:tier"
            'path'       => 'heisenberg/mcp',
        ],
    ],
],

// alongside media/editor:
'middleware' => [
    'ai'  => ['web'],
    'mcp' => [],       // token-authed; deliberately outside the web/session CSRF stack
],
```

---

## Test map

| Path | Covers |
|---|---|
| `tests/Ai/AiSettingsRepositoryTest.php` | settings round-trip, credential rejection |
| `tests/Ai/AiControllerTest.php` | auth posture, settings API |
| `tests/Ai/AnthropicProviderTest.php` | request shape (no sampling params), refusal handling |
| `tests/Ai/OpenAiCompatibleProviderTest.php` | base-url swap, request shape |
| `tests/Ai/AiStreamTest.php` | normalised event stream from both wire formats |
| `tests/Ai/ShortcodeParityTest.php` | PHP ⇄ JS dialect parity over shared fixtures |
| `tests/Ai/McpClientServiceTest.php` | discovery, call, missing-auth behaviour |
| `tests/Ai/AiToolRunnerTest.php` | allowlist enforcement, iteration cap |
| `tests/Mcp/*` | handshake, auth tiers, per-tool behaviour, end-to-end page build |
| `tests/Editor/AiPanelWiringTest.php` | panel mounts extracted components, carries data attrs |
| `tests/js/ai-panel-matrix.mjs` | browser matrix for the panel |
| `tests/js/code-editor-matrix.mjs` | extended for the exposed `window.hbCodeView` parser |

# Registering Heisenberg as an MCP server

Heisenberg can run as an **inbound** MCP server: `POST /heisenberg/mcp`, one
endpoint, JSON-RPC 2.0 over the MCP **Streamable HTTP** transport. Any
MCP-speaking agent — Claude Code, Claude Desktop, the reference
`@modelcontextprotocol/inspector` — can connect to it as a first-class client
and author content through the same validation pipeline the editor UI uses.

This document is for the person turning it on and pointing an MCP client at
it. For the tool catalogue itself (what `create_post`, `list_blocks` etc. do),
see `docs/ai-mcp-plan.md`; for the tool implementations, `src/Mcp/`.

## 1. Enable the server

Off by default — this is a write API reachable with nothing but a bearer
token, so turning it on is a deliberate act. Three environment variables
(read by `config/heisenberg.php`'s `ai.mcp.server` block):

| Variable | Default | Purpose |
| --- | --- | --- |
| `HEISENBERG_MCP_SERVER` | `false` | Set to `true` to turn the server on. `false`/unset: the route 404s — the feature doesn't even advertise its existence. |
| `HEISENBERG_MCP_TOKENS` | *(none)* | Comma-separated `token:tier` pairs, e.g. `HEISENBERG_MCP_TOKENS="tok-abc123:authors,tok-readonly:read"`. See §3 for what each tier can do. |
| `HEISENBERG_MCP_PATH` | `heisenberg/mcp` | The route path (no leading slash), if you need it somewhere other than `/heisenberg/mcp`. |

Generate a real token yourself — anything long and random works, e.g.:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

The demo/dev environment (`testbench.yaml`) ships a fixed demo token,
`live-demo-token:authors`, for exercising the endpoint locally — **do not**
reuse that token anywhere reachable from outside your machine.

## 2. Register it with Claude Code

Claude Code's HTTP MCP client speaks the same Streamable HTTP transport this
server now implements. Two ways to register it, from the [Claude Code MCP
docs](https://code.claude.com/docs/en/mcp):

### `claude mcp add`

```bash
claude mcp add --transport http heisenberg http://127.0.0.1:8972/heisenberg/mcp \
  --header "Authorization: Bearer live-demo-token"
```

(swap the host/port and token for your real deployment; `-t` and `-H` are the
short forms of `--transport`/`--header`). Add `--scope project` to write this
into `.mcp.json` instead of your personal `~/.claude.json`, if the team should
share the registration (do **not** commit a real production token that way —
use the local/demo token, or `headersHelper`/`${ENV_VAR}` expansion for a
real one).

### `.mcp.json`

```json
{
  "mcpServers": {
    "heisenberg": {
      "type": "http",
      "url": "http://127.0.0.1:8972/heisenberg/mcp",
      "headers": {
        "Authorization": "Bearer live-demo-token"
      }
    }
  }
}
```

`"type"` is required — an entry with a `url` but no `type` is read as a
*stdio* server and fails outright. `"streamable-http"` is accepted as a
synonym for `"http"` if you're copying a snippet that used the spec's own name
for the transport.

**Known Claude Code client issue** ([anthropics/claude-code#29562](https://github.com/anthropics/claude-code/issues/29562),
open at the time of writing): custom `--header`/`.mcp.json` `headers` are
sometimes not sent during the very first `initialize` request, which reads to
this server as a missing token and fails the connection with 401 before
Claude Code ever gets to a tool call. If `claude mcp list` shows this server
as failed to connect but the handshake works fine by hand (§4), you have hit
this client bug, not a problem with this server — see §5 for how to tell the
difference, and the issue thread for the current workaround (a small stdio
proxy script that injects the header itself).

## 3. Tiers: what a token can do

Every token in `HEISENBERG_MCP_TOKENS` is bound to exactly one tier. Tiers are
cumulative — `authors` gets everything `read` gets, `admins` gets everything
`authors` gets:

| Tier | Can do |
| --- | --- |
| `read` | Discovery and inspection only: `list_blocks`, `describe_block`, `search_icons`, `search_web`, `list_email_variables`, `get_theme`, read-only post/SEO/taxonomy/revision listings, `render_preview` (renders without saving). No tool in this tier ever writes anything. |
| `authors` | Everything in `read`, plus the write surface: `create_post`, `update_post`, `set_post_status`-adjacent lifecycle tools, taxonomy/SEO writes, trash/restore, revision restore. Writes always land as **drafts** — publishing a post is not reachable over MCP at any tier; that stays a human, in-editor action. |
| `admins` | Everything in `authors`. No tool is currently gated specifically to `admins` — it exists as headroom for a future admin-only tool (e.g. something that touches site-wide settings rather than one post) without a config or code change to the tier model itself. |

A read-only token cannot reach a write tool by *hiding* it from `tools/list`
alone — that would be security by obscurity. Calling a write tool by name
with a `read` token fails the same way whether or not you can see it in the
catalogue (`McpServerTest::test_a_read_only_token_cannot_call_a_write_tool_by_name`).

## 4. What a successful handshake looks like

The transport is the MCP **Streamable HTTP** transport
(https://modelcontextprotocol.io/specification/2025-06-18/basic/transports),
targeting protocol revision **2025-06-18** — the same revision Claude Code
negotiates by default when it isn't specifically asking for the newer
2026-07-28 stateless-core revision (see §6). The server is fully **stateless**:
no `Mcp-Session-Id` is ever issued, and every POST is a self-contained
JSON-RPC exchange — legal per the spec's own wording ("A server ... **MAY**
assign a session ID at initialization time"; nothing requires one). There is
also no server-initiated SSE stream: every response is a single
`application/json` object, which the spec permits as the alternative to
`text/event-stream` for any request.

A full handshake with curl:

```bash
# 1. initialize
curl -s -i -X POST http://127.0.0.1:8972/heisenberg/mcp \
  -H "Authorization: Bearer live-demo-token" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"my-client","version":"1.0.0"}}}'
# => 200, {"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"heisenberg","version":"..."}}}

# 2. notifications/initialized (a notification: no "id", no response body)
curl -s -i -X POST http://127.0.0.1:8972/heisenberg/mcp \
  -H "Authorization: Bearer live-demo-token" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'
# => 202 Accepted, empty body

# 3. tools/list
curl -s -X POST http://127.0.0.1:8972/heisenberg/mcp \
  -H "Authorization: Bearer live-demo-token" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
# => 200, {"jsonrpc":"2.0","id":2,"result":{"tools":[...]}}

# 4. tools/call
curl -s -X POST http://127.0.0.1:8972/heisenberg/mcp \
  -H "Authorization: Bearer live-demo-token" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_blocks","arguments":{}}}'
# => 200, {"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"[...]"}],"isError":false}}
```

If you have Node available, the same handshake with the official SDK — this
is exactly what Claude Code's own client does internally:

```js
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const transport = new StreamableHTTPClientTransport(new URL('http://127.0.0.1:8972/heisenberg/mcp'), {
  requestInit: { headers: { Authorization: 'Bearer live-demo-token' } },
});
const client = new Client({ name: 'probe', version: '1.0.0' }, { capabilities: {} });
await client.connect(transport); // does initialize + notifications/initialized for you
console.log(await client.listTools());
```

or non-interactively with the reference inspector:

```bash
npx @modelcontextprotocol/inspector --cli http://127.0.0.1:8972/heisenberg/mcp \
  --transport http --header "Authorization: Bearer live-demo-token" \
  --method tools/list
```

## 5. Troubleshooting

| Symptom | Meaning | Fix |
| --- | --- | --- |
| **404** on every request | `HEISENBERG_MCP_SERVER` is not `true` (or you're hitting the wrong path). | Confirm the env var and `HEISENBERG_MCP_PATH`/`heisenberg.ai.mcp.server.path`. |
| **401**, `WWW-Authenticate: Bearer realm="heisenberg-mcp"` (no `error=`) | No `Authorization` header was received at all. | Check the header actually left the client — see the Claude Code header bug in §2. |
| **401**, `WWW-Authenticate: Bearer realm="heisenberg-mcp", error="invalid_token"` | A token was presented but doesn't match any entry in `HEISENBERG_MCP_TOKENS`. | Check for typos, stray whitespace, or the token having been rotated. |
| **403**, error code `-32000` | The request carried an `Origin` header naming a different host than the one it was sent to (cross-origin — the transport-security check the spec requires against DNS rebinding). A real MCP client (curl, the SDKs, Claude Code) never sends `Origin` at all, so you should only see this from a browser-based caller. | Don't call this endpoint from browser JS on another origin. |
| **400**, error code `-32700` | Malformed JSON body. | Fix the request body; this is a transport-level failure, not a tool error. |
| **400**, error code `-32600`, mentions `MCP-Protocol-Version` | The `MCP-Protocol-Version` header named a version this server doesn't recognise (anything other than `2025-06-18`, `2025-03-26`, `2024-11-05`, or absent). | Omit the header (the spec's own backwards-compatible default) or send a recognised value. |
| **405**, `Allow: POST` | You sent GET or DELETE to the MCP endpoint. Correct: this server is stateless (no session to `DELETE`) and opens no server-initiated SSE stream (no reason to `GET`). | Use POST for everything. |
| **200** with `"error":{"code":-32601,...}` | Valid JSON-RPC, but an unrecognised `method` — this is an *application*-level error, hence still HTTP 200 (unlike the transport failures above, which use real HTTP error statuses). | Check the method name against the three this server implements: `initialize`, `tools/list`, `tools/call` (plus `ping` and swallowed `notifications/*`). |
| `tools/call` result has `"isError": true` | The tool ran but refused the input (e.g. a `read` token trying `create_post`, or an unparseable shortcode) — a normal outcome for a model to see and self-correct from, not a transport problem. | Read `result.content[0].text` — it names the reason (tier, validation, stale version, etc). |

## 6. Why 2025-06-18, and not the newer stateless-core revision

The MCP specification has moved past 2025-06-18 (there is a 2025-11-25
revision, and, more recently, a 2026-07-28 revision that rewrites the base
protocol to a fully stateless, handshake-free core). This server targets
**2025-06-18** deliberately:

- 2025-11-25 is fully backward-compatible with 2025-06-18 for everything this
  server implements (it adds OAuth/elicitation/tasks features this
  tools-only server doesn't use) — the version-negotiation logic in
  `McpServerController` will happily echo `2025-11-25` back if a client asks
  for it, but there is nothing to gain from advertising it as a target.
- 2026-07-28 drops the `initialize`/`notifications/initialized` handshake
  entirely in favour of a self-describing per-request model. Adopting it
  would be a rewrite of this transport, not a conformance fix, and Claude
  Code's own negotiation for it is optional and falls back gracefully: per
  the Lifecycle spec's version-negotiation rule, a client asking for
  `2026-07-28` in its `initialize` request gets `2025-06-18` back (the latest
  version this server actually supports), and a client that still
  understands `2025-06-18` — every current release of Claude Code does —
  proceeds on that version instead of disconnecting. In other words: nothing
  about not implementing 2026-07-28 breaks the connection; it just means this
  server is reached over the well-established, thoroughly-interoperable
  revision rather than the newest one.

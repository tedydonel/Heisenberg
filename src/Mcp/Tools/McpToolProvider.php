<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Services\McpToolException;
use Heisenberg\Services\McpToolRegistry;

/**
 * One cohesive slice of the MCP tool catalogue (e.g. posts, taxonomy, SEO) — a domain's
 * tool descriptors AND the handlers that execute them, kept together so the schema a
 * caller sees can never drift from what the handler actually accepts.
 *
 * {@see McpToolRegistry} is the only consumer: it collects every
 * provider, merges their {@see self::definitions()} into `tools/list`'s catalogue (in a
 * fixed, registry-owned order — a provider's own iteration order is not the contract),
 * and dispatches `tools/call` to whichever provider {@see self::handles()} the requested
 * name. Tier/surface gating happens once, in the registry, BEFORE a provider is ever
 * asked to handle a call — a provider does not re-check either.
 *
 * The tier/surface vocabulary ({@see self::TIER_READ} etc.) lives here, on the interface
 * every provider implements, rather than on the registry — so a provider's own
 * `definitions()` never has to reach into the façade class just to spell out a tier.
 * {@see McpToolRegistry}'s own `TIER_*`/`SURFACE_*` constants are
 * simply aliases of these, kept for callers already written against them.
 */
interface McpToolProvider
{
    /** Read-only; available to any valid token. */
    public const TIER_READ = 'read';

    /** Create and edit content. */
    public const TIER_AUTHORS = 'authors';

    /** Publish, and anything that changes site-wide state. */
    public const TIER_ADMINS = 'admins';

    /**
     * The in-editor assistant — a human is driving, so this surface may reach further
     * than an unattended external caller (e.g. it may change a post's lifecycle status).
     */
    public const SURFACE_EDITOR = 'editor';

    /**
     * The inbound MCP server (`routes/mcp.php`) — any agent holding a valid bearer
     * token, with no human necessarily reviewing what it does. This is the default
     * surface: a tool with no `surface` entry is offered here too.
     */
    public const SURFACE_EXTERNAL = 'external';

    /**
     * This provider's tool descriptors, keyed by tool name, in MCP's `tools/list` shape
     * plus the `tier` (and optional `surface`) the registry gates on. Order within the
     * returned array does not matter — the registry re-orders by name into the
     * catalogue's fixed sequence.
     *
     * @return array<string, array{description: string, tier: string, inputSchema: array<string, mixed>, surface?: string}>
     */
    public function definitions(): array;

    /** Whether this provider implements `$tool`. The registry asks every provider exactly once per call. */
    public function handles(string $tool): bool;

    /**
     * Execute `$tool` (already tier/surface-checked by the registry) and return its raw
     * result — the registry wraps it into MCP's `tools/call` content shape and turns a
     * thrown {@see McpToolException} into an `isError` result.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $tool, array $arguments, string $surface): mixed;
}

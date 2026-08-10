<?php

declare(strict_types=1);

namespace Heisenberg\Services;

/**
 * Heisenberg's own MCP tools, offered to the **editor's** assistant in-process.
 *
 * The inbound MCP server already exposes `list_blocks`, `get_post`,
 * `create_post`, `render_preview` and the rest to external agents. Without this,
 * the assistant living inside the editor was the one client that could not reach
 * them — it had no way to look up a block contract or read a post, so it asked
 * the user to paste their document instead of going and reading it.
 *
 * This is a direct call into {@see McpToolRegistry}, not an HTTP round trip to
 * our own server: the loop is already running inside the application, and going
 * out over the network to come straight back would add a socket, a token and a
 * failure mode for nothing.
 *
 * The tier is `authors`, so the assistant can read and draft; whether it may
 * also change a post's lifecycle status is a SEPARATE axis —
 * {@see McpToolRegistry::SURFACE_EDITOR} — passed on every call below. A human
 * is driving this surface (unlike the inbound MCP server, which stays
 * draft-only), so it is offered `set_post_status`, gated per-call by the same
 * {@see \Heisenberg\Policies\PostPolicy::transitionAllowed()} check the
 * editor's own Publish button runs.
 */
class HeisenbergToolSource
{
    /** Namespaced so a connected MCP server offering `get_post` stays distinct. */
    public const PREFIX = 'heisenberg__';

    /**
     * The live-canvas write tool ({@see McpToolRegistry}'s `write_canvas`), by
     * its namespaced name. Special-cased in {@see AiToolRunner::stream()}: its
     * arguments ARE the payload — the panel applies them to the editor when the
     * tool_use frame arrives, after this side has validated the shortcode.
     */
    public const CANVAS_TOOL = self::PREFIX . 'write_canvas';

    /**
     * Every tool whose execution is split validate-server-side /
     * apply-client-side. {@see AiToolRunner::stream()} ships these frames with
     * their arguments and an `ok` verdict; the panel does the applying.
     */
    public const CLIENT_APPLIED_TOOLS = [
        self::CANVAS_TOOL,
        self::PREFIX . 'set_page_title',
    ];

    public static function appliesClientSide(string $name): bool
    {
        return in_array($name, self::CLIENT_APPLIED_TOOLS, true);
    }

    public function __construct(private McpToolRegistry $registry)
    {
    }

    /**
     * Tool definitions in the neutral shape {@see \Heisenberg\Ai\AiRequest::$tools} expects.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return array_map(static fn (array $tool): array => [
            'name' => self::PREFIX . $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['inputSchema'],
        ], $this->registry->listFor(McpToolRegistry::TIER_AUTHORS, McpToolRegistry::SURFACE_EDITOR));
    }

    public function owns(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    /**
     * @param  array<string, mixed> $arguments
     * @return array{content: string, isError: bool}
     */
    public function call(string $name, array $arguments): array
    {
        $result = $this->registry->call(
            substr($name, strlen(self::PREFIX)),
            $arguments,
            McpToolRegistry::TIER_AUTHORS,
            McpToolRegistry::SURFACE_EDITOR,
        );

        return [
            'content' => (string) ($result['content'][0]['text'] ?? ''),
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }
}

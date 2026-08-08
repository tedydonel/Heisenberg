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
 * The tier is `authors`, so the assistant can read and draft but cannot publish
 * — publishing stays a deliberate human act in the editor.
 */
class HeisenbergToolSource
{
    /** Namespaced so a connected MCP server offering `get_post` stays distinct. */
    public const PREFIX = 'heisenberg__';

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
        ], $this->registry->listFor(McpToolRegistry::TIER_AUTHORS));
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
        );

        return [
            'content' => (string) ($result['content'][0]['text'] ?? ''),
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }
}

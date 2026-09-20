<?php

declare(strict_types=1);

namespace Heisenberg\Mcp\Tools;

use Heisenberg\Mcp\Support\ToolSchema;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\WebSearchService;

/** `search_web` — the only tool that reaches outside this install. */
final class WebTools implements McpToolProvider
{
    public function __construct(private ?WebSearchService $webSearch = null)
    {
    }

    public function definitions(): array
    {
        return [
            'search_web' => [
                'description' => 'Search the internet for up-to-date information, news, recent facts, or image links. '
                    . 'Use `type: "text"` (default) to search web pages, articles and news. '
                    . 'Use `type: "images"` to search for image URLs, dimensions, and image attribution to reference in heisenberg/image blocks or featured images.',
                'tier' => self::TIER_READ,
                'inputSchema' => ToolSchema::schema([
                    'query' => ['type' => 'string', 'description' => 'Search query string, e.g. "latest tech news" or "mountain landscape photography".'],
                    'type' => ['type' => 'string', 'description' => 'Search type: "text" (default) or "images".'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (1-30, default 10).'],
                ], ['query']),
            ],
        ];
    }

    public function handles(string $tool): bool
    {
        return $tool === 'search_web';
    }

    public function call(string $tool, array $arguments, string $surface): mixed
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            throw new McpToolException('query is required for search_web.');
        }
        $type = (string) ($arguments['type'] ?? 'text');
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 10;
        $service = $this->webSearch ?? app(WebSearchService::class);

        return $service->search($query, $type, $limit);
    }
}

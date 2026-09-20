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

        $result = $service->search($query, $type, $limit);

        // Every backend failed — no search actually happened. This must NEVER look like a
        // clean zero-result answer: an isError result is the only signal strong enough to
        // stop a model from quietly falling back to (possibly stale) training data. See
        // WebSearchService::runWaterfall()'s docblock for the three states this collapses.
        if (($result['all_failed'] ?? false) === true) {
            $detail = (string) ($result['failure_detail'] ?? 'no backend responded');

            throw new McpToolException(
                "search_web FAILED — no web search was actually performed ({$detail}). "
                . 'Do not answer from memory or training data for this query, especially anything '
                . 'time-sensitive (news, prices, current rules/regulations, "latest"/"today"/a given '
                . 'year). Tell the user the live search failed and ask them to retry, rather than '
                . 'guessing from what you already know.'
            );
        }

        unset($result['all_failed'], $result['failure_detail']);

        return $result;
    }
}

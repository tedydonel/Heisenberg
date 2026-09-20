<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Illuminate\Support\Facades\Http;

/**
 * Provides internet search capabilities to Heisenberg's AI assistant and MCP tools.
 * Supports searching both web content/news (text) and images (with direct image URLs,
 * dimensions, and attribution) using reliable public search APIs and fallbacks.
 */
class WebSearchService
{
    private const DEFAULT_TIMEOUT = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (Heisenberg-AI-Search/1.0)';

    /**
     * Perform an internet search for web content, news, or image links.
     *
     * @param string $query The search query string
     * @param string $type 'text' for web pages/news or 'images' for image links
     * @param int $limit Maximum results to return (1-30, default 10)
     * @return array{query: string, type: string, results: list<array<string, mixed>>, total: int}
     */
    public function search(string $query, string $type = 'text', int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['query' => '', 'type' => $type, 'results' => [], 'total' => 0];
        }

        $limit = max(1, min(30, $limit));

        if ($type === 'images' || $type === 'image') {
            return $this->searchImages($query, $limit);
        }

        return $this->searchText($query, $limit);
    }

    /**
     * Search for web pages, news articles, and general text information.
     *
     * @return array{query: string, type: string, results: list<array<string, mixed>>, total: int}
     */
    public function searchText(string $query, int $limit): array
    {
        // 1. If a dedicated search API key is configured (Brave or Tavily), use it first.
        $braveKey = env('BRAVE_SEARCH_API_KEY');
        if (is_string($braveKey) && $braveKey !== '') {
            $braveResults = $this->searchBrave($query, $braveKey, $limit);
            if ($braveResults !== null && $braveResults !== []) {
                return ['query' => $query, 'type' => 'text', 'results' => array_slice($braveResults, 0, $limit), 'total' => count($braveResults)];
            }
        }

        $tavilyKey = env('TAVILY_API_KEY');
        if (is_string($tavilyKey) && $tavilyKey !== '') {
            $tavilyResults = $this->searchTavily($query, $tavilyKey, $limit);
            if ($tavilyResults !== null && $tavilyResults !== []) {
                return ['query' => $query, 'type' => 'text', 'results' => array_slice($tavilyResults, 0, $limit), 'total' => count($tavilyResults)];
            }
        }

        // 2. DuckDuckGo HTML search (no API key required)
        $ddgResults = $this->searchDuckDuckGoHtml($query, $limit);
        if ($ddgResults !== []) {
            return ['query' => $query, 'type' => 'text', 'results' => array_slice($ddgResults, 0, $limit), 'total' => count($ddgResults)];
        }

        // 3. Fallback: Wikipedia Search API (public, high availability, structured text)
        $wikiResults = $this->searchWikipedia($query, $limit);

        return [
            'query' => $query,
            'type' => 'text',
            'results' => array_slice($wikiResults, 0, $limit),
            'total' => count($wikiResults),
        ];
    }

    /**
     * Search for image links, dimensions, and attribution.
     *
     * @return array{query: string, type: string, results: list<array<string, mixed>>, total: int}
     */
    public function searchImages(string $query, int $limit): array
    {
        // 1. Openverse API (CC-licensed high-res images with full URLs & attribution)
        $openverseResults = $this->searchOpenverse($query, $limit);
        if ($openverseResults !== []) {
            return ['query' => $query, 'type' => 'images', 'results' => array_slice($openverseResults, 0, $limit), 'total' => count($openverseResults)];
        }

        // 2. Wikimedia Commons API (massive media library, reliable JSON API)
        $wikimediaResults = $this->searchWikimediaCommons($query, $limit);
        if ($wikimediaResults !== []) {
            return ['query' => $query, 'type' => 'images', 'results' => array_slice($wikimediaResults, 0, $limit), 'total' => count($wikimediaResults)];
        }

        // 3. DuckDuckGo Image Search Fallback
        $ddgImages = $this->searchDuckDuckGoImages($query, $limit);

        return [
            'query' => $query,
            'type' => 'images',
            'results' => array_slice($ddgImages, 0, $limit),
            'total' => count($ddgImages),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function searchDuckDuckGoHtml(string $query, int $limit): array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->asForm()
                ->post('https://html.duckduckgo.com/html/', [
                    'q' => $query,
                    'b' => '',
                    'kl' => 'us-en',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $html = $response->body();
            $results = [];

            // Match result blocks
            if (preg_match_all('/<div class="result__body">([\s\S]*?)<\/div>\s*<\/div>/i', $html, $matches)) {
                foreach ($matches[1] as $block) {
                    $title = '';
                    $url = '';
                    $snippet = '';

                    if (preg_match('/<a class="result__a" href="([^"]+)">([\s\S]*?)<\/a>/i', $block, $aMatch)) {
                        $rawUrl = $aMatch[1];
                        // DuckDuckGo redirects through /l/?uddg=...
                        if (str_contains($rawUrl, 'uddg=')) {
                            parse_str(parse_url($rawUrl, PHP_URL_QUERY) ?? '', $queryParams);
                            $url = (string) ($queryParams['uddg'] ?? $rawUrl);
                        } else {
                            $url = $rawUrl;
                        }
                        $title = trim(strip_tags(html_entity_decode($aMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    }

                    if (preg_match('/<a class="result__snippet[^>]*>([\s\S]*?)<\/a>/i', $block, $sMatch)) {
                        $snippet = trim(strip_tags(html_entity_decode($sMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                    }

                    if ($title !== '' && $url !== '' && str_starts_with($url, 'http')) {
                        $results[] = [
                            'title' => $title,
                            'url' => $url,
                            'snippet' => $snippet,
                        ];
                    }

                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function searchWikipedia(string $query, int $limit): array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query,
                    'srlimit' => $limit,
                    'utf8' => 1,
                    'format' => 'json',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $searchItems = (array) ($data['query']['search'] ?? []);
            $results = [];

            foreach ($searchItems as $item) {
                $title = (string) ($item['title'] ?? '');
                $snippet = trim(strip_tags(html_entity_decode((string) ($item['snippet'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($title !== '') {
                    $results[] = [
                        'title' => $title,
                        'url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)),
                        'snippet' => $snippet,
                    ];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function searchOpenverse(string $query, int $limit): array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->get('https://api.openverse.org/v1/images/', [
                    'q' => $query,
                    'page_size' => $limit,
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $items = (array) ($data['results'] ?? []);
            $results = [];

            foreach ($items as $item) {
                $url = (string) ($item['url'] ?? '');
                if ($url === '' || ! str_starts_with($url, 'http')) {
                    continue;
                }

                $results[] = [
                    'title' => (string) ($item['title'] ?? $query),
                    'url' => $url,
                    'thumbnail_url' => (string) ($item['thumbnail'] ?? $url),
                    'source' => (string) ($item['foreign_landing_url'] ?? $item['creator'] ?? 'Openverse'),
                    'width' => isset($item['width']) ? (int) $item['width'] : null,
                    'height' => isset($item['height']) ? (int) $item['height'] : null,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function searchWikimediaCommons(string $query, int $limit): array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'generator' => 'search',
                    'gsrsearch' => $query,
                    'gsrnamespace' => 6, // File namespace
                    'gsrlimit' => $limit,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|size|extmetadata',
                    'format' => 'json',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json();
            $pages = (array) ($data['query']['pages'] ?? []);
            $results = [];

            foreach ($pages as $page) {
                $imageInfo = (array) ($page['imageinfo'][0] ?? []);
                $url = (string) ($imageInfo['url'] ?? '');
                if ($url === '' || ! str_starts_with($url, 'http')) {
                    continue;
                }

                // Skip non-standard image extensions (e.g. svg, ogg, pdf) when looking for web images if not supported
                $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($ext, ['ogg', 'ogv', 'oga', 'pdf', 'djvu'], true)) {
                    continue;
                }

                $meta = (array) ($imageInfo['extmetadata'] ?? []);
                $desc = trim(strip_tags((string) ($meta['ImageDescription']['value'] ?? $meta['ObjectName']['value'] ?? $page['title'] ?? '')));

                $results[] = [
                    'title' => $desc !== '' ? $desc : (string) ($page['title'] ?? $query),
                    'url' => $url,
                    'thumbnail_url' => (string) ($imageInfo['thumburl'] ?? $url),
                    'source' => (string) ($imageInfo['descriptionurl'] ?? 'Wikimedia Commons'),
                    'width' => isset($imageInfo['width']) ? (int) $imageInfo['width'] : null,
                    'height' => isset($imageInfo['height']) ? (int) $imageInfo['height'] : null,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function searchDuckDuckGoImages(string $query, int $limit): array
    {
        try {
            // First fetch VQD token from DuckDuckGo
            $tokenRes = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->get('https://duckduckgo.com/', ['q' => $query]);

            if (! $tokenRes->successful()) {
                return [];
            }

            if (! preg_match('/vqd=([0-9-_]+)/i', $tokenRes->body(), $vqdMatch)) {
                return [];
            }

            $vqd = $vqdMatch[1];
            $imageRes = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withHeaders(['Referer' => 'https://duckduckgo.com/'])
                ->get('https://duckduckgo.com/i.js', [
                    'l' => 'us-en',
                    'o' => 'json',
                    'q' => $query,
                    'vqd' => $vqd,
                    'f' => ',,,',
                    'p' => '1',
                ]);

            if (! $imageRes->successful()) {
                return [];
            }

            $data = $imageRes->json();
            $items = (array) ($data['results'] ?? []);
            $results = [];

            foreach ($items as $item) {
                $image = (string) ($item['image'] ?? '');
                if ($image === '' || ! str_starts_with($image, 'http')) {
                    continue;
                }

                $results[] = [
                    'title' => (string) ($item['title'] ?? $query),
                    'url' => $image,
                    'thumbnail_url' => (string) ($item['thumbnail'] ?? $image),
                    'source' => (string) ($item['url'] ?? $item['source'] ?? ''),
                    'width' => isset($item['width']) ? (int) $item['width'] : null,
                    'height' => isset($item['height']) ? (int) $item['height'] : null,
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<array<string, mixed>>|null */
    private function searchBrave(string $query, string $apiKey, int $limit): ?array
    {
        try {
            $response = Http::withHeaders(['X-Subscription-Token' => $apiKey])
                ->timeout(self::DEFAULT_TIMEOUT)
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query,
                    'count' => $limit,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $webItems = (array) ($data['web']['results'] ?? []);
            $results = [];

            foreach ($webItems as $item) {
                $results[] = [
                    'title' => (string) ($item['title'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'snippet' => (string) ($item['description'] ?? ''),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return list<array<string, mixed>>|null */
    private function searchTavily(string $query, string $apiKey, int $limit): ?array
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->timeout(self::DEFAULT_TIMEOUT)
                ->post('https://api.tavily.com/search', [
                    'query' => $query,
                    'max_results' => $limit,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $items = (array) ($data['results'] ?? []);
            $results = [];

            foreach ($items as $item) {
                $results[] = [
                    'title' => (string) ($item['title'] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'snippet' => (string) ($item['content'] ?? ''),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

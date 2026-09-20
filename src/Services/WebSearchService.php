<?php

declare(strict_types=1);

namespace Heisenberg\Services;

use Heisenberg\Mcp\Tools\WebTools;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provides internet search capabilities to Heisenberg's AI assistant and MCP tools.
 * Supports searching both web content/news (text) and images (with direct image URLs,
 * dimensions, and attribution) using reliable public search APIs and fallbacks.
 *
 * EMPIRICAL BASIS (2026-09-20 diagnosis — see the project's incident report):
 * a live `search_web` call returned `{"results":[],"total":0}` with no error at all. Two
 * independent problems produced that, and both are fixed here:
 *
 *  1. `html.duckduckgo.com/html/` — the previous primary no-key backend — now serves an
 *     "anomaly" bot-check page instead of results for automated User-Agents; it returns
 *     HTTP 202 with zero `result__body` blocks, so the old parser silently found nothing.
 *     Confirmed by fetching it directly and grepping for `anomaly-modal__` (present) vs
 *     `result__body` (absent). Replaced below with `lite.duckduckgo.com/lite/`, DuckDuckGo's
 *     lightweight/legacy UI, which — as of this diagnosis — still returns full result sets
 *     (verified live: 10/10 results for several queries, real URLs, real per-result dates)
 *     for the same request shape (form POST, browser User-Agent, no key).
 *  2. Every backend method swallowed ALL failures (bad HTTP status, timeout, thrown
 *     exception) into a plain `return []` — IDENTICAL to "the backend ran and found
 *     nothing". A caller — and therefore the model reading the tool result — could not
 *     tell "genuinely zero matches" from "the search never actually happened". Each
 *     backend method below now returns `null` for a failure and `[]` only for a
 *     confirmed, successful, empty result set; {@see self::runWaterfall()} uses that
 *     distinction to report one of three states: ok, partial (some backends failed —
 *     `warnings`), or total failure (`all_failed` — see {@see WebTools}
 *     for how that becomes an `isError` MCP result instead of a silent empty list).
 *
 * The DuckDuckGo *image* fallback (`duckduckgo.com/i.js`, undocumented and reverse
 * engineered) is REMOVED rather than patched: its `vqd` anti-bot token, previously a bare
 * `vqd=<digits>`, is now emitted as a quoted JS string literal (`vqd="4-<digits>"`), which
 * is exactly the kind of unannounced shape change an unofficial endpoint is free to make at
 * any time. Openverse and Wikimedia Commons already cover image search reliably with real
 * license/attribution metadata, so this fallback added scraping risk without adding
 * coverage — see the class docblock note by {@see self::searchImages()}.
 */
class WebSearchService
{
    private const DEFAULT_TIMEOUT = 10;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 (Heisenberg-AI-Search/1.0)';

    /**
     * A real response from any backend here (a scraped HTML page or a JSON API) is at
     * most a few hundred KB. A response past this size is either a broken/looping page or
     * something hostile — either way it is treated as a backend FAILURE (not parsed, not
     * truncated-and-parsed, which for HTML in particular could run a regex over a huge
     * string) so a single misbehaving host can never turn into unbounded memory/CPU work.
     */
    private const MAX_RESPONSE_BYTES = 2_000_000;

    /**
     * Perform an internet search for web content, news, or image links.
     *
     * @param string $query The search query string
     * @param string $type 'text' for web pages/news or 'images' for image links
     * @param int $limit Maximum results to return (1-30, default 10)
     * @return array{query: string, type: string, as_of: string, results: list<array<string, mixed>>, total: int, warnings?: list<string>, all_failed?: bool, failure_detail?: string}
     */
    public function search(string $query, string $type = 'text', int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['query' => '', 'type' => $type, 'as_of' => $this->today(), 'results' => [], 'total' => 0];
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
     * Waterfall order: a configured key-based provider first (Brave, then Tavily — both
     * skipped entirely when no key is set), then DuckDuckGo (no key), then Wikipedia (no
     * key, always available, last resort). The first backend to return genuine
     * (non-empty) results wins; a backend that returns a confirmed-empty result set is
     * recorded as succeeded (not failed) and the waterfall moves on to the next one.
     *
     * @return array{query: string, type: string, as_of: string, results: list<array<string, mixed>>, total: int, warnings?: list<string>, all_failed?: bool, failure_detail?: string}
     */
    public function searchText(string $query, int $limit): array
    {
        $backends = [];

        $braveKey = env('BRAVE_SEARCH_API_KEY');
        if (is_string($braveKey) && $braveKey !== '') {
            $backends['Brave'] = fn (): ?array => $this->searchBrave($query, $braveKey, $limit);
        }

        $tavilyKey = env('TAVILY_API_KEY');
        if (is_string($tavilyKey) && $tavilyKey !== '') {
            $backends['Tavily'] = fn (): ?array => $this->searchTavily($query, $tavilyKey, $limit);
        }

        $backends['DuckDuckGo'] = fn (): ?array => $this->searchDuckDuckGoLite($query, $limit);
        $backends['Wikipedia'] = fn (): ?array => $this->searchWikipedia($query, $limit);

        return $this->runWaterfall($query, 'text', $limit, $backends);
    }

    /**
     * Search for image links, dimensions, and attribution.
     *
     * Openverse first (CC-licensed, full attribution), then Wikimedia Commons. Both are
     * stable, documented, key-free JSON APIs — see the class docblock for why the former
     * DuckDuckGo image fallback was removed rather than kept as a third tier.
     *
     * @return array{query: string, type: string, as_of: string, results: list<array<string, mixed>>, total: int, warnings?: list<string>, all_failed?: bool, failure_detail?: string}
     */
    public function searchImages(string $query, int $limit): array
    {
        return $this->runWaterfall($query, 'images', $limit, [
            'Openverse' => fn (): ?array => $this->searchOpenverse($query, $limit),
            'Wikimedia Commons' => fn (): ?array => $this->searchWikimediaCommons($query, $limit),
        ]);
    }

    /**
     * Shared waterfall runner for both search types. Tries each backend in order and
     * stops at the first that returns genuine (non-empty) results; a backend returning
     * `null` (its own transport/parse failure) is skipped and recorded, never mistaken
     * for "ran and found nothing".
     *
     * Three outcomes, matching the incident's three failure states:
     *  - every backend succeeded (possibly with zero matches) and none failed: a plain
     *    result, no `warnings`, no `all_failed` — the model can trust an empty list here.
     *  - at least one backend succeeded but at least one failed: the same result shape
     *    plus `warnings` naming the failed backend(s) — the model should trust the
     *    results it got but knows coverage may be incomplete.
     *  - EVERY backend failed: `all_failed => true` plus a `failure_detail` string. No
     *    search actually happened; {@see WebTools} turns this into
     *    an `isError` MCP result rather than letting it look like a clean zero-result
     *    answer.
     *
     * @param array<string, callable(): ?array<int, array<string, mixed>>> $backends
     * @return array{query: string, type: string, as_of: string, results: list<array<string, mixed>>, total: int, warnings?: list<string>, all_failed?: bool, failure_detail?: string}
     */
    private function runWaterfall(string $query, string $type, int $limit, array $backends): array
    {
        $results = [];
        $succeeded = [];
        $failed = [];

        foreach ($backends as $label => $run) {
            $backendResults = $run();

            if ($backendResults === null) {
                $failed[] = $label;

                continue;
            }

            $succeeded[] = $label;

            if ($backendResults !== []) {
                $results = $backendResults;

                break;
            }
        }

        $payload = [
            'query' => $query,
            'type' => $type,
            'as_of' => $this->today(),
            'results' => array_slice($results, 0, $limit),
            'total' => count($results),
        ];

        if ($succeeded === []) {
            $payload['all_failed'] = true;
            $payload['failure_detail'] = implode('; ', array_map(
                static fn (string $label): string => "{$label} was unreachable",
                $failed
            ));

            return $payload;
        }

        if ($failed !== []) {
            $payload['warnings'] = array_map(
                static fn (string $label): string => "{$label} search failed and was skipped; results may be incomplete.",
                $failed
            );
        }

        return $payload;
    }

    /** Today's date (server timezone), so a result's own date can be judged fresh or stale against it. */
    private function today(): string
    {
        return now()->toDateString();
    }

    /**
     * Whether outbound search requests verify the TLS certificate chain. Defaults to
     * TRUE (secure) always — this exists ONLY for a machine whose PHP install has no CA
     * bundle configured at all (curl error 60, "unable to get local issuer certificate",
     * for literally every HTTPS host, verified during this diagnosis on the demo
     * environment's own PHP-on-Windows install). Never disable this in a real deployment;
     * fix the machine's CA bundle instead. See config/heisenberg.php's `ai.web_search`
     * block for the operator-facing explanation.
     */
    private function verifySsl(): bool
    {
        return (bool) config('heisenberg.ai.web_search.verify_ssl', true);
    }

    /** A response too large to trust — see {@see self::MAX_RESPONSE_BYTES}. */
    private function isOversized(Response $response): bool
    {
        return strlen($response->body()) > self::MAX_RESPONSE_BYTES;
    }

    /**
     * Best-effort extraction of a YYYY-MM-DD date from whatever timestamp shape a
     * backend hands back (`2026-02-19T00:00:00.0000000`, `2026-09-09T03:31:00Z`,
     * `2023-07-20 12:55:15`, or a relative string like "3 days ago" that simply doesn't
     * match and becomes null) — one normalizer for every backend below rather than a
     * bespoke parser per API.
     */
    private function normalizeDate(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1 ? $m[1] : null;
    }

    /**
     * DuckDuckGo's lightweight "lite" HTML UI — see the class docblock for why this
     * replaced `html.duckduckgo.com/html/`.
     *
     * Each result anchor is located first (with its byte offset), and title/snippet/date
     * are then parsed ONLY from the slice of HTML between that anchor and the next one
     * (or a fixed window at the end of the document). Scoping every secondary regex to
     * its own result's slice — rather than one regex spanning the whole page — is what
     * keeps a result missing its snippet or timestamp (observed live: not every result
     * carries a `timestamp` span) from bleeding into the next result's fields.
     *
     * @return list<array{title: string, url: string, snippet: string, date: ?string}>|null
     */
    private function searchDuckDuckGoLite(string $query, int $limit): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
                ->asForm()
                ->post('https://lite.duckduckgo.com/lite/', [
                    'q' => $query,
                    'kl' => 'us-en',
                ]);

            if (! $response->successful() || $this->isOversized($response)) {
                return null;
            }

            $html = $response->body();

            if (preg_match_all(
                '/<a rel="nofollow" href="([^"]+)" class=\'result-link\'>([\s\S]*?)<\/a>/i',
                $html,
                $anchors,
                PREG_OFFSET_CAPTURE
            ) === false) {
                return null;
            }

            $count = count($anchors[0]);
            $results = [];

            for ($i = 0; $i < $count && count($results) < $limit; $i++) {
                $title = trim(strip_tags(html_entity_decode((string) $anchors[2][$i][0], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $url = $this->resolveDuckDuckGoUrl((string) $anchors[1][$i][0]);
                if ($title === '' || $url === '' || ! str_starts_with($url, 'http')) {
                    continue;
                }

                $start = (int) $anchors[0][$i][1];
                $end = $i + 1 < $count ? (int) $anchors[0][$i + 1][1] : min(strlen($html), $start + 4000);
                $block = substr($html, $start, max(0, $end - $start));

                $snippet = '';
                if (preg_match('/<td class=\'result-snippet\'>([\s\S]*?)<\/td>/i', $block, $sMatch)) {
                    $snippet = trim(strip_tags(html_entity_decode($sMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }

                $date = null;
                if (preg_match('/<span class=\'timestamp\'>([^<]*)<\/span>/i', $block, $tMatch)) {
                    $date = $this->normalizeDate(trim($tMatch[1]));
                }

                $results[] = [
                    'title' => $title,
                    'url' => $url,
                    'snippet' => $snippet,
                    'date' => $date,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Unwraps DuckDuckGo's `/l/?uddg=<encoded target>&rut=...` click-tracking redirect
     * into the real target URL; a result that is already a direct URL (lite.duckduckgo.com
     * serves both shapes depending on request context — observed live) passes through
     * unchanged.
     */
    private function resolveDuckDuckGoUrl(string $rawUrl): string
    {
        $rawUrl = html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($rawUrl, '//')) {
            $rawUrl = 'https:' . $rawUrl;
        }

        if (str_contains($rawUrl, 'uddg=')) {
            parse_str((string) parse_url($rawUrl, PHP_URL_QUERY), $params);
            $target = $params['uddg'] ?? null;
            if (is_string($target) && $target !== '') {
                return $target;
            }
        }

        return $rawUrl;
    }

    /**
     * @return list<array{title: string, url: string, snippet: string, date: ?string}>|null
     */
    private function searchWikipedia(string $query, int $limit): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
                ->get('https://en.wikipedia.org/w/api.php', [
                    'action' => 'query',
                    'list' => 'search',
                    'srsearch' => $query,
                    'srlimit' => $limit,
                    'srprop' => 'snippet|timestamp',
                    'utf8' => 1,
                    'format' => 'json',
                ]);

            if (! $response->successful() || $this->isOversized($response)) {
                return null;
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
                        // The page's last-edited date — not a "published" date, but the
                        // best freshness signal a wiki article exposes.
                        'date' => $this->normalizeDate($item['timestamp'] ?? null),
                    ];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{title: string, url: string, thumbnail_url: string, source: string, width: ?int, height: ?int, date: ?string}>|null
     */
    private function searchOpenverse(string $query, int $limit): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
                ->get('https://api.openverse.org/v1/images/', [
                    'q' => $query,
                    'page_size' => $limit,
                ]);

            if (! $response->successful() || $this->isOversized($response)) {
                return null;
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
                    'date' => $this->normalizeDate($item['indexed_on'] ?? null),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{title: string, url: string, thumbnail_url: string, source: string, width: ?int, height: ?int, date: ?string}>|null
     */
    private function searchWikimediaCommons(string $query, int $limit): ?array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
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

            if (! $response->successful() || $this->isOversized($response)) {
                return null;
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
                    'date' => $this->normalizeDate($meta['DateTime']['value'] ?? null),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{title: string, url: string, snippet: string, date: ?string}>|null
     */
    private function searchBrave(string $query, string $apiKey, int $limit): ?array
    {
        try {
            $response = Http::withHeaders(['X-Subscription-Token' => $apiKey])
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query,
                    'count' => $limit,
                ]);

            if (! $response->successful() || $this->isOversized($response)) {
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
                    // 'page_age' is an ISO timestamp when present; 'age' is a relative
                    // string ("3 days ago") that normalizeDate() will correctly ignore.
                    'date' => $this->normalizeDate($item['page_age'] ?? $item['age'] ?? null),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{title: string, url: string, snippet: string, date: ?string}>|null
     */
    private function searchTavily(string $query, string $apiKey, int $limit): ?array
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->timeout(self::DEFAULT_TIMEOUT)
                ->withOptions(['verify' => $this->verifySsl()])
                ->post('https://api.tavily.com/search', [
                    'query' => $query,
                    'max_results' => $limit,
                ]);

            if (! $response->successful() || $this->isOversized($response)) {
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
                    'date' => $this->normalizeDate($item['published_date'] ?? null),
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}

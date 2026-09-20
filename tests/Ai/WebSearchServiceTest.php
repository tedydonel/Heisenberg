<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Mcp\Tools\WebTools;
use Heisenberg\Services\McpToolException;
use Heisenberg\Services\WebSearchService;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Covers the 2026-09-20 incident: a live `search_web` call returned
 * `{"results":[],"total":0}` with `isError:false` — a total backend failure that was
 * indistinguishable from "genuinely nothing found", so the calling model quietly
 * answered from stale training data instead of telling the user the search failed.
 *
 * These tests run entirely against `Http::fake()` — no live network — and pin the three
 * states {@see WebSearchService} must now tell apart: full success (no warnings), partial
 * failure (`warnings` naming the failed backend(s)), and total failure (`all_failed`,
 * which {@see WebTools} turns into an `isError` MCP result rather than an empty list).
 */
class WebSearchServiceTest extends TestCase
{
    /** A two-result DuckDuckGo "lite" page: one result with every field, one missing its
     * timestamp span (observed live: not every result carries one) and reached through
     * the `/l/?uddg=` click-tracking redirect rather than a direct href. */
    private const DDG_LITE_HTML = <<<'HTML'
        <html><body><table border="0">
        <tr><td><a rel="nofollow" href="https://example.com/china-visa-2026" class='result-link'>China Work Visa Rules 2026</a></td></tr>
        <tr><td class='result-snippet'>Everything about the <b>2026</b> Chinese work visa changes.</td></tr>
        <tr><td><span class='link-text'>example.com/china-visa-2026</span><span class='timestamp'>2026-08-01T00:00:00.0000000</span></td></tr>
        <tr><td><a rel="nofollow" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fno-date&amp;rut=abc123" class='result-link'>No Date Result</a></td></tr>
        <tr><td class='result-snippet'>A result with no timestamp at all.</td></tr>
        </table></body></html>
        HTML;

    private const WIKIPEDIA_JSON = [
        'query' => [
            'search' => [
                [
                    'title' => 'Visa policy of mainland China',
                    'snippet' => 'Several categories of <span class="searchmatch">visas</span>.',
                    'timestamp' => '2026-09-09T03:31:00Z',
                ],
            ],
        ],
    ];

    private function service(): WebSearchService
    {
        return new WebSearchService();
    }

    public function test_successful_search_parses_titles_urls_snippets_and_dates(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response(self::DDG_LITE_HTML, 200),
        ]);

        $result = $this->service()->searchText('China work visa rules 2026', 10);

        $this->assertSame('China work visa rules 2026', $result['query']);
        $this->assertSame('text', $result['type']);
        $this->assertSame(now()->toDateString(), $result['as_of']);
        $this->assertSame(2, $result['total']);
        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertArrayNotHasKey('all_failed', $result);

        $first = $result['results'][0];
        $this->assertSame('China Work Visa Rules 2026', $first['title']);
        $this->assertSame('https://example.com/china-visa-2026', $first['url']);
        $this->assertStringContainsString('2026 Chinese work visa', $first['snippet']);
        $this->assertSame('2026-08-01', $first['date']);

        // The uddg-wrapped redirect must be unwrapped to the real target, and a result
        // missing its timestamp span must carry a null date rather than an error.
        $second = $result['results'][1];
        $this->assertSame('https://example.org/no-date', $second['url']);
        $this->assertNull($second['date']);
    }

    public function test_partial_backend_failure_still_returns_results_with_a_warning(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response('', 503),
            'https://en.wikipedia.org/*' => Http::response(self::WIKIPEDIA_JSON, 200),
        ]);

        $result = $this->service()->searchText('China work visa rules 2026', 10);

        $this->assertArrayNotHasKey('all_failed', $result);
        $this->assertSame(1, $result['total']);
        $this->assertSame('Visa policy of mainland China', $result['results'][0]['title']);
        $this->assertSame('2026-09-09', $result['results'][0]['date']);

        $this->assertArrayHasKey('warnings', $result);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('DuckDuckGo', $result['warnings'][0]);
    }

    public function test_total_backend_failure_is_reported_not_hidden_as_zero_results(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response('', 500),
            'https://en.wikipedia.org/*' => Http::response('', 500),
        ]);

        $result = $this->service()->searchText('China work visa rules 2026', 10);

        $this->assertTrue($result['all_failed']);
        $this->assertSame(0, $result['total']);
        $this->assertNotEmpty($result['failure_detail']);
    }

    public function test_web_tools_turns_total_failure_into_an_mcp_error_the_model_cannot_miss(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response('', 500),
            'https://en.wikipedia.org/*' => Http::response('', 500),
        ]);

        $tools = new WebTools($this->service());

        try {
            $tools->call('search_web', ['query' => 'China work visa rules 2026'], 'external');
            $this->fail('Expected McpToolException for a total backend failure.');
        } catch (McpToolException $e) {
            $this->assertStringContainsString('search_web FAILED', $e->getMessage());
            $this->assertStringContainsString('Do not answer from memory', $e->getMessage());
        }
    }

    public function test_web_tools_passes_through_a_healthy_result_without_internal_flags(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response(self::DDG_LITE_HTML, 200),
        ]);

        $tools = new WebTools($this->service());
        $result = $tools->call('search_web', ['query' => 'China work visa rules 2026'], 'external');

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('all_failed', $result);
        $this->assertArrayNotHasKey('failure_detail', $result);
        $this->assertSame(2, $result['total']);
    }

    public function test_genuine_zero_matches_from_a_working_backend_is_not_a_warning(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response('<html><body>no results here</body></html>', 200),
            'https://en.wikipedia.org/*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        $result = $this->service()->searchText('asdkjqwoieuqwoiuewqoiuzxcvnnnnnnzzzz', 10);

        $this->assertSame(0, $result['total']);
        $this->assertArrayNotHasKey('warnings', $result);
        $this->assertArrayNotHasKey('all_failed', $result);
    }

    /**
     * A malformed/looping/hostile host could serve gigabytes; nothing here may attempt to
     * regex-parse or json_decode a body that big. An oversized response must be treated as
     * a backend FAILURE (contributing to `warnings`/`all_failed`, never silently truncated
     * and parsed), and the whole call must still complete quickly.
     */
    public function test_an_oversized_response_is_treated_as_a_failure_not_parsed(): void
    {
        Http::fake([
            'https://lite.duckduckgo.com/*' => Http::response(str_repeat('a', 2_000_001), 200),
            'https://en.wikipedia.org/*' => Http::response(self::WIKIPEDIA_JSON, 200),
        ]);

        $start = microtime(true);
        $result = $this->service()->searchText('China work visa rules 2026', 10);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, 'an oversized fake response should fail fast, not hang parsing it');
        $this->assertArrayHasKey('warnings', $result);
        $this->assertStringContainsString('DuckDuckGo', $result['warnings'][0]);
        $this->assertSame(1, $result['total']);
    }

    public function test_image_search_parses_openverse_results_with_dates_and_falls_back_on_failure(): void
    {
        Http::fake([
            'https://api.openverse.org/*' => Http::response('', 500),
            'https://commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        '1' => [
                            'title' => 'File:Great Wall of China.jpg',
                            'imageinfo' => [[
                                'url' => 'https://upload.wikimedia.org/great-wall.jpg',
                                'thumburl' => 'https://upload.wikimedia.org/thumb/great-wall.jpg',
                                'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Great_Wall.jpg',
                                'width' => 4032,
                                'height' => 2688,
                                'extmetadata' => [
                                    'DateTime' => ['value' => '2023-07-20 12:55:15'],
                                    'ObjectName' => ['value' => 'Great Wall of China'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service()->searchImages('great wall of china', 5);

        $this->assertSame('images', $result['type']);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertStringContainsString('Openverse', $result['warnings'][0]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('2023-07-20', $result['results'][0]['date']);
        $this->assertSame('https://upload.wikimedia.org/great-wall.jpg', $result['results'][0]['url']);
    }

    public function test_empty_query_short_circuits_without_any_http_call(): void
    {
        Http::fake();

        $result = $this->service()->search('   ');

        $this->assertSame('', $result['query']);
        $this->assertSame(0, $result['total']);
        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Services\McpToolRegistry;
use Heisenberg\Services\WebSearchService;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class WebSearchToolTest extends TestCase
{
    use RefreshDatabase;

    private function callTool(string $name, array $args, string $surface = McpToolRegistry::SURFACE_EXTERNAL): array
    {
        $result = app(McpToolRegistry::class)->call($name, $args, McpToolRegistry::TIER_READ, $surface);

        return [
            'isError' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
        ];
    }

    private function toolData(string $name, array $args, string $surface = McpToolRegistry::SURFACE_EXTERNAL): array
    {
        $call = $this->callTool($name, $args, $surface);
        $this->assertFalse($call['isError'], $call['text']);

        return (array) json_decode($call['text'], true);
    }

    public function test_search_web_tool_is_advertised_on_both_surfaces_at_read_tier(): void
    {
        $registry = app(McpToolRegistry::class);
        $editorTools = $registry->listFor(McpToolRegistry::TIER_READ, McpToolRegistry::SURFACE_EDITOR);
        $externalTools = $registry->listFor(McpToolRegistry::TIER_READ, McpToolRegistry::SURFACE_EXTERNAL);

        $editorNames = array_column($editorTools, 'name');
        $externalNames = array_column($externalTools, 'name');

        $this->assertContains('search_web', $editorNames);
        $this->assertContains('search_web', $externalNames);
    }

    public function test_search_web_fails_when_query_is_missing_or_empty(): void
    {
        $call = $this->callTool('search_web', ['query' => '   ']);
        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('query is required', $call['text']);
    }

    public function test_search_web_text_mode_returns_formatted_results(): void
    {
        Http::fake([
            'https://html.duckduckgo.com/html/' => Http::response(
                '<div class="result__body"><a class="result__a" href="https://duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fnews">Breaking News Title</a><a class="result__snippet">This is the news snippet describing recent events.</a></div></div>',
                200
            ),
        ]);

        $data = $this->toolData('search_web', [
            'query' => 'latest breaking news',
            'type' => 'text',
            'limit' => 5,
        ]);

        $this->assertSame('latest breaking news', $data['query']);
        $this->assertSame('text', $data['type']);
        $this->assertCount(1, $data['results']);
        $this->assertSame('Breaking News Title', $data['results'][0]['title']);
        $this->assertSame('https://example.com/news', $data['results'][0]['url']);
        $this->assertSame('This is the news snippet describing recent events.', $data['results'][0]['snippet']);
    }

    public function test_search_web_images_mode_returns_image_links(): void
    {
        Http::fake([
            'https://api.openverse.org/v1/images/*' => Http::response([
                'results' => [
                    [
                        'title' => 'Mountain Sunrise',
                        'url' => 'https://images.example.com/mountain.jpg',
                        'thumbnail' => 'https://images.example.com/mountain-thumb.jpg',
                        'foreign_landing_url' => 'https://example.com/photo/1',
                        'width' => 1920,
                        'height' => 1080,
                    ],
                ],
            ], 200),
        ]);

        $data = $this->toolData('search_web', [
            'query' => 'mountain sunrise',
            'type' => 'images',
            'limit' => 5,
        ]);

        $this->assertSame('mountain sunrise', $data['query']);
        $this->assertSame('images', $data['type']);
        $this->assertCount(1, $data['results']);
        $this->assertSame('Mountain Sunrise', $data['results'][0]['title']);
        $this->assertSame('https://images.example.com/mountain.jpg', $data['results'][0]['url']);
        $this->assertSame('https://images.example.com/mountain-thumb.jpg', $data['results'][0]['thumbnail_url']);
        $this->assertSame(1920, $data['results'][0]['width']);
        $this->assertSame(1080, $data['results'][0]['height']);
    }

    public function test_search_web_falls_back_to_wikipedia_when_duckduckgo_fails(): void
    {
        Http::fake([
            'https://html.duckduckgo.com/html/' => Http::response('Blocked', 403),
            'https://en.wikipedia.org/w/api.php*' => Http::response([
                'query' => [
                    'search' => [
                        [
                            'title' => 'Quantum Computing',
                            'snippet' => 'Quantum computing is a rapidly-emerging technology...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $data = $this->toolData('search_web', [
            'query' => 'quantum computing',
            'type' => 'text',
        ]);

        $this->assertSame('quantum computing', $data['query']);
        $this->assertCount(1, $data['results']);
        $this->assertSame('Quantum Computing', $data['results'][0]['title']);
        $this->assertSame('https://en.wikipedia.org/wiki/Quantum_Computing', $data['results'][0]['url']);
    }
}

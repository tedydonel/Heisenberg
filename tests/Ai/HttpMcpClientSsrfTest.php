<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Adapters\HttpMcpClient;
use Heisenberg\Ai\McpServer;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * HttpMcpClient's own SSRF re-check (see OutboundUrlGuard) — the check that
 * actually matters, since it runs IMMEDIATELY BEFORE the real outbound
 * request, closing the gap a save-time-only check (AiSettingsRepositoryTest)
 * would leave open to DNS rebinding.
 */
class HttpMcpClientSsrfTest extends TestCase
{
    private function server(string $url): McpServer
    {
        return McpServer::fromArray([
            'id' => 'test-server',
            'label' => 'Test',
            'url' => $url,
            'enabled' => true,
            'allowed_tools' => ['search'],
        ]);
    }

    public function test_a_loopback_url_is_rejected_before_any_request_is_sent(): void
    {
        Http::fake();

        $result = (new HttpMcpClient())->callTool($this->server('http://127.0.0.1:9000/mcp'), 'search', []);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('private/internal address', $result['content']);
        Http::assertNothingSent();
    }

    public function test_listtools_also_rejects_a_blocked_url(): void
    {
        Http::fake();

        $this->expectException(\RuntimeException::class);

        (new HttpMcpClient())->listTools($this->server('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_a_private_network_url_is_allowed_once_opted_in(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []],
        ])]);

        $tools = (new HttpMcpClient())->listTools($this->server('http://10.0.0.5/mcp'));

        $this->assertSame([], $tools);
        Http::assertSent(fn ($request) => $request->url() === 'http://10.0.0.5/mcp');
    }

    public function test_a_normal_public_looking_url_still_works(): void
    {
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []],
        ])]);

        $tools = (new HttpMcpClient())->listTools($this->server('https://mcp.example.test/mcp'));

        $this->assertSame([], $tools);
        Http::assertSent(fn ($request) => $request->url() === 'https://mcp.example.test/mcp');
    }
}

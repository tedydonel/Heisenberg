<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Tests\Taxonomy\FakeActor;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * AiMcpController::test() — the MCP Servers tab's "probe this server" action.
 *
 * The SSRF-relevant behaviour: `resolve()` accepts an INLINE url/auth_env
 * pair for a server that has never been saved (and therefore never ran
 * through AiSettingsRepository::validateServers at all), so this controller
 * re-checks OutboundUrlGuard itself rather than trusting the settings layer —
 * see the controller's own docblock on that check.
 */
class AiMcpControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutCsrfProtection();
        $this->app['env'] = 'testing';
        $this->actingAs(new FakeActor(1, 'admin'));
    }

    private function probe(string $url): TestResponse
    {
        return $this->postJson('/editor/ai/mcp/test', ['id' => 'probe', 'url' => $url]);
    }

    public function test_a_loopback_url_is_rejected_before_any_request_is_sent(): void
    {
        Http::fake();

        $this->probe('http://127.0.0.1:8080/mcp')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_localhost_by_name_is_rejected_the_same_as_127_0_0_1(): void
    {
        Http::fake();

        $this->probe('http://localhost:11434/mcp')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_private_network_url_is_rejected_by_default_outside_local(): void
    {
        Http::fake();

        $this->probe('http://10.0.0.5/mcp')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_cloud_metadata_url_is_rejected_even_when_private_networks_are_allowed(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);
        Http::fake();

        $response = $this->probe('http://169.254.169.254/latest/meta-data/');
        $response->assertStatus(422);
        $this->assertStringContainsString('link-local', (string) $response->json('error'));

        Http::assertNothingSent();
    }

    public function test_a_private_network_url_is_allowed_once_opted_in(): void
    {
        config(['heisenberg.ai.outbound.allow_private_networks' => true]);
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []],
        ])]);

        $this->probe('http://127.0.0.1:8080/mcp')->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8080/mcp');
    }

    public function test_a_named_allowed_host_bypasses_the_check(): void
    {
        config([
            'heisenberg.ai.outbound.allow_private_networks' => false,
            'heisenberg.ai.outbound.allowed_hosts' => ['localhost'],
        ]);
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []],
        ])]);

        $this->probe('http://localhost:11434/mcp')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_a_normal_public_looking_url_is_unaffected(): void
    {
        Http::fake(['*' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [
                ['name' => 'search', 'description' => 'search issues'],
            ]],
        ])]);

        $this->probe('https://mcp.example.test/mcp')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('tools.0.name', 'search');
    }
}

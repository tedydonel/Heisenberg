<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

/**
 * Conformance tests for the MCP **Streamable HTTP** transport
 * (https://modelcontextprotocol.io/specification/2025-06-18/basic/transports),
 * as distinct from {@see McpServerTest}, which covers the tool surface itself
 * (auth tiers, the editor pipeline, the block/post tools). Nothing here
 * exercises a tool beyond `initialize`/`ping` — the point is the envelope
 * every JSON-RPC exchange travels in: version negotiation, the
 * `MCP-Protocol-Version` header, batching, transport-level error codes and
 * HTTP statuses, `Origin` validation, and the GET/DELETE 405s the spec
 * requires the single MCP endpoint to answer.
 */
class McpTransportConformanceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok-transport-0000000';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.ai.mcp.server.enabled', true);
        $app['config']->set('heisenberg.ai.mcp.server.tokens_env', 'HB_TEST_MCP_TRANSPORT_TOKENS');
    }

    protected function setUp(): void
    {
        $value = self::TOKEN . ':authors';
        putenv('HB_TEST_MCP_TRANSPORT_TOKENS=' . $value);
        $_ENV['HB_TEST_MCP_TRANSPORT_TOKENS'] = $value;
        $_SERVER['HB_TEST_MCP_TRANSPORT_TOKENS'] = $value;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('HB_TEST_MCP_TRANSPORT_TOKENS');
        unset($_ENV['HB_TEST_MCP_TRANSPORT_TOKENS'], $_SERVER['HB_TEST_MCP_TRANSPORT_TOKENS']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $headers */
    private function rawPost(string $rawBody, array $headers = [], bool $withToken = true): TestResponse
    {
        $headers = array_merge($headers, $withToken ? ['Authorization' => 'Bearer ' . self::TOKEN] : []);

        return $this->call('POST', '/heisenberg/mcp', [], [], [], $this->transformHeadersToServerVars($headers), $rawBody);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], array $headers = [], int|string|null $id = 1): TestResponse
    {
        $body = array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: null]);

        return $this->rawPost((string) json_encode($body), $headers);
    }

    // --- Version negotiation (Lifecycle spec) ---------------------------------------------

    public function test_initialize_echoes_back_the_clients_requested_supported_version(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => '2025-03-26'])->assertOk()->json('result');

        $this->assertSame('2025-03-26', $result['protocolVersion']);
    }

    public function test_initialize_falls_back_to_the_servers_latest_for_an_unrecognised_version(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])->assertOk()->json('result');

        $this->assertSame('2025-06-18', $result['protocolVersion']);
    }

    public function test_initialize_reports_a_tools_only_capability_set(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => '2025-06-18'])->assertOk()->json('result');

        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertSame('heisenberg', $result['serverInfo']['name']);
    }

    // --- notifications/initialized --------------------------------------------------------

    public function test_notifications_initialized_gets_202_with_no_body(): void
    {
        $response = $this->rawPost((string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $response->assertStatus(202);
        $this->assertSame('', $response->getContent());
    }

    // --- JSON-RPC transport-level errors ---------------------------------------------------

    public function test_malformed_json_is_a_parse_error_not_a_500(): void
    {
        $response = $this->rawPost('{ this is not json');

        $response->assertStatus(400);
        $this->assertSame(-32700, $response->json('error.code'));
        $this->assertNull($response->json('id'));
    }

    public function test_an_unknown_method_is_a_200_with_a_json_rpc_error(): void
    {
        $this->rpc('resources/list')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_a_non_object_non_array_body_is_an_invalid_request(): void
    {
        $response = $this->rawPost('"just a string"');

        $response->assertStatus(400);
        $this->assertSame(-32600, $response->json('error.code'));
    }

    // --- MCP-Protocol-Version header --------------------------------------------------------

    public function test_an_unsupported_protocol_version_header_is_rejected(): void
    {
        $response = $this->rpc('tools/list', [], ['MCP-Protocol-Version' => 'not-a-real-version']);

        $response->assertStatus(400);
        $this->assertSame(-32600, $response->json('error.code'));
    }

    public function test_a_supported_protocol_version_header_is_accepted(): void
    {
        $this->rpc('tools/list', [], ['MCP-Protocol-Version' => '2025-06-18'])->assertOk();
    }

    public function test_a_missing_protocol_version_header_is_tolerated(): void
    {
        // The Transports spec's own backwards-compatibility fallback: no header
        // at all is not an error, unlike an unrecognised one.
        $this->rpc('tools/list')->assertOk();
    }

    public function test_the_initialize_call_itself_is_exempt_from_the_header_check(): void
    {
        // A client cannot know a negotiated version before initialize answers.
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18'], ['MCP-Protocol-Version' => 'garbage'])
            ->assertOk();
    }

    // --- JSON-RPC batching (accepted defensively; see McpServerController docblock) --------

    public function test_a_batch_of_requests_gets_a_batch_of_responses_in_order(): void
    {
        $batch = [
            ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'resources/list'],
        ];

        $response = $this->rawPost((string) json_encode($batch))->assertOk();
        $results = $response->json();

        $this->assertCount(2, $results);
        $this->assertSame('a', $results[0]['id']);
        $this->assertArrayHasKey('result', $results[0]);
        $this->assertSame('b', $results[1]['id']);
        $this->assertSame(-32601, $results[1]['error']['code']);
    }

    public function test_a_batch_containing_only_notifications_gets_202(): void
    {
        $batch = [
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled'],
        ];

        $this->rawPost((string) json_encode($batch))->assertStatus(202);
    }

    public function test_an_empty_batch_is_an_invalid_request(): void
    {
        $response = $this->rawPost('[]');

        $response->assertStatus(400);
        $this->assertSame(-32600, $response->json('error.code'));
    }

    // --- Auth: WWW-Authenticate on 401 -------------------------------------------------------

    public function test_no_token_gets_a_bearer_challenge_with_no_error_param(): void
    {
        $response = $this->rawPost((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), [], false);

        $response->assertStatus(401);
        $this->assertSame('Bearer realm="heisenberg-mcp"', $response->headers->get('WWW-Authenticate'));
    }

    public function test_a_wrong_token_gets_an_invalid_token_challenge(): void
    {
        $response = $this->rawPost(
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']),
            ['Authorization' => 'Bearer nope-not-real'],
            false,
        );

        $response->assertStatus(401);
        $this->assertSame('Bearer realm="heisenberg-mcp", error="invalid_token"', $response->headers->get('WWW-Authenticate'));
    }

    // --- Origin validation (transport security MUST) -----------------------------------------

    public function test_a_same_origin_request_is_allowed(): void
    {
        $this->rpc('tools/list', [], ['Origin' => 'http://localhost'])->assertOk();
    }

    public function test_a_request_with_no_origin_header_is_allowed(): void
    {
        // Every real MCP client (curl, the SDKs, Claude Code) never sends Origin —
        // only browsers do.
        $this->rpc('tools/list')->assertOk();
    }

    public function test_a_cross_origin_request_is_forbidden(): void
    {
        $response = $this->rpc('tools/list', [], ['Origin' => 'http://evil.example']);

        $response->assertStatus(403);
        $this->assertSame(-32000, $response->json('error.code'));
    }

    // --- GET / DELETE on the MCP endpoint ------------------------------------------------------

    public function test_get_is_method_not_allowed_with_an_allow_header(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN])->get('/heisenberg/mcp');

        $response->assertStatus(405);
        $this->assertSame('POST', $response->headers->get('Allow'));
    }

    public function test_delete_is_method_not_allowed(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . self::TOKEN])->delete('/heisenberg/mcp');

        $response->assertStatus(405);
    }

    public function test_get_still_404s_when_the_server_is_disabled(): void
    {
        config(['heisenberg.ai.mcp.server.enabled' => false]);

        $this->get('/heisenberg/mcp')->assertStatus(404);
    }
}

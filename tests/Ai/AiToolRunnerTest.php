<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Ai;

use Heisenberg\Adapters\HttpMcpClient;
use Heisenberg\Ai\AiMessage;
use Heisenberg\Ai\AiRequest;
use Heisenberg\Ai\AiResponse;
use Heisenberg\Ai\AiStreamEvent;
use Heisenberg\Ai\McpServer;
use Heisenberg\Contracts\AiProvider;
use Heisenberg\Contracts\McpClient;
use Heisenberg\Services\AiSettingsRepository;
use Heisenberg\Services\AiToolRunner;
use Heisenberg\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The outbound tool loop.
 *
 * The assertions that matter are the two safety properties: the allow-list is
 * consulted BEFORE a third party's tool runs, and the loop cannot spin forever
 * on a model that keeps calling tools (an unbounded loop here is an unbounded
 * bill and an unbounded wait).
 */
class AiToolRunnerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-ai-runner-' . uniqid('', true) . '.json';
        config(['heisenberg.ai.settings_path' => $this->path]);

        putenv('HB_TEST_MCP_TOKEN=secret-token');
        $_ENV['HB_TEST_MCP_TOKEN'] = 'secret-token';
        $_SERVER['HB_TEST_MCP_TOKEN'] = 'secret-token';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('HB_TEST_MCP_TOKEN');
        unset($_ENV['HB_TEST_MCP_TOKEN'], $_SERVER['HB_TEST_MCP_TOKEN']);
        parent::tearDown();
    }

    private function server(array $allowed = ['search']): McpServer
    {
        return McpServer::fromArray([
            'id' => 'linear',
            'label' => 'Linear',
            'url' => 'https://mcp.example.test/mcp',
            'auth_env' => 'HB_TEST_MCP_TOKEN',
            'enabled' => true,
            'allowed_tools' => $allowed,
        ]);
    }

    private function runner(McpClient $mcp): AiToolRunner
    {
        return new AiToolRunner($mcp, new AiSettingsRepository($this->path));
    }

    private function request(): AiRequest
    {
        return new AiRequest(messages: [AiMessage::user('find my issues')]);
    }

    public function test_discovery_offers_only_allow_listed_tools(): void
    {
        $mcp = new class () implements McpClient {
            public function listTools(McpServer $server): array
            {
                return [
                    ['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object']],
                    ['name' => 'delete_everything', 'description' => 'Danger', 'input_schema' => ['type' => 'object']],
                ];
            }

            public function callTool(McpServer $server, string $tool, array $arguments): array
            {
                return ['content' => '', 'isError' => false];
            }
        };

        $discovered = $this->runner($mcp)->discover([$this->server(['search'])]);

        $this->assertSame(['linear__search'], array_column($discovered['tools'], 'name'));
    }

    public function test_a_disabled_server_contributes_nothing(): void
    {
        $mcp = $this->recordingClient();
        $server = McpServer::fromArray([
            'id' => 'linear', 'url' => 'https://mcp.example.test/mcp',
            'enabled' => false, 'allowed_tools' => ['search'],
        ]);

        $this->assertSame([], $this->runner($mcp)->discover([$server])['tools']);
    }

    /** One broken integration must not take the assistant down with it. */
    public function test_an_unreachable_server_is_skipped_not_fatal(): void
    {
        $mcp = new class () implements McpClient {
            public function listTools(McpServer $server): array
            {
                throw new \RuntimeException('boom');
            }

            public function callTool(McpServer $server, string $tool, array $arguments): array
            {
                return ['content' => '', 'isError' => false];
            }
        };

        $discovered = $this->runner($mcp)->discover([$this->server()]);

        $this->assertSame([], $discovered['tools']);
        $this->assertNotSame([], $discovered['errors']);
    }

    public function test_a_tool_call_round_trips_and_the_model_gets_the_result(): void
    {
        $mcp = $this->recordingClient('42 open issues');
        $provider = $this->providerReturning([
            new AiResponse('', [['id' => 'call_1', 'name' => 'linear__search', 'arguments' => ['q' => 'mine']]]),
            new AiResponse('You have 42 open issues.'),
        ]);

        $response = $this->runner($mcp)->run($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]);

        $this->assertSame('You have 42 open issues.', $response->text);
        $this->assertSame([['linear', 'search', ['q' => 'mine']]], $mcp->calls);

        // The model's own turn is replayed, then the result, matched by id.
        $second = $provider->seen[1];
        $this->assertTrue($second[1]->hasToolCalls());
        $this->assertTrue($second[2]->isToolResult());
        $this->assertSame('call_1', $second[2]->toolUseId);
        $this->assertSame('42 open issues', $second[2]->content);
    }

    /**
     * The runner strips un-allowed tools at discovery, but a model can still
     * name one — that must not reach the server.
     */
    public function test_a_tool_the_model_invented_is_refused_without_calling_any_server(): void
    {
        $mcp = $this->recordingClient();
        $provider = $this->providerReturning([
            new AiResponse('', [['id' => 'call_1', 'name' => 'linear__delete_everything', 'arguments' => []]]),
            new AiResponse('Understood.'),
        ]);

        $this->runner($mcp)->run($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]);

        $this->assertSame([], $mcp->calls);
        $result = $provider->seen[1][2];
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('No such tool', $result->content);
    }

    public function test_the_loop_is_bounded(): void
    {
        config(['heisenberg.ai.mcp.client.max_iterations' => 3]);
        $mcp = $this->recordingClient();

        // A model that never stops asking.
        $provider = new class () implements AiProvider {
            public int $calls = 0;

            public function id(): string
            {
                return 'loop';
            }

            public function label(): string
            {
                return 'Loop';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function discoverModels(): array
            {
                return ['x'];
            }

            public function supportsTools(): bool
            {
                return true;
            }

            public function complete(AiRequest $request): AiResponse
            {
                $this->calls++;

                return new AiResponse('', [['id' => 'c' . $this->calls, 'name' => 'linear__search', 'arguments' => []]]);
            }

            public function stream(AiRequest $request): iterable
            {
                yield AiStreamEvent::done();
            }
        };

        $response = $this->runner($mcp)->run($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]);

        $this->assertTrue($response->isError());
        $this->assertSame(3, $provider->calls);
    }

    public function test_with_no_tools_it_is_a_plain_completion(): void
    {
        $provider = $this->providerReturning([new AiResponse('Plain answer.')]);

        $response = $this->runner($this->recordingClient())->run($provider, $this->request(), [], []);

        $this->assertSame('Plain answer.', $response->text);
    }

    /** The client re-checks the allow-list itself — the last gate before egress. */
    public function test_the_http_client_refuses_a_tool_outside_the_allow_list(): void
    {
        Http::fake();

        $result = (new HttpMcpClient())->callTool($this->server(['search']), 'delete_everything', []);

        $this->assertTrue($result['isError']);
        Http::assertNothingSent();
    }

    public function test_the_http_client_sends_the_token_from_the_named_env_var(): void
    {
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]])]);

        (new HttpMcpClient())->listTools($this->server());

        Http::assertSent(function ($request) {
            $this->assertSame('Bearer secret-token', $request->header('Authorization')[0]);

            return true;
        });
    }

    public function test_the_http_client_sends_no_auth_header_when_the_env_var_is_unset(): void
    {
        putenv('HB_TEST_MCP_TOKEN');
        unset($_ENV['HB_TEST_MCP_TOKEN'], $_SERVER['HB_TEST_MCP_TOKEN']);
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]])]);

        (new HttpMcpClient())->listTools($this->server());

        // Not an empty Bearer: some servers read that as anonymous and others
        // reject it confusingly.
        Http::assertSent(fn ($request) => $request->header('Authorization') === []);
    }

    /**
     * The streamed loop. Streaming used to bypass the runner entirely, so the
     * assistant lost every tool the moment it streamed — and a turn that opened
     * with a tool call produced no text at all, which reads as a reply that was
     * cut off before it started.
     */
    public function test_a_streamed_tool_call_is_executed_and_the_answer_continues(): void
    {
        $mcp = $this->recordingClient('42 open issues');
        $provider = $this->streamingProviderReturning([
            [AiStreamEvent::toolUse(['id' => 'call_1', 'name' => 'linear__search', 'arguments' => ['q' => 'mine']]), AiStreamEvent::done()],
            [AiStreamEvent::textDelta('You have '), AiStreamEvent::textDelta('42.'), AiStreamEvent::done(['stopReason' => 'end_turn'])],
        ]);

        $events = iterator_to_array($this->runner($mcp)->stream($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]));

        $this->assertSame([['linear', 'search', ['q' => 'mine']]], $mcp->calls);

        // Text is forwarded delta by delta; the tool call itself is machinery and
        // never reaches the panel.
        $types = array_map(static fn (AiStreamEvent $e): string => $e->type, $events);
        $this->assertSame([AiStreamEvent::TEXT_DELTA, AiStreamEvent::TEXT_DELTA, AiStreamEvent::DONE], $types);
        $this->assertSame('You have 42.', $events[0]->text . $events[1]->text);
    }

    /**
     * Exactly one terminator, on the last pass. A `done` after the tool-calling
     * pass would tell the panel the answer had finished while the real work was
     * still to come.
     */
    public function test_only_the_final_pass_terminates_the_stream(): void
    {
        $provider = $this->streamingProviderReturning([
            [AiStreamEvent::toolUse(['id' => 'c1', 'name' => 'linear__search', 'arguments' => []]), AiStreamEvent::done()],
            [AiStreamEvent::textDelta('ok'), AiStreamEvent::done()],
        ]);

        $events = iterator_to_array($this->runner($this->recordingClient())->stream($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]));

        $done = array_filter($events, static fn (AiStreamEvent $e): bool => $e->type === AiStreamEvent::DONE);
        $this->assertCount(1, $done);
    }

    public function test_the_streamed_loop_is_bounded_too(): void
    {
        config(['heisenberg.ai.mcp.client.max_iterations' => 2]);
        $provider = $this->streamingProviderReturning([
            [AiStreamEvent::toolUse(['id' => 'c1', 'name' => 'linear__search', 'arguments' => []])],
            [AiStreamEvent::toolUse(['id' => 'c2', 'name' => 'linear__search', 'arguments' => []])],
            [AiStreamEvent::toolUse(['id' => 'c3', 'name' => 'linear__search', 'arguments' => []])],
        ]);

        $events = iterator_to_array($this->runner($this->recordingClient())->stream($provider, $this->request(), ['linear__search' => $this->server()], [
            ['name' => 'linear__search', 'description' => '', 'input_schema' => ['type' => 'object']],
        ]));

        $last = end($events);
        $this->assertSame(AiStreamEvent::ERROR, $last->type);
    }

    /** @param list<list<AiStreamEvent>> $passes */
    private function streamingProviderReturning(array $passes): AiProvider
    {
        return new class ($passes) implements AiProvider {
            private int $index = 0;

            public function __construct(private array $passes)
            {
            }

            public function id(): string
            {
                return 'fake-stream';
            }

            public function label(): string
            {
                return 'Fake stream';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function discoverModels(): array
            {
                return ['x'];
            }

            public function supportsTools(): bool
            {
                return true;
            }

            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse('unused');
            }

            public function stream(AiRequest $request): iterable
            {
                yield from $this->passes[$this->index++] ?? [AiStreamEvent::done()];
            }
        };
    }

    private function recordingClient(string $reply = 'ok'): McpClient
    {
        return new class ($reply) implements McpClient {
            /** @var list<array{0: string, 1: string, 2: array}> */
            public array $calls = [];

            public function __construct(private string $reply)
            {
            }

            public function listTools(McpServer $server): array
            {
                return [['name' => 'search', 'description' => 'Search', 'input_schema' => ['type' => 'object']]];
            }

            public function callTool(McpServer $server, string $tool, array $arguments): array
            {
                $this->calls[] = [$server->id, $tool, $arguments];

                return ['content' => $this->reply, 'isError' => false];
            }
        };
    }

    /** @param list<AiResponse> $responses */
    private function providerReturning(array $responses): AiProvider
    {
        return new class ($responses) implements AiProvider {
            /** @var list<list<AiMessage>> */
            public array $seen = [];

            private int $index = 0;

            public function __construct(private array $responses)
            {
            }

            public function id(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function discoverModels(): array
            {
                return ['x'];
            }

            public function supportsTools(): bool
            {
                return true;
            }

            public function complete(AiRequest $request): AiResponse
            {
                $this->seen[] = $request->messages;

                return $this->responses[$this->index++] ?? new AiResponse('done');
            }

            public function stream(AiRequest $request): iterable
            {
                yield AiStreamEvent::done();
            }
        };
    }
}

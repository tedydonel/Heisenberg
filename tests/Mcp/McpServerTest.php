<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Mcp;

use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Heisenberg as an MCP server — the surface other AIs use to build pages here.
 *
 * Three properties carry most of the weight:
 *
 * 1. **It ships off.** A write API reachable with a bearer token and no session
 *    must not exist until someone turns it on.
 * 2. **A read-only token cannot write** — and not merely by having the write
 *    tools hidden from `tools/list`, which would be security by obscurity.
 * 3. **Writes go through the editor's own pipeline**, so a post authored over
 *    MCP is indistinguishable from one saved in the browser.
 */
class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private const READ_TOKEN = 'tok-read-0000000000';
    private const WRITE_TOKEN = 'tok-write-000000000';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.ai.mcp.server.enabled', true);
        $app['config']->set('heisenberg.ai.mcp.server.tokens_env', 'HB_TEST_MCP_TOKENS');
    }

    protected function setUp(): void
    {
        $value = self::READ_TOKEN . ':read,' . self::WRITE_TOKEN . ':authors';
        putenv('HB_TEST_MCP_TOKENS=' . $value);
        $_ENV['HB_TEST_MCP_TOKENS'] = $value;
        $_SERVER['HB_TEST_MCP_TOKENS'] = $value;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('HB_TEST_MCP_TOKENS');
        unset($_ENV['HB_TEST_MCP_TOKENS'], $_SERVER['HB_TEST_MCP_TOKENS']);
        parent::tearDown();
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], ?string $token = self::WRITE_TOKEN, int $id = 1)
    {
        return $this->postJson(
            '/heisenberg/mcp',
            array_filter(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ?: null]),
            $token === null ? [] : ['Authorization' => 'Bearer ' . $token],
        );
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $name, array $arguments = [], ?string $token = self::WRITE_TOKEN): array
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $token)->assertOk();
        $result = $response->json('result');

        return [
            'isError' => (bool) ($result['isError'] ?? false),
            'text' => (string) ($result['content'][0]['text'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function toolData(string $name, array $arguments = [], ?string $token = self::WRITE_TOKEN): array
    {
        $call = $this->callTool($name, $arguments, $token);
        $this->assertFalse($call['isError'], $call['text']);

        return (array) json_decode($call['text'], true);
    }

    public function test_the_handshake_reports_a_tools_only_server(): void
    {
        $result = $this->rpc('initialize')->assertOk()->json('result');

        $this->assertArrayHasKey('tools', $result['capabilities']);
        $this->assertSame('heisenberg', $result['serverInfo']['name']);
    }

    public function test_an_unknown_method_returns_a_json_rpc_error_not_a_500(): void
    {
        $this->rpc('resources/list')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $this->rpc('tools/list', [], null)->assertStatus(401);
    }

    public function test_a_wrong_token_is_unauthorized(): void
    {
        $this->rpc('tools/list', [], 'tok-not-a-real-token')->assertStatus(401);
    }

    public function test_tools_list_hides_write_tools_from_a_read_only_token(): void
    {
        $readNames = array_column($this->rpc('tools/list', [], self::READ_TOKEN)->json('result.tools'), 'name');
        $writeNames = array_column($this->rpc('tools/list', [], self::WRITE_TOKEN)->json('result.tools'), 'name');

        $this->assertContains('list_blocks', $readNames);
        $this->assertNotContains('create_post', $readNames);
        $this->assertContains('create_post', $writeNames);
    }

    /** Hiding a tool is not a control; calling it by name must also fail. */
    public function test_a_read_only_token_cannot_call_a_write_tool_by_name(): void
    {
        $call = $this->callTool('create_post', ['title' => 'Nope', 'code' => '[p]x[/p]'], self::READ_TOKEN);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('tier', $call['text']);
        $this->assertSame(0, Post::query()->count());
    }

    public function test_the_block_contracts_are_discoverable(): void
    {
        $blocks = $this->toolData('list_blocks');
        $slugs = array_column($blocks, 'slug');

        $this->assertContains('heading', $slugs);
        $this->assertContains('paragraph', $slugs);

        $heading = $this->toolData('describe_block', ['name' => 'heading']);
        $this->assertArrayHasKey('level', $heading['attributes']);
        $this->assertArrayHasKey('typography', $heading['supports']);
    }

    public function test_describe_block_rejects_an_unknown_name_with_a_useful_message(): void
    {
        $call = $this->callTool('describe_block', ['name' => 'nonesuch']);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('list_blocks', $call['text']);
    }

    public function test_a_post_can_be_authored_from_shortcode(): void
    {
        $created = $this->toolData('create_post', [
            'title' => 'From an agent',
            'code' => "[h2]\n  Hello\n[/h2]\n\n[p]\n  Body copy.\n[/p]\n",
        ]);

        $this->assertSame(2, $created['blocks']);
        $this->assertSame('draft', $created['status']);

        $post = Post::query()->findOrFail($created['id']);
        $this->assertSame('From an agent', $post->title_en);
        $this->assertSame(2, $post->blocks()->count());
        $this->assertSame('heisenberg/heading', $post->blocks()->orderBy('order')->first()->content['name']);
    }

    public function test_get_post_returns_content_as_shortcode_that_round_trips(): void
    {
        $code = "[h2]\n  Hello\n[/h2]\n";
        $created = $this->toolData('create_post', ['title' => 'Round trip', 'code' => $code]);

        $fetched = $this->toolData('get_post', ['id' => $created['id']]);

        $this->assertSame($code, $fetched['code']);
        $this->assertIsArray($fetched['blocks']);
    }

    public function test_unparseable_shortcode_is_refused_with_line_numbers(): void
    {
        $call = $this->callTool('create_post', ['title' => 'Bad', 'code' => "[p]ok[/p]\n[nope]x[/nope]"]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('line 2', $call['text']);
        $this->assertSame(0, Post::query()->count());
    }

    /**
     * The point of routing every write through BlocksPayloadService: an agent
     * cannot invent a block type that has no contract.
     */
    public function test_an_unregistered_block_name_cannot_be_written_through_raw_json(): void
    {
        $call = $this->callTool('create_post', [
            'title' => 'Sneaky',
            'blocks' => [['name' => 'evil/backdoor', 'attributes' => [], 'supports' => [], 'innerBlocks' => []]],
        ]);

        $this->assertTrue($call['isError']);
        $this->assertSame(0, Post::query()->count());
    }

    public function test_content_cannot_be_supplied_twice_over(): void
    {
        $call = $this->callTool('create_post', ['title' => 'Both', 'code' => '[p]x[/p]', 'blocks' => []]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('not both', $call['text']);
    }

    public function test_update_replaces_content_and_bumps_the_version(): void
    {
        $created = $this->toolData('create_post', ['title' => 'v1', 'code' => "[p]\n  one\n[/p]\n"]);

        $updated = $this->toolData('update_post', [
            'id' => $created['id'],
            'code' => "[p]\n  two\n[/p]\n\n[p]\n  three\n[/p]\n",
        ]);

        $this->assertSame(2, $updated['blocks']);
        $this->assertGreaterThan($created['content_version'], $updated['content_version']);
    }

    public function test_a_stale_content_version_is_refused_rather_than_clobbering(): void
    {
        $created = $this->toolData('create_post', ['title' => 'Concurrent', 'code' => "[p]\n  one\n[/p]\n"]);
        $this->toolData('update_post', ['id' => $created['id'], 'code' => "[p]\n  two\n[/p]\n"]);

        // Quoting the version from BEFORE the other edit.
        $call = $this->callTool('update_post', [
            'id' => $created['id'],
            'code' => "[p]\n  three\n[/p]\n",
            'content_version' => $created['content_version'],
        ]);

        $this->assertTrue($call['isError']);
        $this->assertStringContainsString('stale', $call['text']);
    }

    /** Publishing is a lifecycle transition, not a content edit. */
    public function test_posts_cannot_be_published_over_mcp(): void
    {
        $call = $this->callTool('create_post', [
            'title' => 'Straight to live',
            'code' => '[p]x[/p]',
            'status' => 'published',
        ]);

        $this->assertTrue($call['isError']);
        $this->assertSame(0, Post::query()->count());
    }

    public function test_a_preview_renders_without_saving_anything(): void
    {
        $preview = $this->toolData('render_preview', ['code' => "[h2]\n  Preview me\n[/h2]\n"]);

        $this->assertStringContainsString('Preview me', $preview['html']);
        $this->assertSame(0, Post::query()->count());
    }

    public function test_the_whole_surface_is_absent_when_the_server_is_disabled(): void
    {
        config(['heisenberg.ai.mcp.server.enabled' => false]);

        $this->rpc('tools/list')->assertStatus(404);
    }
}

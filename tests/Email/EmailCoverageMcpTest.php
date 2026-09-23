<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Email;

use Heisenberg\Mcp\Support\PostAccess;
use Heisenberg\Models\Post;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * docs/email-system.md §4: an agent authoring an email through `create_post`/`update_post`
 * gets no other signal that a placed block has no `email` template (Icon/Embed — renders as
 * nothing) or degrades once sent (a gradient flattened, an alignment/conditional style
 * skipped). {@see PostAccess::writePost()} now attaches a `warnings`
 * array to the tool RESULT — never an error, mirroring how `WebTools`/`WebSearchService`
 * report a partial web-search failure (see WebSearchService::runWaterfall()).
 */
class EmailCoverageMcpTest extends TestCase
{
    use RefreshDatabase;

    private const WRITE_TOKEN = 'tok-write-000000000';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.ai.mcp.server.enabled', true);
        $app['config']->set('heisenberg.ai.mcp.server.tokens_env', 'HB_TEST_MCP_TOKENS');
    }

    protected function setUp(): void
    {
        $value = self::WRITE_TOKEN . ':authors';
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
    private function rpc(string $method, array $params = [])
    {
        return $this->postJson(
            '/heisenberg/mcp',
            array_filter(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ?: null]),
            ['Authorization' => 'Bearer ' . self::WRITE_TOKEN],
        );
    }

    /** @param array<string, mixed> $arguments */
    private function toolData(string $name, array $arguments = []): array
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk();
        $result = $response->json('result');

        $this->assertFalse((bool) ($result['isError'] ?? false), (string) ($result['content'][0]['text'] ?? ''));

        return (array) json_decode((string) ($result['content'][0]['text'] ?? ''), true);
    }

    public function test_create_post_type_email_warns_about_a_block_with_no_email_template(): void
    {
        $created = $this->toolData('create_post', [
            'title' => 'A newsletter',
            'type' => 'email',
            'blocks' => [
                ['name' => 'heisenberg/embed'],
                ['name' => 'heisenberg/paragraph'],
            ],
        ]);

        $this->assertArrayHasKey('warnings', $created);
        $this->assertCount(1, $created['warnings']);
        $this->assertStringContainsString('heisenberg/embed', $created['warnings'][0]);
        $this->assertStringContainsString('will not appear', $created['warnings'][0]);
    }

    public function test_create_post_type_email_has_no_warnings_for_an_email_safe_document(): void
    {
        $created = $this->toolData('create_post', [
            'title' => 'A clean newsletter',
            'type' => 'email',
            'blocks' => [
                ['name' => 'heisenberg/heading'],
                ['name' => 'heisenberg/paragraph'],
            ],
        ]);

        $this->assertArrayNotHasKey('warnings', $created);
    }

    public function test_create_post_type_post_never_carries_email_warnings_even_with_an_email_only_defect(): void
    {
        // The `dropped`/`degraded` distinction is EMAIL-specific — a plain post's palette
        // includes every block, so an icon block there is not a defect to warn about.
        $created = $this->toolData('create_post', [
            'title' => 'A blog post',
            'blocks' => [
                ['name' => 'heisenberg/embed'],
            ],
        ]);

        $this->assertArrayNotHasKey('warnings', $created);
        $this->assertSame('post', Post::query()->findOrFail($created['id'])->type);
    }

    public function test_update_post_reports_a_warning_for_the_documents_current_content_even_when_only_the_title_changes(): void
    {
        $created = $this->toolData('create_post', [
            'title' => 'v1',
            'type' => 'email',
            'blocks' => [
                ['name' => 'heisenberg/embed'],
            ],
        ]);
        $this->assertArrayHasKey('warnings', $created);

        // A follow-up write that only changes the title (no `code`/`blocks`) still reports on
        // whatever content the document already carries.
        $updated = $this->toolData('update_post', [
            'id' => $created['id'],
            'title' => 'v2',
        ]);

        $this->assertArrayHasKey('warnings', $updated);
        $this->assertStringContainsString('heisenberg/embed', $updated['warnings'][0]);
    }

    public function test_a_degraded_but_present_block_is_warned_about_separately_from_a_dropped_one(): void
    {
        $created = $this->toolData('create_post', [
            'title' => 'Mixed',
            'type' => 'email',
            'blocks' => [
                ['name' => 'heisenberg/embed'],
                [
                    'name' => 'heisenberg/group',
                    'supports' => ['align' => 'wide'],
                ],
            ],
        ]);

        $this->assertArrayHasKey('warnings', $created);
        $this->assertCount(2, $created['warnings']);

        $joined = implode(' | ', $created['warnings']);
        $this->assertStringContainsString('heisenberg/embed', $joined);
        $this->assertStringContainsString('will not appear at all', $joined);
        $this->assertStringContainsString('heisenberg/group', $joined);
        $this->assertStringContainsString('render differently', $joined);
    }
}

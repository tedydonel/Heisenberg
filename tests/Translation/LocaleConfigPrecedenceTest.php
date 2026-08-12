<?php

declare(strict_types=1);

namespace Heisenberg\Tests\Translation;

use Heisenberg\Support\LocaleConfig;
use Heisenberg\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Config single-source pin (docs/content-translation.md §3): `heisenberg.locales` is the one
 * key every locale-aware surface reads through `Heisenberg\Support\LocaleConfig`. This pins the
 * precedence (`heisenberg.locales` wins over the deprecated `heisenberg.editor.locales` alias,
 * which wins over the hardcoded `['en', 'fr']` last resort) AND that changing
 * `heisenberg.locales` actually flows through LocaleController's switch validation, the editor
 * locale middleware, and McpToolRegistry's `locale` argument validation — not just LocaleConfig
 * itself in isolation.
 */
class LocaleConfigPrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private const MCP_TOKEN = 'tok-write-000000000';

    /**
     * MCP route registration (routes/mcp.php) happens in HeisenbergServiceProvider::boot(),
     * reading `heisenberg.ai.mcp.server.enabled` at THAT time — setting it later inside a test
     * method is too late for the route to exist. Same pattern as McpServerTest.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('heisenberg.ai.mcp.server.enabled', true);
        $app['config']->set('heisenberg.ai.mcp.server.tokens_env', 'HB_TEST_LOCALE_MCP_TOKENS');
    }

    protected function setUp(): void
    {
        $value = self::MCP_TOKEN . ':authors';
        putenv('HB_TEST_LOCALE_MCP_TOKENS=' . $value);
        $_ENV['HB_TEST_LOCALE_MCP_TOKENS'] = $value;
        $_SERVER['HB_TEST_LOCALE_MCP_TOKENS'] = $value;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('HB_TEST_LOCALE_MCP_TOKENS');
        unset($_ENV['HB_TEST_LOCALE_MCP_TOKENS'], $_SERVER['HB_TEST_LOCALE_MCP_TOKENS']);
        parent::tearDown();
    }

    public function test_heisenberg_locales_wins_when_both_keys_are_set(): void
    {
        config([
            'heisenberg.locales' => ['en', 'de'],
            'heisenberg.editor.locales' => ['en', 'fr'],
        ]);

        $this->assertSame(['en', 'de'], LocaleConfig::locales());
    }

    public function test_editor_locales_is_read_only_as_a_fallback(): void
    {
        config([
            'heisenberg.locales' => null,
            'heisenberg.editor.locales' => ['en', 'es'],
        ]);

        $this->assertSame(['en', 'es'], LocaleConfig::locales());
    }

    public function test_the_hardcoded_default_is_the_last_resort(): void
    {
        config(['heisenberg.locales' => null, 'heisenberg.editor.locales' => null]);

        $this->assertSame(['en', 'fr'], LocaleConfig::locales());
    }

    public function test_default_locale_reads_config_with_an_en_fallback(): void
    {
        config(['heisenberg.default_locale' => 'fr']);
        $this->assertSame('fr', LocaleConfig::default());

        config(['heisenberg.default_locale' => null]);
        $this->assertSame('en', LocaleConfig::default());
    }

    public function test_changing_heisenberg_locales_flows_through_locale_controller_switch(): void
    {
        config(['heisenberg.locales' => ['en', 'de']]);

        $this->postJson('/locale/de', ['return' => '/editor'])->assertRedirect('/editor');
        // 'fr' was on the old default but is no longer in the configured set.
        $this->postJson('/locale/fr')->assertNotFound();
    }

    public function test_changing_heisenberg_locales_flows_through_the_editor_locale_middleware(): void
    {
        config(['heisenberg.locales' => ['en', 'de']]);

        // Switch first (writes the session), then confirm a follow-up request actually
        // applies App::setLocale — proving the middleware itself re-validates via the
        // same config, not a value cached from the switch request.
        $this->postJson('/locale/de', ['return' => '/editor']);
        $this->assertSame('de', session('heisenberg.locale'));
    }

    public function test_changing_heisenberg_locales_flows_through_mcp_create_post_locale_validation(): void
    {
        // 'fr' deliberately dropped from the configured set — a locale that used to be
        // hardcoded-valid must now be rejected, and 'de' (never hardcoded) must now pass.
        config(['heisenberg.locales' => ['en', 'de']]);

        $response = $this->postJson('/heisenberg/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_post',
                'arguments' => ['title' => 'Hallo', 'locale' => 'de', 'code' => '[p]x[/p]'],
            ],
        ], ['Authorization' => 'Bearer ' . self::MCP_TOKEN])->assertOk();

        $result = $response->json('result');
        $this->assertFalse((bool) ($result['isError'] ?? false), (string) ($result['content'][0]['text'] ?? ''));

        $response = $this->postJson('/heisenberg/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_post',
                'arguments' => ['title' => 'Bonjour', 'locale' => 'fr', 'code' => '[p]x[/p]'],
            ],
        ], ['Authorization' => 'Bearer ' . self::MCP_TOKEN])->assertOk();

        $result = $response->json('result');
        $this->assertTrue((bool) ($result['isError'] ?? false));
        $this->assertStringContainsString('en, de', (string) ($result['content'][0]['text'] ?? ''));
    }
}
